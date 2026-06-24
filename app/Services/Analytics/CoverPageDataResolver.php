<?php

namespace App\Services\Analytics;

use App\Enums\CoverPageBlockType;
use App\Enums\CoverPageDataSource;
use App\Enums\DateComparison;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Support\RichTextSanitizer;
use App\Support\ValueFormatter;
use Carbon\Carbon;

class CoverPageDataResolver
{
    public function __construct(
        protected WidgetDataService $widgets,
        protected CommerceDashboardService $commerce,
        protected ReportQueryExecutor $reportQueries,
        protected ReportDataMapper $reportMapper,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolveForClient(CoverPage $coverPage, ClientDashboard $dashboard): ?array
    {
        $coverPage->load('blocks');

        return [
            'id' => $coverPage->id,
            'title' => $coverPage->title,
            'period_start' => $coverPage->period_start?->toDateString(),
            'period_end' => $coverPage->period_end?->toDateString(),
            'is_active' => $coverPage->is_active,
            'blocks' => $coverPage->blocks->map(fn (CoverPageBlock $block) => $this->resolveBlock($block, $dashboard, $coverPage))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveBlock(CoverPageBlock $block, ClientDashboard $dashboard, CoverPage $coverPage): array
    {
        $config = $block->configuration ?? $block->block_type->defaultConfiguration();
        $dataSource = CoverPageDataSource::tryFrom($config['data_source'] ?? 'manual') ?? CoverPageDataSource::Manual;

        if ($block->block_type === CoverPageBlockType::RichText) {
            return $this->resolveRichText($config, $block);
        }

        $resolved = match ($block->block_type) {
            CoverPageBlockType::StatCard => $this->resolveStatCard($config, $dataSource, $dashboard, $coverPage, $block),
            CoverPageBlockType::LineChart => $this->resolveLineChart($config, $dataSource, $dashboard, $coverPage, $block),
            CoverPageBlockType::Table => $this->resolveTable($config, $dataSource, $dashboard, $coverPage, $block),
        };

        return $this->withAiReportMeta($resolved, $config, $dashboard);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function withAiReportMeta(array $payload, array $config, ClientDashboard $dashboard): array
    {
        if (($config['data_source'] ?? '') !== CoverPageDataSource::Report->value) {
            return $payload;
        }

        $report = AnalyticsReport::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->find((int) ($config['report_id'] ?? 0));

        if (! $report) {
            $payload['ai_report'] = [
                'id' => (int) ($config['report_id'] ?? 0),
                'prompt' => 'AI report (missing)',
            ];

            return $payload;
        }

        $payload['ai_report'] = [
            'id' => $report->id,
            'prompt' => $report->prompt,
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveRichText(array $config, CoverPageBlock $block): array
    {
        return [
            'type' => CoverPageBlockType::RichText->value,
            'id' => $block->id,
            'column_span' => $block->column_span,
            'title' => (string) ($config['title'] ?? ''),
            'body' => RichTextSanitizer::clean((string) ($config['body'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveStatCard(
        array $config,
        CoverPageDataSource $dataSource,
        ClientDashboard $dashboard,
        CoverPage $coverPage,
        CoverPageBlock $block,
    ): array {
        $header = (string) ($config['header'] ?? '');
        $text = (string) ($config['text'] ?? '');
        $tooltip = $config['tooltip'] ?? null;
        $improvementPercent = isset($config['improvement_percent']) ? (float) $config['improvement_percent'] : null;

        if ($dataSource === CoverPageDataSource::Report) {
            $mapped = $this->resolveReportBlock($config, $dashboard, $coverPage, $block->block_type);

            return array_merge([
                'type' => CoverPageBlockType::StatCard->value,
                'id' => $block->id,
                'column_span' => $block->column_span,
            ], $mapped);
        }

        if ($dataSource === CoverPageDataSource::Metric) {
            $metricKey = (string) ($config['metric_key'] ?? 'revenue');
            $connectionId = isset($config['connection_id']) ? (int) $config['connection_id'] : null;
            $range = $this->periodRange($coverPage);

            if ($connectionId) {
                $connection = Connection::query()->find($connectionId);

                if ($connection && $connection->connector_type->isCommerce()) {
                    $data = $this->commerce->dataFor(
                        $dashboard,
                        $connection,
                        'custom',
                        ['start' => $range[0]->toDateString(), 'end' => $range[1]->toDateString()],
                        DateComparison::PreviousPeriod,
                    );

                    $total = match ($metricKey) {
                        'orders' => (float) $data['summary']['orders'],
                        'avg_order_value' => (float) $data['summary']['avg_order_value'],
                        default => (float) $data['summary']['revenue'],
                    };

                    $text = match ($metricKey) {
                        'orders' => number_format($total),
                        'avg_order_value' => ValueFormatter::currency($total),
                        default => ValueFormatter::currency($total),
                    };

                    if ($improvementPercent === null) {
                        $improvementPercent = match ($metricKey) {
                            'orders' => $data['summary']['orders_change_percent'],
                            'avg_order_value' => $data['summary']['avg_order_value_change_percent'],
                            default => $data['summary']['revenue_change_percent'],
                        };
                    }
                }
            }
        }

        return [
            'type' => CoverPageBlockType::StatCard->value,
            'id' => $block->id,
            'column_span' => $block->column_span,
            'header' => $header,
            'text' => $text,
            'tooltip' => $tooltip,
            'improvement_percent' => $improvementPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveLineChart(
        array $config,
        CoverPageDataSource $dataSource,
        ClientDashboard $dashboard,
        CoverPage $coverPage,
        CoverPageBlock $block,
    ): array {
        $title = (string) ($config['title'] ?? '');
        $series = $config['series'] ?? [];

        if ($dataSource === CoverPageDataSource::Report) {
            $mapped = $this->resolveReportBlock($config, $dashboard, $coverPage, $block->block_type);
            $blockTitle = trim((string) ($config['title'] ?? ''));
            $insights = RichTextSanitizer::clean((string) ($config['insights'] ?? $mapped['insights'] ?? ''));

            return array_merge([
                'type' => CoverPageBlockType::LineChart->value,
                'id' => $block->id,
                'column_span' => $block->column_span,
            ], $mapped, [
                'title' => $blockTitle !== '' ? $blockTitle : (string) ($mapped['title'] ?? 'Chart'),
                'insights' => $insights,
            ]);
        }

        if ($dataSource === CoverPageDataSource::Metric) {
            $metricKey = (string) ($config['metric_key'] ?? 'revenue');
            $connectionId = isset($config['connection_id']) ? (int) $config['connection_id'] : null;
            $range = $this->periodRange($coverPage);

            if ($connectionId) {
                $connection = Connection::query()->find($connectionId);

                if ($connection && $connection->connector_type->isCommerce()) {
                    $data = $this->commerce->dataFor(
                        $dashboard,
                        $connection,
                        'custom',
                        ['start' => $range[0]->toDateString(), 'end' => $range[1]->toDateString()],
                    );

                    $series = $metricKey === 'orders'
                        ? $this->ordersSeriesFromCommerce($dashboard, $connection, $range[0], $range[1])
                        : ($data['revenue_series'] ?? []);
                }
            } else {
                $widgetType = match ($metricKey) {
                    'orders' => \App\Enums\WidgetType::Orders,
                    'ad_spend' => \App\Enums\WidgetType::AdSpend,
                    'organic_sessions', 'search_clicks' => \App\Enums\WidgetType::OrganicTraffic,
                    default => \App\Enums\WidgetType::Revenue,
                };

                $data = $this->widgets->dataFor(
                    $dashboard,
                    $widgetType,
                    'custom',
                    ['start' => $range[0]->toDateString(), 'end' => $range[1]->toDateString()],
                );

                $series = $data['series'] ?? [];
            }
        }

        $metricKey = (string) ($config['metric_key'] ?? 'revenue');
        $valueFormat = $config['value_format'] ?? match ($metricKey) {
            'revenue', 'ad_spend' => 'currency',
            default => 'number',
        };

        return [
            'type' => CoverPageBlockType::LineChart->value,
            'id' => $block->id,
            'column_span' => $block->column_span,
            'title' => $title !== '' ? $title : 'Chart',
            'insights' => RichTextSanitizer::clean((string) ($config['insights'] ?? '')),
            'series' => $series,
            'value_format' => $valueFormat,
            'series_label' => $config['series_label'] ?? $title,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveTable(
        array $config,
        CoverPageDataSource $dataSource,
        ClientDashboard $dashboard,
        CoverPage $coverPage,
        CoverPageBlock $block,
    ): array {
        $title = (string) ($config['title'] ?? '');
        $columns = $config['columns'] ?? [];
        $rows = $config['rows'] ?? [];

        if ($dataSource === CoverPageDataSource::Report) {
            $mapped = $this->resolveReportBlock($config, $dashboard, $coverPage, $block->block_type);

            return array_merge([
                'type' => CoverPageBlockType::Table->value,
                'id' => $block->id,
                'column_span' => $block->column_span,
            ], $mapped);
        }

        if ($dataSource === CoverPageDataSource::Metric) {
            $connectionId = isset($config['connection_id']) ? (int) $config['connection_id'] : null;
            $range = $this->periodRange($coverPage);

            if ($connectionId) {
                $connection = Connection::query()->find($connectionId);

                if ($connection && $connection->connector_type->isCommerce()) {
                    $data = $this->commerce->dataFor(
                        $dashboard,
                        $connection,
                        'custom',
                        ['start' => $range[0]->toDateString(), 'end' => $range[1]->toDateString()],
                    );

                    $columns = [
                        ['key' => 'order_number', 'label' => 'Order'],
                        ['key' => 'date', 'label' => 'Date'],
                        ['key' => 'total', 'label' => 'Total'],
                        ['key' => 'source_medium', 'label' => 'Source / medium'],
                        ['key' => 'channel', 'label' => 'Channel'],
                    ];
                    $rows = $data['orders'] ?? [];
                }
            }
        }

        return [
            'type' => CoverPageBlockType::Table->value,
            'id' => $block->id,
            'column_span' => $block->column_span,
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'filterable' => (bool) ($config['filterable'] ?? false),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(CoverPage $coverPage): array
    {
        $start = $coverPage->period_start
            ? $coverPage->period_start->copy()->startOfDay()
            : now()->subDays(29)->startOfDay();
        $end = $coverPage->period_end
            ? $coverPage->period_end->copy()->endOfDay()
            : now()->endOfDay();

        return [$start, $end];
    }

    /**
     * @return list<array{date: string, value: float}>
     */
    protected function ordersSeriesFromCommerce(
        ClientDashboard $dashboard,
        Connection $connection,
        Carbon $start,
        Carbon $end,
    ): array {
        return $this->commerce->ordersSeriesFor($connection, $start, $end);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveReportBlock(
        array $config,
        ClientDashboard $dashboard,
        CoverPage $coverPage,
        CoverPageBlockType $blockType,
    ): array {
        $reportId = (int) ($config['report_id'] ?? 0);
        $report = AnalyticsReport::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->find($reportId);

        if (! $report) {
            return match ($blockType) {
                CoverPageBlockType::StatCard => [
                    'header' => $config['header'] ?? 'Report',
                    'text' => '—',
                    'tooltip' => null,
                    'improvement_percent' => null,
                ],
                CoverPageBlockType::LineChart => [
                    'title' => $config['title'] ?? 'Report',
                    'series' => [],
                ],
                CoverPageBlockType::Table => [
                    'title' => $config['title'] ?? '',
                    'columns' => [],
                    'rows' => [],
                    'filterable' => true,
                ],
            };
        }

        [$start, $end] = $this->periodRange($coverPage);
        $days = $start->diffInDays($end) + 1;
        $compareEnd = $start->copy()->subDay();
        $compareStart = $compareEnd->copy()->subDays($days - 1);

        $context = new ReportQueryContext(
            dashboardId: $dashboard->id,
            startDate: $start,
            endDate: $end,
            compareStartDate: $compareStart,
            compareEndDate: $compareEnd,
            connectionId: $report->visualization_config['connection_id'] ?? null,
        );

        try {
            $result = $this->reportQueries->execute($report->sql, $context);

            return $this->reportMapper->toBlockPayload(
                $report->visualization_type,
                $result,
                $report->visualization_config ?? [],
            );
        } catch (\Throwable) {
            return match ($blockType) {
                CoverPageBlockType::StatCard => [
                    'header' => $config['header'] ?? 'Report',
                    'text' => 'Query error',
                    'tooltip' => null,
                    'improvement_percent' => null,
                ],
                CoverPageBlockType::LineChart => [
                    'title' => $config['title'] ?? 'Report',
                    'series' => [],
                ],
                CoverPageBlockType::Table => [
                    'title' => $config['title'] ?? '',
                    'columns' => [],
                    'rows' => [],
                    'filterable' => true,
                ],
            };
        }
    }
}
