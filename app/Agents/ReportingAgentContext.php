<?php

namespace App\Agents;

use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\MetricDefinition;
use App\Models\User;
use Carbon\Carbon;

class ReportingAgentContext
{
    public function __construct(
        public AnalyticsReportSession $session,
        public ClientDashboard $dashboard,
        public User $user,
        public Carbon $previewStartDate,
        public Carbon $previewEndDate,
        public ?Carbon $previewCompareStartDate = null,
        public ?Carbon $previewCompareEndDate = null,
        public ?int $connectionId = null,
        public ?AnalyticsReport $lastSavedReport = null,
        public ?array $lastPreviewResult = null,
        public ?string $lastPreviewSql = null,
        public ?MetricDefinition $lastMetricDefinition = null,
        public ?array $lastDashboardSpec = null,
        public ?array $lastQualityReport = null,
        public ?array $lastDocumentation = null,
        public ?string $currentUserMessage = null,
    ) {}
}
