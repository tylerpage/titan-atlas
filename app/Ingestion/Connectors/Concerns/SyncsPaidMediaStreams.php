<?php

namespace App\Ingestion\Connectors\Concerns;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Models\Connection;
use App\Support\SyncDateChunkWalker;
use Carbon\Carbon;
use Illuminate\Support\Arr;

trait SyncsPaidMediaStreams
{
    abstract protected function paidMediaConfigKey(): string;

    abstract protected function paidMediaCursorPrefix(): string;

    abstract protected function paidMediaApiClient(): PaidMediaAdsApiClient;

    abstract protected function paidMediaAccountCredentialKey(): string;

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $accountId = trim((string) ($credentials[$this->paidMediaAccountCredentialKey()] ?? ''));

        if ($accessToken === '' || $accountId === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.'.$this->paidMediaConfigKey().'.chunk_days', 7));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $rows = $this->paidMediaApiClient()->reportRows(
            $accessToken,
            $accountId,
            $chunkStart->toDateString(),
            $chunkEnd->toDateString(),
            $state['stream'],
        );

        $records = $this->normalizePaidMediaRows($state['stream'], $rows);
        $nextState = SyncDateChunkWalker::nextDateChunkState($state, $chunkDays);

        if ($nextState !== null) {
            return $this->result($records, $this->encodePaidMediaCursor($nextState), true, $progressFrom, $progressThrough);
        }

        if (! empty($state['fan_out'])) {
            return $this->result($records, null, false, $progressFrom, $progressThrough);
        }

        $nextStream = $this->nextPaidMediaStream($state['stream']);

        if ($nextStream !== null) {
            return $this->result(
                $records,
                $this->encodePaidMediaCursor($this->nextStreamCursorState($connection, $nextStream, $state)),
                true,
                $progressFrom,
                $progressThrough,
            );
        }

        return $this->result($records, null, false, $progressFrom, $progressThrough);
    }

    public function syncStreams(): array
    {
        return ['spend_daily', 'campaign_daily'];
    }

    public function initialSyncCursor(Connection $connection, string $stream, bool $fanOut = false): string
    {
        [$start, $end] = $this->resolveDateRange($connection);
        $walk = SyncDateChunkWalker::walkForConnection($connection);

        return $this->encodePaidMediaCursor(SyncDateChunkWalker::initialState($start, $end, $walk, [
            'stream' => $stream,
            'fan_out' => $fanOut,
        ]));
    }

    /**
     * @return array{stream: string, start_date: string, end_date: string, range_start: string, range_end: string, chunk_end: string, walk: string, fan_out?: bool}
     */
    protected function decodeCursor(?string $cursor, Connection $connection): array
    {
        $prefix = $this->paidMediaCursorPrefix().':';

        if ($cursor !== null && str_starts_with($cursor, $prefix)) {
            $decoded = json_decode(substr($cursor, strlen($prefix)), true);

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
            'stream' => $this->syncStreams()[0],
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function encodePaidMediaCursor(array $state): string
    {
        return $this->paidMediaCursorPrefix().':'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $configKey = $this->paidMediaConfigKey();
        $lagDays = max(0, (int) config("titan.{$configKey}.data_lag_days", 1));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config("titan.{$configKey}.backfill_months", 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config("titan.{$configKey}.incremental_days", 5));
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
    protected function normalizePaidMediaRows(string $stream, array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $date = $this->resolvePaidMediaDate($row);

            if ($date === '') {
                continue;
            }

            $cost = $this->resolvePaidMediaCost($row);
            $clicks = (float) ($row['clicks'] ?? Arr::get($row, 'metrics.clicks', 0));
            $impressions = (float) ($row['impressions'] ?? Arr::get($row, 'metrics.impressions', 0));

            $payload = [
                'date' => $date,
                'cost' => $cost,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => (float) ($row['ctr'] ?? ($impressions > 0 ? ($clicks / $impressions) * 100 : 0)),
                'conversions' => (float) ($row['conversions'] ?? Arr::get($row, 'metrics.conversions', Arr::get($row, 'purchases', Arr::get($row, 'attributedOrders', 0)))),
                'conversions_value' => (float) ($row['conversions_value'] ?? Arr::get($row, 'metrics.conversions_value', Arr::get($row, 'sales', Arr::get($row, 'attributedSales', Arr::get($row, 'SALE_AMOUNT', 0))))),
            ];

            if ($stream === 'campaign_daily') {
                $campaignId = (string) ($row['campaign_id'] ?? Arr::get($row, 'campaign.id', ''));

                if ($campaignId === '' || ($cost <= 0 && $payload['conversions_value'] <= 0)) {
                    continue;
                }

                $payload['campaign_id'] = $campaignId;
                $payload['campaign_name'] = (string) ($row['campaign_name'] ?? Arr::get($row, 'campaign.name', $campaignId));
                $payload['objective'] = (string) ($row['objective'] ?? Arr::get($row, 'campaign_type', Arr::get($row, 'ad_product', '')));
                $externalId = "{$date}:{$campaignId}";
            } elseif ($this->isPaidMediaDimensionStream($stream)) {
                $dimensionKey = (string) ($row['dimension_key'] ?? Arr::get($row, 'keyword', Arr::get($row, 'listing_id', '')));

                if ($dimensionKey === '' || ($cost <= 0 && $payload['conversions_value'] <= 0)) {
                    continue;
                }

                $payload['dimension_key'] = $dimensionKey;
                $payload['dimension_label'] = (string) ($row['dimension_label'] ?? Arr::get($row, 'keyword_text', Arr::get($row, 'listing_title', $dimensionKey)));
                $externalId = "{$date}:{$dimensionKey}";
            } else {
                if ($cost <= 0 && $payload['conversions_value'] <= 0) {
                    continue;
                }

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
    protected function resolvePaidMediaDate(array $row): string
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
    protected function resolvePaidMediaCost(array $row): float
    {
        foreach (['cost', 'spend', 'adSpend', 'AD_FEES'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        $micros = $row['spend_micro'] ?? Arr::get($row, 'metrics.spend_micro') ?? Arr::get($row, 'metrics.spendMicro');

        if ($micros !== null && is_numeric($micros)) {
            return round(((float) $micros) / 1_000_000, 6);
        }

        return 0.0;
    }

    protected function nextPaidMediaStream(string $current): ?string
    {
        $streams = $this->syncStreams();
        $index = array_search($current, $streams, true);

        if ($index === false || ! isset($streams[$index + 1])) {
            return null;
        }

        return $streams[$index + 1];
    }

    protected function isPaidMediaDimensionStream(string $stream): bool
    {
        return in_array($stream, [
            'ad_type_daily',
            'keyword_daily',
            'listing_daily',
            'ad_product_daily',
            'page_type_daily',
            'tactic_daily',
        ], true);
    }
}
