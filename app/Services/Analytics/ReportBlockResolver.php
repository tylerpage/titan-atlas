<?php

namespace App\Services\Analytics;

use App\Enums\ReportVisualizationType;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use Carbon\Carbon;

class ReportBlockResolver
{
    public function __construct(
        protected ReportQueryExecutor $executor,
        protected ReportDataMapper $mapper,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(
        AnalyticsReport $report,
        ClientDashboard $dashboard,
        Carbon $start,
        Carbon $end,
        ?string $titleOverride = null,
        ?string $descriptionOverride = null,
    ): ?array {
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
            $result = $this->executor->execute($report->sql, $context);
            $payload = $this->mapper->toBlockPayload(
                $report->visualization_type,
                $result,
                $report->visualization_config ?? [],
            );
        } catch (\Throwable) {
            return null;
        }

        $blockType = $report->visualization_type->toBlockType()->value;

        if ($titleOverride) {
            if ($blockType === 'line_chart' || $blockType === 'table') {
                $payload['title'] = $titleOverride;
            }

            if ($blockType === 'stat_card') {
                $payload['header'] = $titleOverride;
            }
        }

        return [
            'type' => $blockType,
            'visualization_type' => $report->visualization_type->value,
            'title' => $titleOverride ?? ($payload['title'] ?? $payload['header'] ?? $report->prompt),
            'description' => $descriptionOverride,
            'ai_report' => [
                'id' => $report->id,
                'prompt' => $report->prompt,
            ],
            'report_id' => $report->id,
            ...$payload,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewForChat(
        AnalyticsReport $report,
        ClientDashboard $dashboard,
        Carbon $start,
        Carbon $end,
    ): ?array {
        $resolved = $this->resolve($report, $dashboard, $start, $end);

        if (! $resolved) {
            return null;
        }

        return [
            'report_id' => $report->id,
            'visualization_type' => $report->visualization_type->value,
            'prompt' => $report->prompt,
            'payload' => $resolved,
        ];
    }
}
