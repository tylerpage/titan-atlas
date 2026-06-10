<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Support\SyncDateChunkWalker;
use App\Ingestion\Connectors\GoogleAnalytics\GoogleAnalyticsAdminClient;
use App\Ingestion\Connectors\GoogleAnalytics\GoogleAnalyticsDataClient;
use App\Models\Connection;
use Carbon\Carbon;

class GoogleAnalyticsConnector extends AbstractConnector implements FanOutSyncConnector
{
    use WalksSyncDateChunks;

    /** @var list<string> */
    protected array $streams = ['traffic_daily', 'traffic_channel', 'events_daily', 'landing_page'];

    public function __construct(
        protected GoogleAnalyticsDataClient $client,
        protected GoogleAnalyticsAdminClient $admin,
    ) {}

    public function type(): string
    {
        return ConnectorType::GoogleAnalytics->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['property_id'])) {
            return ValidationResult::fail('Google Analytics requires Google sign-in and a property selection.');
        }

        if (empty($credentials['refresh_token'])) {
            return ValidationResult::fail('Google Analytics requires Google sign-in and a property selection.');
        }

        $propertyId = $this->admin->normalizePropertyId((string) $credentials['property_id']);
        $refreshToken = (string) $credentials['refresh_token'];
        $debug = [
            'property_id' => $propertyId,
            'token_length' => strlen($refreshToken),
        ];

        try {
            $properties = $this->admin->listProperties($refreshToken);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not connect to Google Analytics.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $match = collect($properties)->firstWhere('propertyId', $propertyId);

        if ($match === null) {
            return ValidationResult::fail(
                'Selected GA4 property was not found for this Google account.',
                array_merge($debug, [
                    'available_properties' => collect($properties)->pluck('propertyId')->take(10)->all(),
                    'hint' => 'Reconnect with Google and choose a property listed for your account.',
                ]),
            );
        }

        try {
            $this->client->testConnection($refreshToken, $propertyId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not query the selected GA4 property.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        return ValidationResult::ok(
            'Connected to GA4 property '.$match['displayName'].' ('.$propertyId.')',
            array_merge($debug, ['display_name' => $match['displayName']]),
        );
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $propertyId = $this->admin->normalizePropertyId((string) ($credentials['property_id'] ?? ''));
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($propertyId === '' || $refreshToken === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.google_analytics.chunk_days', 7));
        $rowLimit = max(1, (int) config('titan.google_analytics.row_limit', 5000));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $dimensions = $this->dimensionsForStream($state['stream']);
        $metrics = $this->metricsForStream($state['stream']);

        $rows = $this->client->runReport(
            $refreshToken,
            $propertyId,
            $chunkStart->toDateString(),
            $chunkEnd->toDateString(),
            $dimensions,
            $metrics,
            $rowLimit,
            $state['start_row'],
        );

        $records = $this->normalizeRows($state['stream'], $rows);

        if (count($rows) === $rowLimit) {
            $nextState = $state;
            $nextState['start_row'] += $rowLimit;

            return $this->result($records, $this->encodeCursor($nextState), true, $progressFrom, $progressThrough);
        }

        $nextState = SyncDateChunkWalker::nextDateChunkState($state, $chunkDays);

        if ($nextState !== null) {
            return $this->result($records, $this->encodeCursor($nextState), true, $progressFrom, $progressThrough);
        }

        if (! empty($state['fan_out'])) {
            return $this->result($records, null, false, $progressFrom, $progressThrough);
        }

        $nextStream = $this->nextStream($state['stream']);

        if ($nextStream !== null) {
            return $this->result(
                $records,
                $this->encodeCursor($this->nextStreamCursorState($connection, $nextStream, $state)),
                true,
                $progressFrom,
                $progressThrough,
            );
        }

        return $this->result($records, null, false, $progressFrom, $progressThrough);
    }

    public function syncStreams(): array
    {
        return $this->streams;
    }

    public function initialSyncCursor(Connection $connection, string $stream, bool $fanOut = false): string
    {
        [$start, $end] = $this->resolveDateRange($connection);
        $walk = SyncDateChunkWalker::walkForConnection($connection);

        return $this->encodeCursor(SyncDateChunkWalker::initialState($start, $end, $walk, [
            'stream' => $stream,
            'start_row' => 0,
            'fan_out' => $fanOut,
        ]));
    }

    /**
     * @return array{stream: string, start_date: string, end_date: string, start_row: int}
     */
    protected function decodeCursor(?string $cursor, Connection $connection): array
    {
        if ($cursor !== null && str_starts_with($cursor, 'ga4:')) {
            $decoded = json_decode(substr($cursor, 4), true);

            if (is_array($decoded) && isset($decoded['stream'])) {
                return SyncDateChunkWalker::mergeDecodedState([
                    'stream' => (string) $decoded['stream'],
                    'start_date' => (string) ($decoded['start_date'] ?? ''),
                    'end_date' => (string) ($decoded['end_date'] ?? ''),
                    'range_start' => (string) ($decoded['range_start'] ?? ''),
                    'range_end' => (string) ($decoded['range_end'] ?? ''),
                    'chunk_end' => (string) ($decoded['chunk_end'] ?? ''),
                    'walk' => (string) ($decoded['walk'] ?? ''),
                    'start_row' => max(0, (int) ($decoded['start_row'] ?? 0)),
                    'fan_out' => (bool) ($decoded['fan_out'] ?? false),
                ], $connection);
            }
        }

        [$start, $end] = $this->resolveDateRange($connection);
        $walk = SyncDateChunkWalker::walkForConnection($connection);

        return SyncDateChunkWalker::initialState($start, $end, $walk, [
            'stream' => $this->streams[0],
            'start_row' => 0,
        ]);
    }

    /**
     * @param  array{stream: string, start_date: string, end_date: string, start_row: int}  $state
     */
    protected function encodeCursor(array $state): string
    {
        return 'ga4:'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $lagDays = max(0, (int) config('titan.google_analytics.data_lag_days', 2));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config('titan.google_analytics.backfill_months', 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config('titan.google_analytics.incremental_days', 5));
        $start = $connection->last_synced_at
            ? $connection->last_synced_at->copy()->subDays($incrementalDays)->startOfDay()
            : $earliest;

        if ($start->lt($earliest)) {
            $start = $earliest;
        }

        return [$start, $end];
    }

    /**
     * @return list<string>
     */
    protected function dimensionsForStream(string $stream): array
    {
        return match ($stream) {
            'traffic_channel' => ['date', 'sessionDefaultChannelGroup'],
            'events_daily' => ['date', 'eventName'],
            'landing_page' => ['date', 'landingPage'],
            default => ['date'],
        };
    }

    /**
     * @return list<string>
     */
    protected function metricsForStream(string $stream): array
    {
        return match ($stream) {
            'traffic_channel' => ['sessions', 'activeUsers'],
            'events_daily' => ['eventCount'],
            'landing_page' => ['sessions', 'activeUsers'],
            default => ['totalUsers', 'activeUsers', 'sessions'],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function normalizeRows(string $stream, array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $dimensionValues = $row['dimensionValues'] ?? [];
            $metricValues = $row['metricValues'] ?? [];

            if (! is_array($dimensionValues) || $dimensionValues === []) {
                continue;
            }

            $date = $this->normalizeDate((string) ($dimensionValues[0]['value'] ?? ''));

            if ($date === '') {
                continue;
            }

            $payload = ['date' => $date];
            $externalSuffix = $date;

            if ($stream === 'traffic_channel') {
                $channel = (string) ($dimensionValues[1]['value'] ?? '');

                if ($channel === '') {
                    continue;
                }

                $payload['channel'] = $channel;
                $payload['sessions'] = $this->metricValue($metricValues, 0);
                $payload['active_users'] = $this->metricValue($metricValues, 1);
                $externalSuffix .= ':'.hash('sha256', $channel);
            } elseif ($stream === 'events_daily') {
                $eventName = (string) ($dimensionValues[1]['value'] ?? '');

                if ($eventName === '') {
                    continue;
                }

                $payload['event_name'] = $eventName;
                $payload['event_count'] = $this->metricValue($metricValues, 0);
                $externalSuffix .= ':'.hash('sha256', $eventName);
            } elseif ($stream === 'landing_page') {
                $landingPage = (string) ($dimensionValues[1]['value'] ?? '');

                if ($landingPage === '') {
                    continue;
                }

                $payload['landing_page'] = $landingPage;
                $payload['sessions'] = $this->metricValue($metricValues, 0);
                $payload['active_users'] = $this->metricValue($metricValues, 1);
                $externalSuffix .= ':'.hash('sha256', $landingPage);
            } else {
                $payload['visitors'] = $this->metricValue($metricValues, 0);
                $payload['active_users'] = $this->metricValue($metricValues, 1);
                $payload['sessions'] = $this->metricValue($metricValues, 2);
            }

            $records[] = [
                'resource_type' => $stream,
                'external_id' => $externalSuffix,
                'payload' => $payload,
            ];
        }

        return $records;
    }

    protected function normalizeDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{8}$/', $value) === 1) {
            return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
        }

        return $value;
    }

    /**
     * @param  list<array{value?: string}>  $metricValues
     */
    protected function metricValue(array $metricValues, int $index): float
    {
        $value = $metricValues[$index]['value'] ?? 0;

        return (float) $value;
    }

    protected function nextStream(string $stream): ?string
    {
        $index = array_search($stream, $this->streams, true);

        if ($index === false) {
            return null;
        }

        return $this->streams[$index + 1] ?? null;
    }

}
