<?php

namespace App\Services\AI;

use App\Agents\PromptSkillRouter;
use App\Agents\ReportingAgentContext;
use App\Enums\AnalyticsReportSessionStatus;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportMessage;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\User;
use App\Services\Analytics\MetricRegistry;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use Carbon\Carbon;

class SimpleMetricFastPathService
{
    /**
     * @var list<array{slug: string, patterns: list<string>}>
     */
    protected array $metricPatterns = [
        ['slug' => 'revenue', 'patterns' => ['total revenue', 'gross revenue', 'what was revenue', 'how much revenue']],
        ['slug' => 'orders', 'patterns' => ['total orders', 'how many orders', 'order count', 'number of orders']],
        ['slug' => 'avg_order_value', 'patterns' => ['average order value', 'aov', 'avg order']],
        ['slug' => 'sessions', 'patterns' => ['total sessions', 'how many sessions', 'shopify sessions']],
        ['slug' => 'visitors', 'patterns' => ['total visitors', 'how many visitors', 'shopify visitors']],
        ['slug' => 'ad_spend', 'patterns' => ['ad spend', 'total ad spend', 'advertising spend']],
        ['slug' => 'ga4_visitors', 'patterns' => ['ga4 visitors', 'google analytics visitors']],
        ['slug' => 'active_users', 'patterns' => ['active users', 'ga4 active users']],
    ];

    public function __construct(
        protected PromptSkillRouter $skills,
        protected MetricRegistry $metrics,
        protected ReportQueryExecutor $executor,
    ) {}

    /**
     * @return array{session: \App\Models\AnalyticsReportSession, response: string, report: AnalyticsReport}|null
     */
    public function tryRespond(
        ClientDashboard $dashboard,
        User $user,
        string $message,
        AnalyticsReportSession $session,
        ?string $previewStart = null,
        ?string $previewEnd = null,
    ): ?array {
        if (! config('titan.reporting.fast_path_enabled', true)) {
            return null;
        }

        if ($this->skills->shouldIncludeDashboardSpecSkill($message)
            || $this->skills->shouldIncludeSummarySkill($message)
            || $this->skills->shouldIncludeQualitySkill($message)
            || $this->skills->shouldIncludeSeoSkill($message)) {
            return null;
        }

        $slug = $this->matchMetricSlug($message);

        if ($slug === null) {
            return null;
        }

        $metric = $this->metrics->findForDashboard($dashboard, $slug);

        if ($metric === null) {
            return null;
        }

        $previewStartDate = $previewStart
            ? Carbon::parse($previewStart)->startOfDay()
            : now()->subDays(29)->startOfDay();
        $previewEndDate = $previewEnd
            ? Carbon::parse($previewEnd)->endOfDay()
            : now()->endOfDay();

        $context = new ReportingAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
            previewStartDate: $previewStartDate,
            previewEndDate: $previewEndDate,
            currentUserMessage: $message,
        );

        try {
            $queryContext = new ReportQueryContext(
                dashboardId: $dashboard->id,
                startDate: $previewStartDate,
                endDate: $previewEndDate,
            );

            $this->executor->execute($metric->sql_template, $queryContext);

            $report = AnalyticsReport::query()->create([
                'client_dashboard_id' => $dashboard->id,
                'analytics_report_session_id' => $session->id,
                'created_by' => $user->id,
                'prompt' => $metric->name,
                'sql' => $metric->sql_template,
                'visualization_type' => $metric->visualization_type,
                'visualization_config' => $metric->visualization_config ?? [],
                'model' => 'fast_path',
            ]);

            $context->lastSavedReport = $report;

            $commentary = sprintf(
                'Here is %s for the selected date range.',
                strtolower($metric->name),
            );

            AnalyticsReportMessage::query()->create([
                'analytics_report_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $commentary,
                'metadata' => [
                    'agent' => 'titan_ai',
                    'report_id' => $report->id,
                    'visualization_type' => $report->visualization_type->value,
                    'fast_path' => true,
                ],
            ]);

            $session->update([
                'status' => AnalyticsReportSessionStatus::Completed,
                'used_fast_path' => true,
            ]);

            return [
                'session' => $session->fresh(['messages']),
                'response' => $commentary,
                'report' => $report->fresh(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function matchMetricSlug(string $message): ?string
    {
        $normalized = strtolower(trim($message));

        foreach ($this->metricPatterns as $entry) {
            foreach ($entry['patterns'] as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return $entry['slug'];
                }
            }
        }

        return null;
    }
}
