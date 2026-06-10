<?php

namespace App\Services\Analytics;

use App\Data\Ingestion\TransformChunkResult;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Support\DedupedRawPayloadQuery;
use App\Support\JsonPayloadSql;
use App\Support\MetricDimensions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TransformConnectionDataService
{
    public function transform(
        SyncRun $syncRun,
        ?int $afterPayloadId = null,
        bool $purgeExisting = false,
        bool $syncRunCatchUp = false,
        ?int $catchUpAfterPayloadId = null,
    ): TransformChunkResult {
        $syncRun->loadMissing('connection.clientDashboard');

        return $this->processConnection(
            $syncRun->connection,
            $afterPayloadId,
            $purgeExisting,
            unlimited: false,
            syncRun: $syncRunCatchUp ? $syncRun : null,
            syncRunCatchUp: $syncRunCatchUp,
            catchUpAfterPayloadId: $catchUpAfterPayloadId,
        );
    }

    public function rebuildForConnection(Connection $connection): int
    {
        return $this->processConnection(
            $connection,
            afterPayloadId: null,
            purgeExisting: true,
            unlimited: true,
        )->written;
    }

    protected function processConnection(
        Connection $connection,
        ?int $afterPayloadId,
        bool $purgeExisting,
        bool $unlimited,
        ?SyncRun $syncRun = null,
        bool $syncRunCatchUp = false,
        ?int $catchUpAfterPayloadId = null,
    ): TransformChunkResult {
        $connection->loadMissing('clientDashboard');
        $dashboard = $connection->clientDashboard;
        $connectionId = $connection->id;
        $allowedResourceTypes = $this->allowedResourceTypes();

        if ($purgeExisting) {
            MetricSnapshot::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->whereRaw(JsonPayloadSql::text('dimensions', 'connection_id') . ' = ?', [$connectionId])
                ->delete();
        }

        $written = 0;
        $stoppedEarly = false;
        $chunksProcessed = 0;
        $chunkSize = max(1, (int) config('titan.transform.payloads_per_chunk', 750));
        $maxChunks = max(1, (int) config('titan.transform.chunks_per_job', 3));
        $maxSeconds = max(10, (int) config('titan.transform.max_seconds_per_job', 45));
        $startedAt = microtime(true);

        $inCatchUp = $syncRunCatchUp && $syncRun !== null;
        $catchUpCursor = $catchUpAfterPayloadId ?? 0;
        $incrementalCursor = $afterPayloadId;
        $lastCatchUpPayloadId = $catchUpAfterPayloadId;
        $lastIncrementalPayloadId = $afterPayloadId;

        while (true) {
            if ($inCatchUp) {
                $payloads = $this->catchUpPayloadQuery($connectionId, $syncRun, $afterPayloadId, $allowedResourceTypes)
                    ->where('id', '>', $catchUpCursor)
                    ->limit($chunkSize)
                    ->get(['id', 'resource_type', 'payload']);

                if ($payloads->isEmpty()) {
                    $inCatchUp = false;

                    continue;
                }

                $written += $this->processPayloadBatch(
                    $dashboard,
                    $connectionId,
                    $payloads,
                    replaceValues: true,
                );
                $catchUpCursor = $payloads->last()->id;
                $lastCatchUpPayloadId = $catchUpCursor;
            } else {
                $payloads = $this->incrementalPayloadQuery($connectionId, $allowedResourceTypes)
                    ->when($incrementalCursor !== null, fn ($query) => $query->where('id', '>', $incrementalCursor))
                    ->limit($chunkSize)
                    ->get(['id', 'resource_type', 'payload']);

                if ($payloads->isEmpty()) {
                    break;
                }

                $written += $this->processPayloadBatch(
                    $dashboard,
                    $connectionId,
                    $payloads,
                    replaceValues: false,
                );
                $incrementalCursor = $payloads->last()->id;
                $lastIncrementalPayloadId = $incrementalCursor;
                $connection->update(['last_transformed_payload_id' => $incrementalCursor]);
            }

            $chunksProcessed++;

            if ($chunksProcessed % 2 === 0) {
                gc_collect_cycles();
            }

            if (! $unlimited && ($chunksProcessed >= $maxChunks || (microtime(true) - $startedAt) >= $maxSeconds)) {
                $stoppedEarly = true;

                break;
            }
        }

        if ($inCatchUp) {
            $hasMore = $stoppedEarly && $this->catchUpPayloadQuery(
                $connectionId,
                $syncRun,
                $afterPayloadId,
                $allowedResourceTypes,
            )
                ->where('id', '>', $catchUpCursor)
                ->exists();

            return new TransformChunkResult(
                $written,
                $hasMore,
                $lastCatchUpPayloadId,
                syncRunCatchUp: true,
            );
        }

        $hasMore = $stoppedEarly && $lastIncrementalPayloadId !== null
            && $this->hasMorePayloads($connectionId, $lastIncrementalPayloadId, $allowedResourceTypes);

        return new TransformChunkResult($written, $hasMore, $lastIncrementalPayloadId);
    }

    /**
     * @return list<string>
     */
    protected function allowedResourceTypes(): array
    {
        return config('titan.transform.resource_types', [
            'order',
            'order_line_item',
            'search_daily',
            'traffic_daily',
            'organic_traffic',
            'ad_spend',
            'spend_daily',
            'campaign_daily',
            'channel_daily',
        ]);
    }

    /**
     * @param  list<string>  $allowedResourceTypes
     */
    protected function catchUpPayloadQuery(
        int $connectionId,
        SyncRun $syncRun,
        ?int $maxPayloadId,
        array $allowedResourceTypes,
    ) {
        return RawConnectorPayload::query()
            ->where('connection_id', $connectionId)
            ->where('sync_run_id', $syncRun->id)
            ->when($maxPayloadId !== null, fn ($query) => $query->where('id', '<=', $maxPayloadId))
            ->whereIn('resource_type', $allowedResourceTypes)
            ->orderBy('id');
    }

    /**
     * @param  list<string>  $allowedResourceTypes
     */
    protected function incrementalPayloadQuery(int $connectionId, array $allowedResourceTypes)
    {
        return DedupedRawPayloadQuery::applyToEloquent(
            RawConnectorPayload::query()
                ->where('connection_id', $connectionId)
                ->whereIn('resource_type', $allowedResourceTypes),
            $connectionId,
        )->orderBy('id');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RawConnectorPayload>  $payloads
     */
    protected function processPayloadBatch(
        \App\Models\ClientDashboard $dashboard,
        int $connectionId,
        $payloads,
        bool $replaceValues,
    ): int {
        /** @var array<string, array{date: Carbon, key: string, value: float, dimensions: ?array<string, mixed>}> $chunkBuckets */
        $chunkBuckets = [];

        foreach ($payloads as $payload) {
            foreach ($this->extractMetrics($payload->resource_type, $payload->payload, $connectionId) as $metric) {
                $dimensions = $metric['dimensions'] ?? null;
                $dimensionHash = MetricDimensions::hash($dimensions);
                $date = $metric['date']->toDateString();
                $bucketKey = "{$date}|{$metric['key']}|{$dimensionHash}";

                if (! isset($chunkBuckets[$bucketKey])) {
                    $chunkBuckets[$bucketKey] = [
                        'date' => $metric['date'],
                        'key' => $metric['key'],
                        'value' => 0.0,
                        'dimensions' => $dimensions,
                    ];
                }

                $chunkBuckets[$bucketKey]['value'] += $metric['value'];
            }
        }

        return $this->persistMetricBuckets($dashboard, $chunkBuckets, $replaceValues);
    }

    /**
     * @param  list<string>  $allowedResourceTypes
     */
    protected function hasMorePayloads(int $connectionId, int $afterPayloadId, array $allowedResourceTypes): bool
    {
        return $this->incrementalPayloadQuery($connectionId, $allowedResourceTypes)
            ->where('id', '>', $afterPayloadId)
            ->exists();
    }

    /**
     * @param  array<string, array{date: Carbon, key: string, value: float, dimensions: ?array<string, mixed>}>  $buckets
     */
    protected function persistMetricBuckets(
        \App\Models\ClientDashboard $dashboard,
        array $buckets,
        bool $replaceValues = false,
    ): int {
        if ($buckets === []) {
            return 0;
        }

        $currency = config('titan.currency', 'USD');
        $timestamp = now();
        $rows = [];

        foreach ($buckets as $bucket) {
            if ($bucket['value'] == 0) {
                continue;
            }

            $rows[] = [
                'client_dashboard_id' => $dashboard->id,
                'snapshot_date' => $bucket['date']->toDateString(),
                'metric_key' => $bucket['key'],
                'dimension_hash' => MetricDimensions::hash($bucket['dimensions']),
                'metric_value' => $bucket['value'],
                'currency' => $currency,
                'dimensions' => $bucket['dimensions'] === null
                    ? null
                    : json_encode($bucket['dimensions'], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $metricValueExpression = match (DB::connection()->getDriverName()) {
            'pgsql' => $replaceValues
                ? 'EXCLUDED.metric_value'
                : 'metric_snapshots.metric_value + EXCLUDED.metric_value',
            default => $replaceValues
                ? 'excluded.metric_value'
                : 'metric_value + excluded.metric_value',
        };

        foreach (array_chunk($rows, 500) as $chunk) {
            MetricSnapshot::query()->upsert(
                $chunk,
                ['client_dashboard_id', 'snapshot_date', 'metric_key', 'dimension_hash'],
                [
                    'metric_value' => DB::raw($metricValueExpression),
                    'updated_at' => $timestamp,
                ],
            );
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{date: Carbon, key: string, value: float, dimensions?: array<string, mixed>}>
     */
    protected function extractMetrics(string $resourceType, array $payload, int $connectionId): array
    {
        return match ($resourceType) {
            'order' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'orders',
                'value' => 1,
                'dimensions' => $this->orderDimensions($payload, $connectionId),
            ], [
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'revenue',
                'value' => (float) ($payload['total'] ?? 0),
                'dimensions' => $this->orderDimensions($payload, $connectionId),
            ]],
            'order_line_item' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'units_sold',
                'value' => (float) ($payload['quantity'] ?? 0),
                'dimensions' => $this->lineItemDimensions($payload, $connectionId),
            ], [
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'line_revenue',
                'value' => (float) ($payload['line_total'] ?? 0),
                'dimensions' => $this->lineItemDimensions($payload, $connectionId),
            ]],
            'ad_spend' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'ad_spend',
                'value' => (float) ($payload['cost'] ?? 0),
                'dimensions' => [
                    'connection_id' => $connectionId,
                    'source' => $payload['source'] ?? 'unknown',
                    'campaign' => $payload['campaign'] ?? null,
                ],
            ]],
            'keyword' => [
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'keyword_rank',
                    'value' => (float) ($payload['position'] ?? 0),
                    'dimensions' => [
                        'keyword' => $payload['keyword'] ?? null,
                    ],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_clicks',
                    'value' => (float) ($payload['clicks'] ?? 0),
                    'dimensions' => [
                        'keyword' => $payload['keyword'] ?? null,
                    ],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_impressions',
                    'value' => (float) ($payload['impressions'] ?? 0),
                    'dimensions' => [
                        'keyword' => $payload['keyword'] ?? null,
                    ],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_ctr',
                    'value' => (float) ($payload['ctr'] ?? 0),
                    'dimensions' => [
                        'keyword' => $payload['keyword'] ?? null,
                    ],
                ],
            ],
            'search_daily' => [
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_clicks',
                    'value' => (float) ($payload['clicks'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_impressions',
                    'value' => (float) ($payload['impressions'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_ctr',
                    'value' => (float) ($payload['ctr'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_avg_position',
                    'value' => (float) ($payload['position'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
            ],
            'search_page' => [
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_page_clicks',
                    'value' => (float) ($payload['clicks'] ?? 0),
                    'dimensions' => [
                        'connection_id' => $connectionId,
                        'page' => $payload['page'] ?? null,
                    ],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'search_page_impressions',
                    'value' => (float) ($payload['impressions'] ?? 0),
                    'dimensions' => [
                        'connection_id' => $connectionId,
                        'page' => $payload['page'] ?? null,
                    ],
                ],
            ],
            'organic_traffic' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'organic_sessions',
                'value' => (float) ($payload['sessions'] ?? 0),
                'dimensions' => [
                    'source' => $payload['source'] ?? 'google_analytics',
                    'landing_page' => $payload['landing_page'] ?? null,
                ],
            ]],
            'traffic_daily' => [
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'visitors',
                    'value' => (float) ($payload['visitors'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'active_users',
                    'value' => (float) ($payload['active_users'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'sessions',
                    'value' => (float) ($payload['sessions'] ?? 0),
                    'dimensions' => ['connection_id' => $connectionId],
                ],
            ],
            'traffic_channel' => [
                [
                    'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                    'key' => 'sessions',
                    'value' => (float) ($payload['sessions'] ?? 0),
                    'dimensions' => [
                        'connection_id' => $connectionId,
                        'channel' => $payload['channel'] ?? null,
                    ],
                ],
            ],
            'events_daily' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'event_count',
                'value' => (float) ($payload['event_count'] ?? 0),
                'dimensions' => [
                    'connection_id' => $connectionId,
                    'event_name' => $payload['event_name'] ?? null,
                ],
            ]],
            'landing_page' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'landing_page_sessions',
                'value' => (float) ($payload['sessions'] ?? 0),
                'dimensions' => [
                    'connection_id' => $connectionId,
                    'landing_page' => $payload['landing_page'] ?? null,
                ],
            ]],
            'spend_daily' => $this->adsMetricsFromPayload($payload, $connectionId),
            'campaign_daily' => $this->adsMetricsFromPayload($payload, $connectionId, [
                'campaign_id' => $payload['campaign_id'] ?? null,
                'campaign_name' => $payload['campaign_name'] ?? null,
                'channel_type' => $payload['channel_type'] ?? null,
            ]),
            'channel_daily' => $this->adsMetricsFromPayload($payload, $connectionId, [
                'channel_type' => $payload['channel_type'] ?? null,
            ]),
            'insight_geo_daily' => $this->insightMetricsFromPayload($payload, $connectionId, $resourceType),
            'insight_domain_daily' => $this->insightMetricsFromPayload($payload, $connectionId, $resourceType),
            'insight_device_daily' => $this->insightMetricsFromPayload($payload, $connectionId, $resourceType),
            'session_attribution' => [[
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'sessions',
                'value' => (float) ($payload['sessions'] ?? 0),
                'dimensions' => $this->sessionDimensions($payload, $connectionId),
            ], [
                'date' => Carbon::parse($payload['date'] ?? now()->toDateString()),
                'key' => 'visitors',
                'value' => (float) ($payload['visitors'] ?? 0),
                'dimensions' => $this->sessionDimensions($payload, $connectionId),
            ]],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function orderDimensions(array $payload, int $connectionId): array
    {
        return array_filter([
            'connection_id' => $connectionId,
            'source' => $payload['source'] ?? $payload['source_name'] ?? null,
            'medium' => $payload['medium'] ?? null,
            'channel' => $payload['channel'] ?? null,
            'referring_site' => $payload['referring_site'] ?? null,
            'landing_site' => $payload['landing_site'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function lineItemDimensions(array $payload, int $connectionId): array
    {
        return array_filter([
            'connection_id' => $connectionId,
            'sku' => $payload['sku'] ?? null,
            'name' => $payload['name'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'medium' => $payload['medium'] ?? null,
            'channel' => $payload['channel'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sessionDimensions(array $payload, int $connectionId): array
    {
        return array_filter([
            'connection_id' => $connectionId,
            'source' => $payload['source'] ?? null,
            'medium' => $payload['medium'] ?? null,
            'source_medium' => $payload['source_medium'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extraDimensions
     * @return list<array{date: Carbon, key: string, value: float, dimensions: array<string, mixed>}>
     */
    protected function adsMetricsFromPayload(array $payload, int $connectionId, array $extraDimensions = []): array
    {
        $date = Carbon::parse($payload['date'] ?? now()->toDateString());
        $dimensions = array_filter([
            'connection_id' => $connectionId,
            ...$extraDimensions,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            [
                'date' => $date,
                'key' => 'ad_spend',
                'value' => (float) ($payload['cost'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_impressions',
                'value' => (float) ($payload['impressions'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_clicks',
                'value' => (float) ($payload['clicks'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_ctr',
                'value' => (float) ($payload['ctr'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_conversions',
                'value' => (float) ($payload['conversions'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_conversions_value',
                'value' => (float) ($payload['conversions_value'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_roas',
                'value' => (float) ($payload['roas'] ?? 0),
                'dimensions' => $dimensions,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{date: Carbon, key: string, value: float, dimensions: array<string, mixed>}>
     */
    protected function insightMetricsFromPayload(array $payload, int $connectionId, string $resourceType): array
    {
        $date = Carbon::parse($payload['date'] ?? now()->toDateString());
        $dimensions = array_filter([
            'connection_id' => $connectionId,
            'insight_type' => str_replace('_daily', '', str_replace('insight_', '', $resourceType)),
            'dimension_key' => $payload['dimension_key'] ?? null,
            'dimension_label' => $payload['dimension_label'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            [
                'date' => $date,
                'key' => 'ad_spend',
                'value' => (float) ($payload['cost'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_impressions',
                'value' => (float) ($payload['impressions'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_clicks',
                'value' => (float) ($payload['clicks'] ?? 0),
                'dimensions' => $dimensions,
            ],
            [
                'date' => $date,
                'key' => 'ads_conversions',
                'value' => (float) ($payload['conversions'] ?? 0),
                'dimensions' => $dimensions,
            ],
        ];
    }
}
