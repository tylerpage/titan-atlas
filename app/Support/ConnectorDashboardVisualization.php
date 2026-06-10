<?php

namespace App\Support;

use App\Enums\ReportVisualizationType;

class ConnectorDashboardVisualization
{
    /**
     * @var array<string, string>
     */
    protected const ALIASES = [
        'number' => 'stat_card',
        'kpi' => 'stat_card',
        'metric' => 'stat_card',
        'stat' => 'stat_card',
        'bar_chart' => 'table',
        'bar' => 'table',
        'chart' => 'line_chart',
        'line' => 'line_chart',
        'timeseries' => 'line_chart',
    ];

    public static function normalize(string $value): ReportVisualizationType
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return ReportVisualizationType::Table;
        }

        if (isset(self::ALIASES[$normalized])) {
            $normalized = self::ALIASES[$normalized];
        }

        return ReportVisualizationType::from($normalized);
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @return list<string>
     */
    public static function validateWidgetSql(array $widgets): array
    {
        $warnings = [];

        foreach ($widgets as $index => $widget) {
            $sql = strtolower((string) ($widget['sql'] ?? ''));

            if ($sql === '') {
                $warnings[] = "Widget {$index}: SQL is empty.";

                continue;
            }

            if (! str_contains($sql, ':dashboard_id') && ! str_contains($sql, 'client_dashboard_id')) {
                $warnings[] = "Widget {$index}: SQL must filter by :dashboard_id or client_dashboard_id.";
            }

            if (! str_contains($sql, 'raw_connector_payloads')) {
                $warnings[] = "Widget {$index}: SQL should query raw_connector_payloads for dynamic connector data.";
            }

            if (! str_contains($sql, 'json_extract') && ! str_contains($sql, 'payload->>') && ! str_contains($sql, 'payload#>>')) {
                $warnings[] = "Widget {$index}: Read JSON fields via json_extract(r.payload, '\$.field') or payload->>'field'.";
            }
        }

        return $warnings;
    }
}
