<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Ingestion\Connectors\RedditAds\RedditAdsApiClient;
use App\Models\Connection;
use App\Support\SyncDateChunkWalker;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class RedditAdsConnector extends AbstractConnector implements FanOutSyncConnector
{
    use WalksSyncDateChunks;

    /** @var list<string> */
    protected array $streams = ['spend_daily', 'campaign_daily'];

    public function __construct(protected RedditAdsApiClient $client) {}

    public function type(): string
    {
        return ConnectorType::RedditAds->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $accountId = trim((string) ($credentials['account_id'] ?? ''));

        if ($accessToken === '') {
            return ValidationResult::fail('Reddit Ads requires an OAuth access token with ads read scope.');
        }

        if ($accountId === '') {
            return ValidationResult::fail('Reddit Ads requires an ad account ID (for example t2_abc123).');
        }

        try {
            $debug = $this->client->testConnection($accessToken, $accountId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not authenticate with the Reddit Ads API for that account.',
                ['account_id' => $accountId, 'hint' => $e->getMessage()],
            );
        }

        return ValidationResult::ok(
            'Connected to Reddit Ads account '.$accountId,
            $debug,
        );
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $accountId = trim((string) ($credentials['account_id'] ?? ''));

        if ($accessToken === '' || $accountId === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.reddit_ads.chunk_days', 7));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $rows = $this->client->reportRows(
            $accessToken,
            $accountId,
            $chunkStart->toDateString(),
            $chunkEnd->toDateString(),
            $state['stream'],
        );

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
     * @return array{stream: string, start_date: string, end_date: string, range_start: string, range_end: string, chunk_end: string, walk: string, fan_out?: bool}
     */
    protected function decodeCursor(?string $cursor, Connection $connection): array
    {
        if ($cursor !== null && str_starts_with($cursor, 'reddit:')) {
            $decoded = json_decode(substr($cursor, 7), true);

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
        return 'reddit:'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $lagDays = max(0, (int) config('titan.reddit_ads.data_lag_days', 1));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config('titan.reddit_ads.backfill_months', 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config('titan.reddit_ads.incremental_days', 5));
        $start = $connection->last_synced_at
            ? $connection->last_synced_at->copy()->subDays($incrementalDays)->startOfDay()
            : $earliest;

        if ($start->lt($earliest)) {
            $start = $earliest;
        }

        return [$start, $end];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function normalizeRows(string $stream, array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $date = $this->resolveDate($row);

            if ($date === '') {
                continue;
            }

            $payload = [
                'date' => $date,
                'cost' => $this->resolveCost($row),
                'impressions' => (float) ($row['impressions'] ?? Arr::get($row, 'metrics.impressions', 0)),
                'clicks' => (float) ($row['clicks'] ?? Arr::get($row, 'metrics.clicks', 0)),
                'ctr' => (float) ($row['ctr'] ?? Arr::get($row, 'metrics.ctr', 0)),
                'conversions' => (float) ($row['conversions'] ?? Arr::get($row, 'metrics.conversions', 0)),
                'conversions_value' => (float) ($row['conversions_value'] ?? Arr::get($row, 'metrics.conversions_value', 0)),
            ];

            if ($stream === 'campaign_daily') {
                $campaignId = (string) ($row['campaign_id'] ?? Arr::get($row, 'campaign.id', Arr::get($row, 'campaign_id', '')));

                if ($campaignId === '') {
                    continue;
                }

                $payload['campaign_id'] = $campaignId;
                $payload['campaign_name'] = (string) ($row['campaign_name'] ?? Arr::get($row, 'campaign.name', $campaignId));
                $externalId = "{$date}:{$campaignId}";
            } else {
                $externalId = $date;
            }

            $records[] = [
                'resource_type' => $stream,
                'external_id' => $externalId,
                'payload' => $payload,
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveDate(array $row): string
    {
        $date = $row['date']
            ?? Arr::get($row, 'day')
            ?? Arr::get($row, 'report_date')
            ?? Arr::get($row, 'metrics.date');

        if (! is_string($date) || $date === '') {
            return '';
        }

        return substr($date, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveCost(array $row): float
    {
        if (isset($row['cost']) && is_numeric($row['cost'])) {
            return (float) $row['cost'];
        }

        if (isset($row['spend']) && is_numeric($row['spend'])) {
            return (float) $row['spend'];
        }

        $micros = $row['spend_micro'] ?? Arr::get($row, 'metrics.spend_micro') ?? Arr::get($row, 'metrics.spendMicro');

        if ($micros !== null && is_numeric($micros)) {
            return $this->client->microsToCurrency($micros);
        }

        return 0.0;
    }

    protected function nextStream(string $current): ?string
    {
        $index = array_search($current, $this->streams, true);

        if ($index === false || ! isset($this->streams[$index + 1])) {
            return null;
        }

        return $this->streams[$index + 1];
    }
}
