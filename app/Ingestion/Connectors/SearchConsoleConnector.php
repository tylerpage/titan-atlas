<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Ingestion\Connectors\SearchConsole\SearchConsoleClient;
use App\Models\Connection;
use App\Support\SyncDateChunkWalker;
use Carbon\Carbon;

class SearchConsoleConnector extends AbstractConnector implements FanOutSyncConnector
{
    use WalksSyncDateChunks;
    /** @var list<string> */
    protected array $streams = ['search_daily', 'keyword', 'search_page', 'search_device'];

    public function __construct(protected SearchConsoleClient $client) {}

    public function type(): string
    {
        return ConnectorType::SearchConsole->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['refresh_token']) || empty($credentials['site_url'])) {
            return ValidationResult::fail('Search Console requires Google sign-in and a property selection.');
        }

        $siteUrl = (string) $credentials['site_url'];
        $refreshToken = (string) $credentials['refresh_token'];
        $debug = [
            'site_url' => $siteUrl,
            'token_length' => strlen($refreshToken),
        ];

        try {
            $sites = $this->client->listSites($refreshToken);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not connect to Google Search Console.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $match = collect($sites)->firstWhere('siteUrl', $siteUrl);

        if ($match === null) {
            return ValidationResult::fail(
                'Selected property was not found for this Google account.',
                array_merge($debug, [
                    'available_sites' => collect($sites)->pluck('siteUrl')->take(10)->all(),
                    'hint' => 'Reconnect with Google and choose a property listed for your account.',
                ]),
            );
        }

        if (! $this->client->hasQueryablePermission((string) ($match['permissionLevel'] ?? ''))) {
            return ValidationResult::fail(
                'Insufficient permission for this Search Console property.',
                array_merge($debug, [
                    'permission_level' => $match['permissionLevel'],
                    'hint' => 'You need owner, full, or restricted access on the property.',
                ]),
            );
        }

        return ValidationResult::ok(
            'Connected to Search Console property '.$siteUrl,
            array_merge($debug, ['permission_level' => $match['permissionLevel']]),
        );
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $siteUrl = (string) ($credentials['site_url'] ?? '');
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($siteUrl === '' || $refreshToken === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.search_console.chunk_days', 7));
        $rowLimit = max(1, (int) config('titan.search_console.row_limit', 5000));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $dimensions = $this->dimensionsForStream($state['stream']);
        $rows = $this->client->querySearchAnalytics($refreshToken, $siteUrl, [
            'startDate' => $chunkStart->toDateString(),
            'endDate' => $chunkEnd->toDateString(),
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
            'startRow' => $state['start_row'],
        ]);

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
        if ($cursor !== null && str_starts_with($cursor, 'gsc:')) {
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
        return 'gsc:'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $lagDays = max(0, (int) config('titan.search_console.data_lag_days', 3));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config('titan.search_console.backfill_months', 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config('titan.search_console.incremental_days', 5));
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
            'keyword' => ['date', 'query'],
            'search_page' => ['date', 'page'],
            'search_device' => ['date', 'device'],
            default => ['date'],
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

            $keys = $row['keys'] ?? [];

            if (! is_array($keys) || $keys === []) {
                continue;
            }

            $date = (string) ($keys[0] ?? '');
            $clicks = (float) ($row['clicks'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $ctr = (float) ($row['ctr'] ?? 0);
            $position = (float) ($row['position'] ?? 0);

            if ($date === '') {
                continue;
            }

            $payload = [
                'date' => $date,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $ctr,
                'position' => $position,
            ];

            if ($stream === 'keyword') {
                $keyword = (string) ($keys[1] ?? '');

                if ($keyword === '') {
                    continue;
                }

                $payload['keyword'] = $keyword;
                $records[] = [
                    'resource_type' => 'keyword',
                    'external_id' => $date.':'.hash('sha256', $keyword),
                    'payload' => $payload,
                ];

                continue;
            }

            if ($stream === 'search_page') {
                $page = (string) ($keys[1] ?? '');

                if ($page === '') {
                    continue;
                }

                $payload['page'] = $page;
                $records[] = [
                    'resource_type' => 'search_page',
                    'external_id' => $date.':'.hash('sha256', $page),
                    'payload' => $payload,
                ];

                continue;
            }

            if ($stream === 'search_device') {
                $device = (string) ($keys[1] ?? '');

                if ($device === '') {
                    continue;
                }

                $payload['device'] = $device;
                $records[] = [
                    'resource_type' => 'search_device',
                    'external_id' => $date.':'.$device,
                    'payload' => $payload,
                ];

                continue;
            }

            $records[] = [
                'resource_type' => 'search_daily',
                'external_id' => $date,
                'payload' => $payload,
            ];
        }

        return $records;
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
