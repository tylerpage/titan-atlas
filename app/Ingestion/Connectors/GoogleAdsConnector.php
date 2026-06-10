<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Support\SyncDateChunkWalker;
use App\Ingestion\Connectors\GoogleAds\GoogleAdsApiClient;
use App\Models\Connection;
use Carbon\Carbon;

class GoogleAdsConnector extends AbstractConnector implements FanOutSyncConnector
{
    use WalksSyncDateChunks;

    /** @var list<string> */
    protected array $streams = ['spend_daily', 'campaign_daily'];

    public function __construct(protected GoogleAdsApiClient $client) {}

    public function type(): string
    {
        return ConnectorType::GoogleAds->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['refresh_token']) || empty($credentials['customer_id'])) {
            return ValidationResult::fail('Google Ads requires Google sign-in and an account selection.');
        }

        $customerId = $this->client->normalizeCustomerId((string) $credentials['customer_id']);
        $refreshToken = (string) $credentials['refresh_token'];
        $loginCustomerId = $this->loginCustomerId($credentials);
        $debug = [
            'customer_id' => $customerId,
            'token_length' => strlen($refreshToken),
            'login_customer_id' => $loginCustomerId,
        ];

        try {
            $this->client->testConnection($refreshToken, $customerId, $loginCustomerId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not query the selected Google Ads account.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $displayName = $this->client->customerDisplayName($refreshToken, $customerId, $loginCustomerId);

        return ValidationResult::ok(
            'Connected to Google Ads account '.$displayName,
            array_merge($debug, ['display_name' => $displayName]),
        );
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $customerId = $this->client->normalizeCustomerId((string) ($credentials['customer_id'] ?? ''));
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');
        $loginCustomerId = $this->loginCustomerId($credentials);

        if ($customerId === '' || $refreshToken === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.google_ads.chunk_days', 7));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $query = $this->queryForStream(
            $state['stream'],
            $chunkStart->toDateString(),
            $chunkEnd->toDateString(),
        );

        $rows = $this->client->searchStream($refreshToken, $customerId, $query, $loginCustomerId);
        $records = $this->normalizeRows($state['stream'], $rows);

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
            'fan_out' => $fanOut,
        ]));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function loginCustomerId(array $credentials): ?string
    {
        $loginCustomerId = trim((string) ($credentials['login_customer_id'] ?? ''));

        if ($loginCustomerId === '') {
            return null;
        }

        return $this->client->normalizeCustomerId($loginCustomerId);
    }

    /**
     * @return array{stream: string, start_date: string, end_date: string}
     */
    protected function decodeCursor(?string $cursor, Connection $connection): array
    {
        if ($cursor !== null && str_starts_with($cursor, 'gads:')) {
            $decoded = json_decode(substr($cursor, 5), true);

            if (is_array($decoded) && isset($decoded['stream'])) {
                return SyncDateChunkWalker::mergeDecodedState([
                    'stream' => (string) $decoded['stream'],
                    'start_date' => (string) ($decoded['start_date'] ?? ''),
                    'end_date' => (string) ($decoded['end_date'] ?? ''),
                    'range_start' => (string) ($decoded['range_start'] ?? ''),
                    'range_end' => (string) ($decoded['range_end'] ?? ''),
                    'chunk_end' => (string) ($decoded['chunk_end'] ?? ''),
                    'walk' => (string) ($decoded['walk'] ?? ''),
                    'fan_out' => (bool) ($decoded['fan_out'] ?? false),
                ], $connection);
            }
        }

        [$start, $end] = $this->resolveDateRange($connection);
        $walk = SyncDateChunkWalker::walkForConnection($connection);

        return SyncDateChunkWalker::initialState($start, $end, $walk, [
            'stream' => $this->streams[0],
        ]);
    }

    /**
     * @param  array{stream: string, start_date: string, end_date: string}  $state
     */
    protected function encodeCursor(array $state): string
    {
        return 'gads:'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $lagDays = max(0, (int) config('titan.google_ads.data_lag_days', 1));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config('titan.google_ads.backfill_months', 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config('titan.google_ads.incremental_days', 5));
        $start = $connection->last_synced_at
            ? $connection->last_synced_at->copy()->subDays($incrementalDays)->startOfDay()
            : $earliest;

        if ($start->lt($earliest)) {
            $start = $earliest;
        }

        return [$start, $end];
    }

    protected function queryForStream(string $stream, string $startDate, string $endDate): string
    {
        $dateFilter = "segments.date BETWEEN '{$startDate}' AND '{$endDate}'";

        return match ($stream) {
            'campaign_daily' => "SELECT segments.date, campaign.id, campaign.name, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.ctr, metrics.conversions_value FROM campaign WHERE {$dateFilter} ORDER BY segments.date",
            default => "SELECT segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.ctr, metrics.conversions_value FROM customer WHERE {$dateFilter} ORDER BY segments.date",
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

            $date = (string) ($row['segments']['date'] ?? '');

            if ($date === '') {
                continue;
            }

            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $payload = [
                'date' => $date,
                'cost' => $this->microsToCurrency($metrics['costMicros'] ?? 0),
                'impressions' => (float) ($metrics['impressions'] ?? 0),
                'clicks' => (float) ($metrics['clicks'] ?? 0),
                'ctr' => (float) ($metrics['ctr'] ?? 0),
                'conversions_value' => (float) ($metrics['conversionsValue'] ?? 0),
            ];

            if ($stream === 'campaign_daily') {
                $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
                $campaignId = (string) ($campaign['id'] ?? '');

                if ($campaignId === '') {
                    continue;
                }

                $payload['campaign_id'] = $campaignId;
                $payload['campaign_name'] = (string) ($campaign['name'] ?? '');
                $records[] = [
                    'resource_type' => 'campaign_daily',
                    'external_id' => $date.':'.$campaignId,
                    'payload' => $payload,
                ];

                continue;
            }

            $records[] = [
                'resource_type' => 'spend_daily',
                'external_id' => $date,
                'payload' => $payload,
            ];
        }

        return $records;
    }

    protected function microsToCurrency(mixed $micros): float
    {
        return round(((float) $micros) / 1_000_000, 2);
    }

    protected function nextStream(string $stream): ?string
    {
        $index = array_search($stream, $this->streams, true);

        if ($index === false) {
            return null;
        }

        return $this->streams[$index + 1] ?? null;
    }

    /**
     * @param  list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>  $records
     */
}
