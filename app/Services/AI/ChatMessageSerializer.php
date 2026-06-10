<?php

namespace App\Services\AI;

use App\Enums\AnalyticsReportSessionStatus;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportMessage;
use App\Models\ClientDashboard;
use App\Services\Analytics\ReportBlockResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ChatMessageSerializer
{
    public function __construct(protected ReportBlockResolver $resolver) {}

    /**
     * @param  Collection<int, AnalyticsReportMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function serialize(
        Collection $messages,
        ClientDashboard $dashboard,
        Carbon $start,
        Carbon $end,
        ?AnalyticsReportSessionStatus $sessionStatus = null,
    ): array {
        $skipReportPreviews = $sessionStatus === AnalyticsReportSessionStatus::Processing;

        return $messages->map(function (AnalyticsReportMessage $message) use ($dashboard, $start, $end, $skipReportPreviews) {
            $reportPreview = null;
            $reportId = $message->metadata['report_id'] ?? null;

            if ($reportId && ! $skipReportPreviews) {
                $report = AnalyticsReport::query()
                    ->where('client_dashboard_id', $dashboard->id)
                    ->find($reportId);

                if ($report) {
                    $reportPreview = $this->resolver->previewForChat($report, $dashboard, $start, $end);
                }
            }

            return [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
                'report_preview' => $reportPreview,
                'created_at' => $message->created_at?->toIso8601String(),
            ];
        })->all();
    }
}
