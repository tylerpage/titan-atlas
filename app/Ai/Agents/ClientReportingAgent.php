<?php

namespace App\Ai\Agents;

use App\Agents\ClientReportingPromptBuilder;
use App\Agents\ReportingAgentContext;
use App\Ai\Tools\DescribeConnectorSchemaTool;
use App\Ai\Tools\ExplainMetricTool;
use App\Ai\Tools\GenerateDocumentationTool;
use App\Ai\Tools\ListAnalyticsSchemaTool;
use App\Ai\Tools\ListMetricDefinitionsTool;
use App\Ai\Tools\PinReportToSavedDashboardTool;
use App\Ai\Tools\PreviewReportQueryTool;
use App\Ai\Tools\RunDataQualityChecksTool;
use App\Ai\Tools\SaveAnalyticsReportTool;
use App\Models\AnalyticsReportMessage;
use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class ClientReportingAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        protected ReportingAgentContext $context,
        protected ClientReportingPromptBuilder $prompts,
        protected Container $container,
    ) {}

    public function maxSteps(): int
    {
        return (int) config('titan.reporting.client_max_steps', 6);
    }

    public function instructions(): Stringable|string
    {
        return $this->prompts->systemPrompt($this->context);
    }

    public function messages(): iterable
    {
        return $this->context->session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get()
            ->map(fn (AnalyticsReportMessage $message) => new Message(
                $message->role === 'user' ? 'user' : 'assistant',
                $message->content,
            ))
            ->all();
    }

    public function tools(): iterable
    {
        $context = $this->context;

        return [
            $this->container->makeWith(ListAnalyticsSchemaTool::class, ['context' => $context]),
            $this->container->makeWith(DescribeConnectorSchemaTool::class, ['context' => $context]),
            $this->container->makeWith(ListMetricDefinitionsTool::class, ['context' => $context]),
            $this->container->makeWith(ExplainMetricTool::class, ['context' => $context]),
            $this->container->makeWith(RunDataQualityChecksTool::class, ['context' => $context]),
            $this->container->makeWith(GenerateDocumentationTool::class, ['context' => $context]),
            $this->container->makeWith(PreviewReportQueryTool::class, ['context' => $context]),
            $this->container->makeWith(SaveAnalyticsReportTool::class, ['context' => $context]),
            $this->container->makeWith(PinReportToSavedDashboardTool::class, ['context' => $context]),
        ];
    }
}
