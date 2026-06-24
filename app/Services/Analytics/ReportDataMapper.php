<?php

namespace App\Services\Analytics;

use App\Enums\ReportVisualizationType;
use App\Support\ValueFormatter;

class ReportDataMapper
{
    /**
     * @param  array{rows: list<array<string, mixed>>, columns: list<string>}  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function toBlockPayload(ReportVisualizationType $type, array $result, array $config): array
    {
        return match ($type) {
            ReportVisualizationType::StatCard => $this->mapStatCard($result, $config),
            ReportVisualizationType::LineChart => $this->mapLineChart($result, $config),
            ReportVisualizationType::Table => $this->mapTable($result, $config),
        };
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, columns: list<string>}  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function mapStatCard(array $result, array $config): array
    {
        $row = $result['rows'][0] ?? [];
        $valueKey = (string) ($config['value_column'] ?? $result['columns'][0] ?? 'value');
        $compareKey = $config['compare_column'] ?? null;
        $rawValue = $row[$valueKey] ?? null;
        $format = (string) ($config['format'] ?? 'number');

        $improvementPercent = null;

        if ($compareKey && isset($row[$compareKey]) && is_numeric($row[$compareKey]) && is_numeric($rawValue)) {
            $previous = (float) $row[$compareKey];
            $current = (float) $rawValue;
            $improvementPercent = $previous == 0
                ? ($current > 0 ? 100.0 : 0.0)
                : round((($current - $previous) / $previous) * 100, 1);
        } elseif (isset($config['improvement_percent'])) {
            $improvementPercent = (float) $config['improvement_percent'];
        }

        return [
            'header' => (string) ($config['header'] ?? $config['title'] ?? 'Metric'),
            'text' => $this->formatValue($rawValue, $format),
            'tooltip' => $config['tooltip'] ?? null,
            'improvement_percent' => $improvementPercent,
        ];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, columns: list<string>}  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function mapLineChart(array $result, array $config): array
    {
        $dateKey = (string) ($config['date_column'] ?? 'date');
        $valueKey = (string) ($config['value_column'] ?? 'value');

        $series = collect($result['rows'])->map(fn (array $row) => [
            'date' => (string) ($row[$dateKey] ?? ''),
            'value' => (float) ($row[$valueKey] ?? 0),
        ])->filter(fn (array $point) => $point['date'] !== '')->values()->all();

        return [
            'title' => (string) ($config['title'] ?? 'Trend'),
            'insights' => (string) ($config['insights'] ?? ''),
            'series' => $series,
            'value_format' => (string) ($config['format'] ?? $config['value_format'] ?? 'number'),
            'series_label' => (string) ($config['series_label'] ?? $config['title'] ?? 'Value'),
        ];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, columns: list<string>}  $result
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function mapTable(array $result, array $config): array
    {
        $columns = $config['columns'] ?? null;

        if (! is_array($columns) || $columns === []) {
            $columns = collect($result['columns'])->map(fn (string $key) => [
                'key' => $key,
                'label' => ucfirst(str_replace('_', ' ', $key)),
            ])->values()->all();
        }

        return [
            'title' => (string) ($config['title'] ?? ''),
            'columns' => $columns,
            'rows' => $result['rows'],
            'filterable' => true,
        ];
    }

    protected function formatValue(mixed $value, string $format): string
    {
        return ValueFormatter::format($value, $format);
    }
}
