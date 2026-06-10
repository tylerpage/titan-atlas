<?php

namespace App\Support;

use App\Agents\ConnectorBuilderAgentContext;
use App\Agents\ReportingAgentContext;
use App\Models\AnalyticsReport;
use Laravel\Ai\Responses\TextResponse;

class AgentAssistantTextResolver
{
    public function forReporting(TextResponse $response, ReportingAgentContext $context): string
    {
        $text = trim($response->text);

        if ($text !== '') {
            return $text;
        }

        if ($context->lastSavedReport instanceof AnalyticsReport) {
            return $this->savedReportMessage($context->lastSavedReport);
        }

        if ($context->lastMetricDefinition) {
            return 'I added the metric definition to your dashboard catalog.';
        }

        if ($context->lastDocumentation) {
            return 'I generated the documentation you asked for.';
        }

        if ($context->lastQualityReport) {
            return (string) ($context->lastQualityReport['summary']['headline']
                ?? 'I finished the data quality checks — see the details below.');
        }

        if ($context->lastDashboardSpec) {
            return 'I drafted a dashboard layout — review the spec below.';
        }

        $stepText = $this->lastStepText($response);

        if ($stepText !== '') {
            return $stepText;
        }

        $toolError = $this->lastToolError($response);

        if ($toolError !== null) {
            return 'I ran into a problem while working on that: '.$toolError;
        }

        return 'I ran out of steps before I could finish my reply. Please try again — a simpler question often works best.';
    }

    public function forConnectorBuilder(TextResponse $response, ConnectorBuilderAgentContext $context): string
    {
        $text = trim($response->text);

        if ($text !== '') {
            return $text;
        }

        if ($context->lastDashboardSpec) {
            return 'I proposed a connector dashboard — review the layout below.';
        }

        if ($context->connection) {
            return 'Your connector connection is ready. Sync data from the connection page, then open the Saved tab on the client dashboard to view widgets.';
        }

        if ($context->lastTestResult) {
            $status = $context->lastTestResult['success'] ?? null;

            if ($status === true) {
                return 'The connection test succeeded — see the results below.';
            }

            if ($status === false) {
                return 'The connection test failed — see the details below.';
            }
        }

        if ($context->lastDevTasks) {
            return 'I added development tasks for this connector — see the list below.';
        }

        $stepText = $this->lastStepText($response);

        if ($stepText !== '') {
            return $stepText;
        }

        $toolError = $this->lastToolError($response);

        if ($toolError !== null) {
            return 'I ran into a problem while working on that: '.$toolError;
        }

        return 'I ran out of steps before I could finish my reply. Please try again with a shorter request.';
    }

    protected function savedReportMessage(AnalyticsReport $report): string
    {
        $prompt = trim($report->prompt);

        if ($prompt === '') {
            return 'Your report is saved below.';
        }

        return "Here's {$prompt} — your chart is saved below.";
    }

    protected function lastStepText(TextResponse $response): string
    {
        foreach ($response->steps->reverse() as $step) {
            $text = trim($step->text);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    protected function lastToolError(TextResponse $response): ?string
    {
        foreach ($response->toolResults->reverse() as $toolResult) {
            $result = $toolResult->result;

            if (! is_string($result)) {
                continue;
            }

            $decoded = json_decode($result, true);

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['success'] ?? null) === false && filled($decoded['error'] ?? null)) {
                return (string) $decoded['error'];
            }
        }

        return null;
    }
}
