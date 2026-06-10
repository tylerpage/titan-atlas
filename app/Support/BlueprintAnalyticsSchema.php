<?php

namespace App\Support;

use App\Models\ConnectorBlueprint;

class BlueprintAnalyticsSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function forBlueprint(ConnectorBlueprint $blueprint, ?int $connectionId = null): array
    {
        $blueprint->loadMissing('streams');

        $streams = $blueprint->streams
            ->where('enabled', true)
            ->map(function ($stream) use ($blueprint) {
                $transform = $blueprint->transform_config[$stream->resource_type] ?? [];
                $mapping = $stream->response_mapping ?? [];

                return [
                    'stream_key' => $stream->stream_key,
                    'resource_type' => $stream->resource_type,
                    'payload_fields' => self::payloadFields($mapping, $transform),
                    'date_field' => $mapping['date_path'] ?? 'date',
                    'id_field' => $mapping['id_path'] ?? 'id',
                ];
            })
            ->values()
            ->all();

        $primary = $streams[0] ?? null;
        $resourceType = (string) ($primary['resource_type'] ?? 'resource');
        $dateField = (string) ($primary['date_field'] ?? 'date');
        $amountField = self::guessAmountField($primary['payload_fields'] ?? []);

        return [
            'blueprint_slug' => $blueprint->slug,
            'connection_id' => $connectionId,
            'streams' => $streams,
            'placeholders' => [
                ':dashboard_id' => 'Required. Filter to this dashboard via connections.client_dashboard_id.',
                ':start_date' => 'Inclusive start date (YYYY-MM-DD).',
                ':end_date' => 'Inclusive end date (YYYY-MM-DD).',
                ':connection_id' => 'Optional. Scope to the connector connection when provided.',
            ],
            'json_hint' => JsonPayloadSql::promptHint(),
            'valid_visualization_types' => ['stat_card', 'line_chart', 'table'],
            'sql_templates' => [
                'total_count' => self::totalCountSql($resourceType),
                'daily_trend' => self::dailyTrendSql($resourceType, $dateField, $amountField),
            ],
            'notes' => [
                'Use each stream resource_type exactly as configured — do not invent names like shopware_order.',
                'Widgets may return zero rows before backfill completes; SQL must still be structurally valid.',
                'After CreateDynamicConnectionTool, call ProposeConnectorDashboardTool to create reports and a saved dashboard board.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $transform
     * @return list<string>
     */
    protected static function payloadFields(array $mapping, array $transform): array
    {
        $fields = [];

        foreach ($mapping['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['target'])) {
                $fields[] = (string) $field['target'];
            } elseif (is_string($field)) {
                $fields[] = $field;
            }
        }

        foreach ($transform['metrics'] ?? [] as $metric) {
            if (is_array($metric) && isset($metric['key'])) {
                $fields[] = (string) $metric['key'];
            }
        }

        return array_values(array_unique(array_filter($fields)));
    }

    /**
     * @param  list<string>  $fields
     */
    protected static function guessAmountField(array $fields): string
    {
        foreach (['total', 'total_price', 'amount', 'revenue', 'price'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }

        return 'total';
    }

    protected static function totalCountSql(string $resourceType): string
    {
        return "SELECT COUNT(*) AS total_count FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = '{$resourceType}' AND r.connection_id = :connection_id";
    }

    protected static function dailyTrendSql(string $resourceType, string $dateField, string $amountField): string
    {
        $date = JsonPayloadSql::text('r.payload', $dateField);
        $amount = JsonPayloadSql::real('r.payload', $amountField);

        return "SELECT {$date} AS day, COALESCE(SUM({$amount}), 0) AS total FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = '{$resourceType}' AND r.connection_id = :connection_id AND {$date} BETWEEN :start_date AND :end_date GROUP BY 1 ORDER BY 1";
    }
}
