<?php

namespace App\Listeners;

use App\Services\AI\AiTraceService;
use App\Support\AiTraceContext;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

class LogAiAgentPerformance
{
    public function __construct(protected AiTraceService $traces) {}

    public function handleInvokingTool(InvokingTool $event): void
    {
        if (! AiTraceContext::enabled() || ! AiTraceContext::active()) {
            return;
        }

        AiTraceContext::recordToolStart($event->toolInvocationId);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        if (! AiTraceContext::enabled() || ! AiTraceContext::active()) {
            return;
        }

        AiTraceContext::recordToolEnd(
            $event->toolInvocationId,
            AiTraceService::toolName($event->tool),
        );
    }

    public function handleAgentPrompted(AgentPrompted $event): void
    {
        if (! AiTraceContext::enabled() || ! AiTraceContext::active()) {
            return;
        }

        $snapshot = AiTraceContext::snapshot();

        if ($snapshot === null) {
            return;
        }

        $snapshot['agent_class'] = class_basename($event->prompt->agent);

        if ($event->response instanceof \Laravel\Ai\Responses\AgentResponse) {
            $this->traces->recordFromAgentResponse(
                $event->invocationId,
                $event->response,
                $snapshot,
            );
        }

        AiTraceContext::clear();
    }
}
