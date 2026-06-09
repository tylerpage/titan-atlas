<?php

namespace App\Services\Admin;

use App\Enums\CoverPageDataSource;
use App\Models\AnalyticsReport;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;

class AnalyticsReportPlacementService
{
    public function __construct(protected CoverPageBlockService $blocks) {}

    public function placeOnCoverPage(
        AnalyticsReport $report,
        CoverPage $coverPage,
        int $columnSpan = 1,
    ): CoverPageBlock {
        abort_unless($report->client_dashboard_id === $coverPage->client_dashboard_id, 422);

        $type = $report->visualization_type->toBlockType();
        $config = array_merge($type->defaultConfiguration(), [
            'data_source' => CoverPageDataSource::Report->value,
            'report_id' => $report->id,
        ]);

        $vizConfig = $report->visualization_config ?? [];

        if ($type->value === 'stat_card') {
            $config['header'] = $vizConfig['header'] ?? $vizConfig['title'] ?? '';
            $config['tooltip'] = $vizConfig['tooltip'] ?? null;
        } elseif ($type->value === 'line_chart') {
            $config['title'] = $vizConfig['title'] ?? '';
        } elseif ($type->value === 'table') {
            $config['title'] = $vizConfig['title'] ?? '';
        }

        return $this->blocks->create($coverPage, $type, [
            'column_span' => $columnSpan,
            'configuration' => $config,
        ]);
    }
}
