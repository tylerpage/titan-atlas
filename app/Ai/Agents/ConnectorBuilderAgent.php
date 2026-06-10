<?php

namespace App\Ai\Agents;

use App\Agents\ConnectorBuilderAgentContext;
use App\Agents\ConnectorBuilderPromptBuilder;
use App\Ai\Concerns\CapsConnectorBuilderConversationHistory;
use App\Ai\Tools\ConnectorBuilder\CreateDynamicConnectionTool;
use App\Ai\Tools\ConnectorBuilder\GetBlueprintStatusTool;
use App\Ai\Tools\ConnectorBuilder\ListExistingConnectorsTool;
use App\Ai\Tools\ConnectorBuilder\LookupConnectorCatalogTool;
use App\Ai\Tools\ConnectorBuilder\ListBlueprintAnalyticsSchemaTool;
use App\Ai\Tools\ConnectorBuilder\ProposeConnectorDashboardTool;
use App\Ai\Tools\ConnectorBuilder\RevertConnectorDashboardTool;
use App\Ai\Tools\ConnectorBuilder\RecordDevTasksTool;
use App\Ai\Tools\ConnectorBuilder\ResearchConnectorApiTool;
use App\Ai\Tools\ConnectorBuilder\SaveConnectorBlueprintTool;
use App\Ai\Tools\ConnectorBuilder\TestBlueprintConnectionTool;
use App\Ai\Tools\ConnectorBuilder\UpdateBlueprintCredentialsTool;
use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class ConnectorBuilderAgent implements Agent, Conversational, HasTools
{
    use CapsConnectorBuilderConversationHistory;
    use Promptable;

    public function __construct(
        protected ConnectorBuilderAgentContext $context,
        protected ConnectorBuilderPromptBuilder $prompts,
        protected Container $container,
    ) {}

    public function maxSteps(): int
    {
        return (int) config('titan.connector_builder.max_steps', 15);
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
            $this->container->makeWith(ListExistingConnectorsTool::class, ['context' => $context]),
            $this->container->makeWith(LookupConnectorCatalogTool::class, ['context' => $context]),
            $this->container->makeWith(ResearchConnectorApiTool::class, ['context' => $context]),
            $this->container->makeWith(SaveConnectorBlueprintTool::class, ['context' => $context]),
            $this->container->makeWith(UpdateBlueprintCredentialsTool::class, ['context' => $context]),
            $this->container->makeWith(TestBlueprintConnectionTool::class, ['context' => $context]),
            $this->container->makeWith(CreateDynamicConnectionTool::class, ['context' => $context]),
            $this->container->makeWith(ListBlueprintAnalyticsSchemaTool::class, ['context' => $context]),
            $this->container->makeWith(ProposeConnectorDashboardTool::class, ['context' => $context]),
            $this->container->makeWith(RevertConnectorDashboardTool::class, ['context' => $context]),
            $this->container->makeWith(RecordDevTasksTool::class, ['context' => $context]),
            $this->container->makeWith(GetBlueprintStatusTool::class, ['context' => $context]),
        ];
    }
}
