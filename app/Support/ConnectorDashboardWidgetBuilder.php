<?php

namespace App\Support;

use App\Enums\ReportVisualizationType;
use App\Models\ConnectorBlueprint;
use App\Models\RawConnectorPayload;
use Illuminate\Support\Facades\DB;

class ConnectorDashboardWidgetBuilder
{
    /**
     * @return list<string>
     */
    public function payloadFields(ConnectorBlueprint $blueprint, ?int $connectionId = null): array
    {
        $blueprint->loadMissing('streams');
        $stream = $blueprint->streams->where('enabled', true)->sortBy('id')->first();

        if ($stream === null) {
            return [];
        }

        $mapping = $stream->response_mapping ?? [];
        $transform = $blueprint->transform_config[$stream->resource_type] ?? [];
        $fields = BlueprintAnalyticsSchema::forBlueprint($blueprint, $connectionId)['streams'][0]['payload_fields'] ?? [];

        if ($fields !== []) {
            return $fields;
        }

        if ($connectionId === null) {
            return [];
        }

        $payload = RawConnectorPayload::query()
            ->where('connection_id', $connectionId)
            ->where('resource_type', $stream->resource_type)
            ->orderByDesc('fetched_at')
            ->value('payload');

        if (! is_array($payload)) {
            return [];
        }

        return $this->flattenPayloadKeys($payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultWidgets(ConnectorBlueprint $blueprint, int $connectionId): array
    {
        $blueprint->loadMissing('streams');
        $stream = $blueprint->streams->where('enabled', true)->sortBy('id')->first();

        if ($stream === null) {
            return [];
        }

        $resourceType = $stream->resource_type;
        $mapping = $stream->response_mapping ?? [];
        $fields = $this->payloadFields($blueprint, $connectionId);
        $amountField = $this->guessAmountField($fields, $mapping, $blueprint, $resourceType);
        $dateField = $this->guessDateField($fields, $mapping);
        $amountSql = JsonPayloadSql::real('r.payload', $amountField);
        $dateSql = JsonPayloadSql::text('r.payload', $dateField);
        $scope = $this->scopeSql($resourceType, $connectionId);

        $widgets = [
            [
                'prompt' => 'Total Sales Overview',
                'sql' => "SELECT COALESCE(SUM({$amountSql}), 0) AS total_sales {$scope}",
                'visualization_type' => 'stat_card',
            ],
            [
                'prompt' => 'Sales by Month',
                'sql' => $this->monthlyTrendSql($dateSql, $amountSql, $scope),
                'visualization_type' => 'line_chart',
            ],
        ];

        if (DB::connection()->getDriverName() === 'pgsql' && $this->supportsLineItemProducts($fields)) {
            $widgets[] = [
                'prompt' => 'Top Selling Products',
                'sql' => $this->topProductsSql($resourceType, $connectionId),
                'visualization_type' => 'table',
            ];
        } else {
            $widgets[] = [
                'prompt' => 'Orders by Status',
                'sql' => "SELECT COALESCE({$this->statusSql($fields)}, 'unknown') AS status, COUNT(*) AS order_count {$scope} GROUP BY 1 ORDER BY order_count DESC LIMIT 10",
                'visualization_type' => 'table',
            ];
        }

        return $widgets;
    }

    /**
     * @param  array<string, mixed>  $widget
     * @param  array{rows: list<array<string, mixed>>, columns: list<string>}  $queryResult
     * @return array<string, mixed>
     */
    public function visualizationConfig(
        ReportVisualizationType $type,
        array $widget,
        array $queryResult,
    ): array {
        $existing = is_array($widget['visualization_config'] ?? null)
            ? $widget['visualization_config']
            : [];
        $columns = $queryResult['columns'] ?? [];
        $prompt = (string) ($widget['prompt'] ?? 'Metric');

        return match ($type) {
            ReportVisualizationType::StatCard => array_filter([
                'header' => $existing['header'] ?? $prompt,
                'value_column' => $existing['value_column'] ?? $columns[0] ?? 'value',
                'format' => $existing['format'] ?? $this->guessFormat($columns[0] ?? '', $prompt),
                'tooltip' => $existing['tooltip'] ?? null,
            ]),
            ReportVisualizationType::LineChart => array_filter([
                'title' => $existing['title'] ?? $prompt,
                'date_column' => $existing['date_column'] ?? $this->guessDateColumn($columns),
                'value_column' => $existing['value_column'] ?? $this->guessValueColumn($columns),
                'format' => $existing['format'] ?? 'currency',
                'series_label' => $existing['series_label'] ?? $prompt,
            ]),
            ReportVisualizationType::Table => array_filter([
                'title' => $existing['title'] ?? $prompt,
                'columns' => $existing['columns'] ?? null,
            ]),
        };
    }

    protected function scopeSql(string $resourceType, int $connectionId): string
    {
        return "FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = '{$resourceType}' AND r.connection_id = :connection_id";
    }

    protected function monthlyTrendSql(string $dateSql, string $amountSql, string $scope): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "SELECT DATE_TRUNC('month', ({$dateSql})::timestamp)::date AS month, COALESCE(SUM({$amountSql}), 0) AS total_sales {$scope} AND ({$dateSql})::timestamp BETWEEN :start_date::timestamp AND :end_date::timestamp GROUP BY 1 ORDER BY 1";
        }

        return "SELECT substr({$dateSql}, 1, 7) AS month, COALESCE(SUM({$amountSql}), 0) AS total_sales {$scope} AND {$dateSql} BETWEEN :start_date AND :end_date GROUP BY 1 ORDER BY 1";
    }

    protected function topProductsSql(string $resourceType, int $connectionId): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return <<<SQL
SELECT COALESCE(line_item->>'productId', line_item->>'product_id', line_item->>'sku', 'unknown') AS product_id,
       COUNT(*) AS sold_count
FROM raw_connector_payloads r
JOIN connections c ON c.id = r.connection_id
CROSS JOIN LATERAL jsonb_array_elements(
    CASE
        WHEN jsonb_typeof(r.payload->'lineItems') = 'array' THEN r.payload->'lineItems'
        WHEN jsonb_typeof(r.payload->'items') = 'array' THEN r.payload->'items'
        WHEN jsonb_typeof(r.payload->'line_items') = 'array' THEN r.payload->'line_items'
        ELSE '[]'::jsonb
    END
) AS line_item
WHERE c.client_dashboard_id = :dashboard_id
  AND r.resource_type = '{$resourceType}'
  AND r.connection_id = :connection_id
GROUP BY 1
ORDER BY sold_count DESC
LIMIT 10
SQL;
        }

        return <<<SQL
SELECT COALESCE(json_extract(line_item.value, '$.productId'), json_extract(line_item.value, '$.product_id'), json_extract(line_item.value, '$.sku'), 'unknown') AS product_id,
       COUNT(*) AS sold_count
FROM raw_connector_payloads r
JOIN connections c ON c.id = r.connection_id
JOIN json_each(
    CASE
        WHEN json_type(r.payload, '$.lineItems') = 'array' THEN r.payload->'lineItems'
        WHEN json_type(r.payload, '$.items') = 'array' THEN r.payload->'items'
        WHEN json_type(r.payload, '$.line_items') = 'array' THEN r.payload->'line_items'
        ELSE '[]'
    END
) AS line_item
WHERE c.client_dashboard_id = :dashboard_id
  AND r.resource_type = '{$resourceType}'
  AND r.connection_id = :connection_id
GROUP BY 1
ORDER BY sold_count DESC
LIMIT 10
SQL;
    }

    /**
     * @param  list<string>  $fields
     */
    protected function supportsLineItemProducts(array $fields): bool
    {
        return count(array_intersect($fields, ['lineItems', 'items', 'line_items'])) > 0
            || in_array('product_id', $fields, true);
    }

    /**
     * @param  list<string>  $fields
     */
    protected function statusSql(array $fields): string
    {
        foreach (['status', 'stateMachineState.technicalName', 'order_status'] as $candidate) {
            if (in_array($candidate, $fields, true) || str_contains(implode(',', $fields), 'status')) {
                return JsonPayloadSql::text('r.payload', $candidate);
            }
        }

        return JsonPayloadSql::text('r.payload', 'status');
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $mapping
     */
    protected function guessAmountField(
        array $fields,
        array $mapping,
        ConnectorBlueprint $blueprint,
        string $resourceType,
    ): string {
        foreach (['total', 'grand_total', 'amountTotal', 'totalOrderPrice', 'total_price', 'total_sales'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }

        $metricKey = $blueprint->transform_config[$resourceType]['metrics'][0]['key'] ?? null;
        $metricPath = $blueprint->transform_config[$resourceType]['metrics'][0]['value_path'] ?? null;

        if (is_string($metricPath) && $metricPath !== '') {
            return $metricPath;
        }

        if (is_string($metricKey) && $metricKey !== '') {
            return $metricKey;
        }

        return 'total';
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $mapping
     */
    protected function guessDateField(array $fields, array $mapping): string
    {
        $mapped = (string) ($mapping['date_path'] ?? '');

        if ($mapped !== '' && ($fields === [] || in_array($mapped, $fields, true))) {
            return $mapped;
        }

        foreach (['orderDateTime', 'date_created', 'created_at', 'orderdate', 'date', 'order_date'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }

        return 'date';
    }

    /**
     * @param  list<string>  $columns
     */
    protected function guessDateColumn(array $columns): string
    {
        foreach (['month', 'day', 'date', 'week'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $columns[0] ?? 'date';
    }

    /**
     * @param  list<string>  $columns
     */
    protected function guessValueColumn(array $columns): string
    {
        foreach (['total_sales', 'total', 'revenue', 'value', 'order_count', 'sold_count'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return $columns[1] ?? ($columns[0] ?? 'value');
    }

    protected function guessFormat(string $column, string $prompt): string
    {
        $haystack = strtolower($column.' '.$prompt);

        if (str_contains($haystack, 'sales') || str_contains($haystack, 'revenue') || str_contains($haystack, 'total')) {
            return 'currency';
        }

        return 'number';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function flattenPayloadKeys(array $payload, string $prefix = ''): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $keys[] = $path;

            if (is_array($value) && array_is_list($value)) {
                if (isset($value[0]) && is_array($value[0])) {
                    $keys = array_merge($keys, $this->flattenPayloadKeys($value[0], $path.'[]'));
                }

                continue;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenPayloadKeys($value, $path));
            }
        }

        return array_values(array_unique($keys));
    }
}
