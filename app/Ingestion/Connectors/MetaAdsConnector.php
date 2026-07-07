<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Ingestion\Connectors\MetaAds\MetaAdsApiClient;
use App\Models\Connection;
use App\Support\SyncDateChunkWalker;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class MetaAdsConnector extends AbstractConnector implements FanOutSyncConnector
{
    use WalksSyncDateChunks;

    /** @var list<string> */
    protected array $streams = [
        'spend_daily',
        'campaign_daily',
        'placement_daily',
        'device_daily',
    ];

    /** @var list<string> */
    protected array $purchaseActionTypes = [
        'purchase',
        'omni_purchase',
        'offsite_conversion.fb_pixel_purchase',
        'onsite_conversion.purchase',
    ];

    public function __construct(protected MetaAdsApiClient $client) {}

    public function type(): string
    {
        return ConnectorType::MetaAds->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($accessToken === '') {
            return ValidationResult::fail('Meta Ads requires a Marketing API access token with ads_read scope.');
        }

        $debug = ['token_length' => strlen($accessToken)];

        try {
            $adAccounts = $this->client->listAdAccounts($accessToken);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not authenticate with the Meta Marketing API.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $debug['ad_accounts'] = $adAccounts;

        $adAccountId = $this->client->normalizeAdAccountId((string) ($credentials['ad_account_id'] ?? ''));

        if ($adAccountId === '') {
            return ValidationResult::ok(
                'Meta access token is valid. Select an ad account to finish setup.',
                $debug,
            );
        }

        try {
            $account = $this->client->testConnection($accessToken, $adAccountId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not access the selected Meta ad account.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        return ValidationResult::ok(
            'Connected to Meta ad account '.$account['name'],
            array_merge($debug, $account),
        );
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $adAccountId = $this->client->normalizeAdAccountId((string) ($credentials['ad_account_id'] ?? ''));

        if ($accessToken === '' || $adAccountId === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.meta_ads.chunk_days', 7));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $page = $this->client->insightsPage(
            $accessToken,
            $adAccountId,
            $chunkStart->toDateString(),
            $chunkEnd->toDateString(),
            $state['stream'],
            $state['page_after'] ?? null,
        );

        $records = $this->normalizeRows($state['stream'], $page['rows']);

        if ($page['after'] !== null) {
            $nextState = array_merge($state, ['page_after' => $page['after']]);

            return $this->result(
                $records,
                $this->encodeCursor($nextState),
                true,
                $progressFrom,
                $progressThrough,
            );
        }

        $nextState = SyncDateChunkWalker::nextDateChunkState(
            array_merge($state, ['page_after' => null]),
            $chunkDays,
        );

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
            'page_after' => null,
        ]));
    }

    /**
     * @return array{stream: string, start_date: string, end_date: string, range_start: string, range_end: string, chunk_end: string, walk: string, fan_out?: bool, page_after?: string|null}
     */
    protected function decodeCursor(?string $cursor, Connection $connection): array
    {
        if ($cursor !== null && str_starts_with($cursor, 'meta:')) {
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
                    'page_after' => isset($decoded['page_after']) ? (string) $decoded['page_after'] : null,
                ], $connection);
            }
        }

        [$start, $end] = $this->resolveDateRange($connection);
        $walk = SyncDateChunkWalker::walkForConnection($connection);

        return SyncDateChunkWalker::initialState($start, $end, $walk, [
            'stream' => $this->streams[0],
            'page_after' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function encodeCursor(array $state): string
    {
        return 'meta:'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $lagDays = max(0, (int) config('titan.meta_ads.data_lag_days', 1));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config('titan.meta_ads.backfill_months', 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config('titan.meta_ads.incremental_days', 5));
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

            $cost = (float) ($row['spend'] ?? 0);
            $clicks = (float) ($row['inline_link_clicks'] ?? $row['clicks'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
            $actionValues = is_array($row['action_values'] ?? null) ? $row['action_values'] : [];

            $payload = [
                'date' => $date,
                'cost' => $cost,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => (float) ($row['ctr'] ?? ($impressions > 0 ? ($clicks / $impressions) * 100 : 0)),
                'cpc' => (float) ($row['cpc'] ?? ($clicks > 0 ? $cost / $clicks : 0)),
                'cpm' => (float) ($row['cpm'] ?? ($impressions > 0 ? ($cost / $impressions) * 1000 : 0)),
                'reach' => (float) ($row['reach'] ?? 0),
                'frequency' => (float) ($row['frequency'] ?? 0),
                'conversions' => $this->client->sumActionValues($actions, $this->purchaseActionTypes),
                'conversions_value' => $this->client->sumActionValues($actionValues, $this->purchaseActionTypes),
            ];

            if ($stream === 'campaign_daily') {
                $campaignId = (string) ($row['campaign_id'] ?? '');

                if ($campaignId === '' || $cost <= 0) {
                    continue;
                }

                $payload['campaign_id'] = $campaignId;
                $payload['campaign_name'] = (string) ($row['campaign_name'] ?? $campaignId);
                $payload['objective'] = (string) ($row['objective'] ?? '');
                $externalId = "{$date}:{$campaignId}";
            } elseif ($stream === 'placement_daily') {
                $platform = (string) ($row['publisher_platform'] ?? 'unknown');
                $position = (string) ($row['platform_position'] ?? 'unknown');
                $dimensionKey = "{$platform}|{$position}";
                $payload['dimension_key'] = $dimensionKey;
                $payload['dimension_label'] = $this->formatPlacementLabel($platform, $position);
                $externalId = "{$date}:{$dimensionKey}";
            } elseif ($stream === 'device_daily') {
                $device = (string) ($row['device_platform'] ?? 'unknown');
                $payload['dimension_key'] = $device;
                $payload['dimension_label'] = ucfirst(str_replace('_', ' ', $device));
                $externalId = "{$date}:{$device}";
            } else {
                $externalId = $date;
            }

            if ($stream !== 'campaign_daily' && $cost <= 0 && $payload['conversions_value'] <= 0) {
                continue;
            }

            $records[] = [
                'resource_type' => $stream,
                'external_id' => $externalId,
                'payload' => $payload,
            ];
        }

        return $records;
    }

    protected function formatPlacementLabel(string $platform, string $position): string
    {
        $platformLabel = ucfirst(str_replace('_', ' ', $platform));
        $positionLabel = ucfirst(str_replace('_', ' ', $position));

        return "{$platformLabel} · {$positionLabel}";
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveDate(array $row): string
    {
        $date = $row['date_start'] ?? Arr::get($row, 'date');

        if (! is_string($date) || $date === '') {
            return '';
        }

        return substr($date, 0, 10);
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
