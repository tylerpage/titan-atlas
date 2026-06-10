<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Support\SyncDateChunkWalker;
use App\Ingestion\Connectors\StackAdapt\StackAdaptGraphqlClient;
use App\Ingestion\Connectors\StackAdapt\StackAdaptRestClient;
use App\Models\Connection;
use Carbon\Carbon;

class StackAdaptConnector extends AbstractConnector implements FanOutSyncConnector
{
    use WalksSyncDateChunks;

    /** @var list<string> */
    protected array $streams = [
        'spend_daily',
        'campaign_daily',
        'channel_daily',
        'insight_geo_daily',
        'insight_domain_daily',
        'insight_device_daily',
    ];

    public function __construct(
        protected StackAdaptGraphqlClient $graphql,
        protected StackAdaptRestClient $rest,
    ) {}

    public function type(): string
    {
        return ConnectorType::StackAdapt->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $apiKey = trim((string) ($credentials['graphql_api_key'] ?? ''));

        if ($apiKey === '') {
            return ValidationResult::fail('StackAdapt requires a GraphQL API key.');
        }

        $advertiserId = trim((string) ($credentials['advertiser_id'] ?? ''));
        $debug = ['token_length' => strlen($apiKey)];

        try {
            $advertisers = $this->graphql->listAdvertisers($apiKey);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not authenticate with StackAdapt.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $debug['advertisers'] = $advertisers;

        if ($advertiserId === '') {
            return ValidationResult::ok(
                'StackAdapt API key is valid. Select an advertiser to finish setup.',
                $debug,
            );
        }

        try {
            $debug = array_merge($debug, $this->graphql->testAdvertiserAccess($apiKey, $advertiserId));
        } catch (\Throwable $e) {
            if ($this->rest->isEnabled() && ! empty($credentials['rest_api_key'])) {
                try {
                    $end = now()->subDays(max(0, (int) config('titan.stackadapt.data_lag_days', 1)))->toDateString();
                    $rows = $this->rest->deliveryStats(
                        (string) $credentials['rest_api_key'],
                        $advertiserId,
                        now()->subDays(7)->toDateString(),
                        $end,
                    );

                    if ($rows === []) {
                        throw new \RuntimeException('REST fallback returned no delivery rows for the selected advertiser.');
                    }

                    $debug['delivery_record_count'] = count($rows);
                    $debug['delivery_source'] = 'rest_fallback';
                } catch (\Throwable $restError) {
                    return ValidationResult::fail(
                        $this->advertiserValidationMessage($restError->getMessage()),
                        array_merge($debug, [
                            'hint' => $restError->getMessage(),
                            'graphql_hint' => $e->getMessage(),
                        ]),
                    );
                }
            } else {
                return ValidationResult::fail(
                    $this->advertiserValidationMessage($e->getMessage()),
                    array_merge($debug, ['hint' => $e->getMessage()]),
                );
            }
        }

        $label = collect($advertisers)->firstWhere('advertiserId', $advertiserId)['displayName'] ?? $advertiserId;

        $message = 'Connected to StackAdapt advertiser '.$label;

        if (($debug['delivery_record_count'] ?? 0) === 0) {
            $message .= ' (no delivery rows in the test window; backfill may still return historical data).';
        }

        return ValidationResult::ok(
            $message,
            array_merge($debug, ['display_name' => $label]),
        );
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $apiKey = trim((string) ($credentials['graphql_api_key'] ?? ''));
        $advertiserId = trim((string) ($credentials['advertiser_id'] ?? ''));

        if ($apiKey === '' || $advertiserId === '') {
            return new FetchResult(records: [], hasMore: false);
        }

        $state = $this->decodeCursor($cursor, $connection);
        $chunkDays = max(1, (int) config('titan.stackadapt.chunk_days', 1));
        [$chunkStart, $chunkEnd] = SyncDateChunkWalker::currentChunkBounds($state, $chunkDays);
        [$progressFrom, $progressThrough] = $this->chunkProgressDates($state, $chunkDays);

        $fromDate = $chunkStart->toDateString();
        $toDate = $this->graphql->exclusiveEndDate($chunkEnd->toDateString());

        $nextPageCursor = null;
        $records = match ($state['stream']) {
            'spend_daily' => $this->fetchSpendDaily($apiKey, $advertiserId, $fromDate, $toDate),
            'campaign_daily' => $this->fetchCampaignDaily($apiKey, $advertiserId, $fromDate, $toDate, $state['page_cursor'] ?? null, $nextPageCursor),
            'channel_daily' => $this->fetchChannelDaily($apiKey, $advertiserId, $fromDate, $toDate, $state['page_cursor'] ?? null, $nextPageCursor),
            'insight_geo_daily' => $this->fetchInsightDaily($apiKey, $advertiserId, $fromDate, $toDate, 'insight_geo_daily', ['COUNTRY', 'REGION', 'DATE'], $state['page_cursor'] ?? null, $nextPageCursor),
            'insight_domain_daily' => $this->fetchInsightDaily($apiKey, $advertiserId, $fromDate, $toDate, 'insight_domain_daily', ['APP', 'DATE'], $state['page_cursor'] ?? null, $nextPageCursor),
            'insight_device_daily' => $this->fetchInsightDaily($apiKey, $advertiserId, $fromDate, $toDate, 'insight_device_daily', ['DEVICE_TYPE', 'DATE'], $state['page_cursor'] ?? null, $nextPageCursor),
            default => [],
        };

        if (is_string($nextPageCursor) && $nextPageCursor !== '') {
            $nextState = $state;
            $nextState['page_cursor'] = $nextPageCursor;

            return $this->result($records, $this->encodeCursor($nextState), true, $progressFrom, $progressThrough);
        }

        $nextState = SyncDateChunkWalker::nextDateChunkState($state, $chunkDays);

        if ($nextState !== null) {
            unset($nextState['page_cursor']);

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
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function fetchSpendDaily(string $apiKey, string $advertiserId, string $fromDate, string $toDate): array
    {
        $rows = $this->graphql->advertiserDeliveryRecords($apiKey, $advertiserId, $fromDate, $toDate);

        return $this->normalizeAdvertiserRows($rows);
    }

    /**
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function fetchCampaignDaily(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        ?string $after,
        ?string &$nextPageCursor,
    ): array {
        $page = $this->graphql->campaignDeliveryPage($apiKey, $advertiserId, $fromDate, $toDate, $after);
        $nextPageCursor = $page['next_cursor'];

        return $this->normalizeCampaignRows($page['records']);
    }

    /**
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function fetchChannelDaily(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        ?string $after,
        ?string &$nextPageCursor,
    ): array {
        $rows = [];
        $cursor = $after;

        do {
            $page = $this->graphql->campaignDeliveryPage($apiKey, $advertiserId, $fromDate, $toDate, $cursor);
            $rows = array_merge($rows, $page['records']);
            $cursor = $page['next_cursor'];
        } while (is_string($cursor) && $cursor !== '');

        $nextPageCursor = null;

        return $this->normalizeChannelRows($rows);
    }

    /**
     * @param  list<string>  $attributes
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function fetchInsightDaily(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        string $stream,
        array $attributes,
        ?string $after,
        ?string &$nextPageCursor,
    ): array {
        $page = $this->graphql->campaignInsightPage($apiKey, $advertiserId, $fromDate, $toDate, $attributes, $after);
        $nextPageCursor = $page['next_cursor'];

        return $this->normalizeInsightRows($page['records'], $stream);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function normalizeAdvertiserRows(array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $payload = $this->metricsPayload($row);

            if ($payload === null) {
                continue;
            }

            $records[] = [
                'resource_type' => 'spend_daily',
                'external_id' => $payload['date'],
                'payload' => $payload,
            ];
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function normalizeCampaignRows(array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $payload = $this->metricsPayload($row);
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $campaignId = (string) ($campaign['id'] ?? '');

            if ($payload === null || $campaignId === '') {
                continue;
            }

            $group = is_array($campaign['campaignGroup'] ?? null) ? $campaign['campaignGroup'] : [];
            $payload['campaign_id'] = $campaignId;
            $payload['campaign_name'] = (string) ($campaign['name'] ?? '');
            $payload['campaign_group_id'] = (string) ($group['id'] ?? '');
            $payload['campaign_group_name'] = (string) ($group['name'] ?? '');
            $payload['channel_type'] = (string) ($campaign['channelType'] ?? '');

            $records[] = [
                'resource_type' => 'campaign_daily',
                'external_id' => $payload['date'].':'.$campaignId,
                'payload' => $payload,
            ];
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function normalizeChannelRows(array $rows): array
    {
        $aggregated = [];

        foreach ($rows as $row) {
            $payload = $this->metricsPayload($row);
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $channelType = (string) ($campaign['channelType'] ?? 'Unknown');

            if ($payload === null) {
                continue;
            }

            $key = $payload['date'].'|'.$channelType;

            if (! isset($aggregated[$key])) {
                $aggregated[$key] = array_merge($payload, [
                    'channel_type' => $channelType,
                ]);

                continue;
            }

            $this->addMetrics($aggregated[$key], $payload);
        }

        $records = [];

        foreach ($aggregated as $payload) {
            $this->finalizeDerivedMetrics($payload);

            $records[] = [
                'resource_type' => 'channel_daily',
                'external_id' => $payload['date'].':'.$payload['channel_type'],
                'payload' => $payload,
            ];
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function normalizeInsightRows(array $rows, string $stream): array
    {
        $records = [];

        foreach ($rows as $row) {
            $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $date = (string) ($attributes['date'] ?? '');

            if ($date === '') {
                continue;
            }

            $dimensionKey = match ($stream) {
                'insight_geo_daily' => (string) (($attributes['country']['code'] ?? '') ?: ($attributes['region']['code'] ?? 'Unknown')),
                'insight_domain_daily' => (string) (($attributes['app']['domain'] ?? '') ?: 'Unknown'),
                'insight_device_daily' => (string) ($attributes['deviceType'] ?? 'Unknown'),
                default => 'Unknown',
            };

            $dimensionLabel = match ($stream) {
                'insight_geo_daily' => trim(
                    (string) (($attributes['country']['name'] ?? '') ?: ($attributes['region']['name'] ?? $dimensionKey))
                ),
                'insight_domain_daily' => (string) (($attributes['app']['title'] ?? '') ?: ($attributes['app']['domain'] ?? $dimensionKey)),
                'insight_device_daily' => $dimensionKey,
                default => $dimensionKey,
            };

            $payload = [
                'date' => $date,
                'dimension_key' => $dimensionKey,
                'dimension_label' => $dimensionLabel !== '' ? $dimensionLabel : $dimensionKey,
                'cost' => $this->moneyValue($metrics['cost'] ?? 0),
                'impressions' => $this->bigintValue($metrics['impressionsBigint'] ?? $metrics['impressions'] ?? 0),
                'clicks' => $this->bigintValue($metrics['clicksBigint'] ?? $metrics['clicks'] ?? 0),
                'ctr' => (float) ($metrics['ctr'] ?? 0),
                'conversions' => $this->bigintValue($metrics['conversionsBigint'] ?? $metrics['conversions'] ?? 0),
                'conversions_value' => $this->moneyValue($metrics['conversionRevenue'] ?? 0),
                'roas' => (float) ($metrics['roas'] ?? 0),
            ];

            $records[] = [
                'resource_type' => $stream,
                'external_id' => $date.':'.$dimensionKey,
                'payload' => $payload,
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function metricsPayload(array $row): ?array
    {
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        $granularity = is_array($row['granularity'] ?? null) ? $row['granularity'] : [];
        $date = (string) ($granularity['time'] ?? '');

        if ($date === '' && isset($granularity['startTime'])) {
            $date = Carbon::parse((string) $granularity['startTime'])->toDateString();
        }

        if ($date === '') {
            return null;
        }

        $payload = [
            'date' => $date,
            'cost' => $this->moneyValue($metrics['cost'] ?? 0),
            'impressions' => $this->bigintValue($metrics['impressionsBigint'] ?? $metrics['impressions'] ?? 0),
            'clicks' => $this->bigintValue($metrics['clicksBigint'] ?? $metrics['clicks'] ?? 0),
            'ctr' => (float) ($metrics['ctr'] ?? 0),
            'conversions' => $this->bigintValue($metrics['conversionsBigint'] ?? $metrics['conversions'] ?? 0),
            'conversions_value' => $this->moneyValue($metrics['conversionRevenue'] ?? 0),
            'roas' => (float) ($metrics['roas'] ?? 0),
            'secondary_conversions' => $this->bigintValue($metrics['secondaryConversionsBigint'] ?? $metrics['secondaryConversions'] ?? 0),
            'engagements' => $this->bigintValue($metrics['engagements'] ?? 0),
            'video_starts' => $this->bigintValue($metrics['videoStarts'] ?? 0),
            'video_completions' => $this->bigintValue($metrics['videoCompletions'] ?? 0),
            'audio_starts' => $this->bigintValue($metrics['audioStarts'] ?? 0),
            'audio_completions' => $this->bigintValue($metrics['audioCompletions'] ?? 0),
        ];

        $this->finalizeDerivedMetrics($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function zeroMetrics(array &$payload): void
    {
        foreach (['cost', 'impressions', 'clicks', 'conversions', 'conversions_value', 'secondary_conversions', 'engagements', 'video_starts', 'video_completions', 'audio_starts', 'audio_completions'] as $key) {
            $payload[$key] = 0;
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $source
     */
    protected function addMetrics(array &$target, array $source): void
    {
        foreach (['cost', 'impressions', 'clicks', 'conversions', 'conversions_value', 'secondary_conversions', 'engagements', 'video_starts', 'video_completions', 'audio_starts', 'audio_completions'] as $key) {
            $target[$key] = (float) ($target[$key] ?? 0) + (float) ($source[$key] ?? 0);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function finalizeDerivedMetrics(array &$payload): void
    {
        $impressions = (float) ($payload['impressions'] ?? 0);
        $clicks = (float) ($payload['clicks'] ?? 0);
        $cost = (float) ($payload['cost'] ?? 0);
        $conversionsValue = (float) ($payload['conversions_value'] ?? 0);

        if ($impressions > 0) {
            $payload['ctr'] = round(($clicks / $impressions) * 100, 4);
        }

        if ($cost > 0 && ($payload['roas'] ?? 0) == 0.0) {
            $payload['roas'] = round($conversionsValue / $cost, 4);
        }
    }

    protected function moneyValue(mixed $value): float
    {
        if (is_array($value)) {
            return round((float) ($value['amount'] ?? $value['value'] ?? 0), 2);
        }

        return round((float) $value, 2);
    }

    protected function bigintValue(mixed $value): float
    {
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return (float) $value;
    }

    /**
     * @return array{stream: string, start_date: string, end_date: string, page_cursor?: string|null}
     */
    protected function decodeCursor(?string $cursor, Connection $connection): array
    {
        if ($cursor !== null && str_starts_with($cursor, 'sa:')) {
            $decoded = json_decode(substr($cursor, 3), true);

            if (is_array($decoded) && isset($decoded['stream'])) {
                return SyncDateChunkWalker::mergeDecodedState([
                    'stream' => (string) $decoded['stream'],
                    'start_date' => (string) ($decoded['start_date'] ?? ''),
                    'end_date' => (string) ($decoded['end_date'] ?? ''),
                    'range_start' => (string) ($decoded['range_start'] ?? ''),
                    'range_end' => (string) ($decoded['range_end'] ?? ''),
                    'chunk_end' => (string) ($decoded['chunk_end'] ?? ''),
                    'walk' => (string) ($decoded['walk'] ?? ''),
                    'page_cursor' => $decoded['page_cursor'] ?? null,
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
     * @param  array{stream: string, start_date: string, end_date: string, page_cursor?: string|null}  $state
     */
    protected function encodeCursor(array $state): string
    {
        return 'sa:'.json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Connection $connection): array
    {
        $lagDays = max(0, (int) config('titan.stackadapt.data_lag_days', 1));
        $end = now()->subDays($lagDays)->startOfDay();
        $months = max(1, (int) config('titan.stackadapt.backfill_months', 16));
        $earliest = now()->subMonths($months)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return [$earliest, $end];
        }

        $incrementalDays = max(1, (int) config('titan.stackadapt.incremental_days', 5));
        $start = $connection->last_synced_at
            ? $connection->last_synced_at->copy()->subDays($incrementalDays)->startOfDay()
            : $earliest;

        if ($start->lt($earliest)) {
            $start = $earliest;
        }

        return [$start, $end];
    }

    protected function nextStream(string $stream): ?string
    {
        $index = array_search($stream, $this->streams, true);

        if ($index === false) {
            return null;
        }

        return $this->streams[$index + 1] ?? null;
    }

    protected function advertiserValidationMessage(string $hint): string
    {
        return 'Could not query the selected StackAdapt advertiser. '.$hint;
    }

}
