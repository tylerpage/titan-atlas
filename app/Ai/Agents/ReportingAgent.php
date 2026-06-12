<?php

namespace App\Ai\Agents;

use App\Agents\ReportingAgentContext;
use App\Agents\ReportingPromptBuilder;
use App\Ai\Concerns\CapsReportingConversationHistory;
use App\Ai\Tools\AnalyzeCampaignPerformanceTool;
use App\Ai\Tools\BuildDashboardSpecTool;
use App\Ai\Tools\CheckConnectorDataTool;
use App\Ai\Tools\CreateAnalyticsReportTool;
use App\Ai\Tools\CreateMetricDefinitionTool;
use App\Ai\Tools\DescribeConnectorSchemaTool;
use App\Ai\Tools\ExplainMetricTool;
use App\Ai\Tools\GenerateDocumentationTool;
use App\Ai\Tools\ListAnalyticsSchemaTool;
use App\Ai\Tools\ListDashboardMemoriesTool;
use App\Ai\Tools\ListMetricDefinitionsTool;
use App\Ai\Tools\PlaceReportOnCoverPageTool;
use App\Ai\Tools\PreviewReportQueryTool;
use App\Ai\Tools\RunDataQualityChecksTool;
use App\Ai\Tools\SaveAnalyticsReportTool;
use App\Ai\Tools\SaveDashboardMemoryTool;
use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class ReportingAgent implements Agent, Conversational, HasTools
{
    use CapsReportingConversationHistory;
    use Promptable;

    public function __construct(
        protected ReportingAgentContext $context,
        protected ReportingPromptBuilder $prompts,
        protected Container $container,
    ) {}

    public function maxSteps(): int
    {
        return (int) config('titan.reporting.max_steps', 10);
    }

    public function instructions(): Stringable|string
    {
        return $this->prompts->systemPrompt($this->context);
    }

    public function messages(): iterable
    {
        return $this->cappedSessionMessages();
    }

    public function tools(): iterable
    {
        $context = $this->context;

        return [
            $this->container->makeWith(AnalyzeCampaignPerformanceTool::class, ['context' => $context]),
            $this->container->makeWith(CheckConnectorDataTool::class, ['context' => $context]),
            $this->container->makeWith(ListDashboardMemoriesTool::class, ['context' => $context]),
            $this->container->makeWith(SaveDashboardMemoryTool::class, ['context' => $context]),
            $this->container->makeWith(ListAnalyticsSchemaTool::class, ['context' => $context]),
            $this->container->makeWith(DescribeConnectorSchemaTool::class, ['context' => $context]),
            $this->container->makeWith(ListMetricDefinitionsTool::class, ['context' => $context]),
            $this->container->makeWith(ExplainMetricTool::class, ['context' => $context]),
            $this->container->makeWith(CreateMetricDefinitionTool::class, ['context' => $context]),
            $this->container->makeWith(RunDataQualityChecksTool::class, ['context' => $context]),
            $this->container->makeWith(GenerateDocumentationTool::class, ['context' => $context]),
            $this->container->makeWith(BuildDashboardSpecTool::class, ['context' => $context]),
            $this->container->makeWith(CreateAnalyticsReportTool::class, ['context' => $context]),
            $this->container->makeWith(PreviewReportQueryTool::class, ['context' => $context]),
            $this->container->makeWith(SaveAnalyticsReportTool::class, ['context' => $context]),
            $this->container->makeWith(PlaceReportOnCoverPageTool::class, ['context' => $context]),
        ];
    }
}
