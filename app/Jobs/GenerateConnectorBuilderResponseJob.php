<?php

namespace App\Jobs;

use App\Enums\ConnectorBuilderSessionStatus;
use App\Models\ClientDashboard;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use App\Services\AI\AiBroadcastService;
use App\Services\AI\ConnectorBuilderAgentService;
use App\Support\AiTraceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateConnectorBuilderResponseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 2;

    public function __construct(
        public int $sessionId,
        public int $dashboardId,
        public int $userId,
        public string $message,
    ) {
        $this->timeout = (int) config('titan.connector_builder.response_timeout_seconds', 180);
        $this->onQueue(config('titan.queues.ai', 'ai'));
    }

    public function handle(ConnectorBuilderAgentService $agent): void
    {
        @ini_set('max_execution_time', (string) ($this->timeout + 30));

        $session = ConnectorBuilderSession::query()->findOrFail($this->sessionId);
        $dashboard = ClientDashboard::query()->findOrFail($this->dashboardId);
        $user = User::query()->findOrFail($this->userId);

        if ($session->updated_at !== null) {
            AiTraceContext::setQueueWaitMs(
                max(0, (int) round((microtime(true) - $session->updated_at->getTimestamp()) * 1000)),
            );
        }

        $agent->sendMessage(
            dashboard: $dashboard,
            user: $user,
            message: $this->message,
            session: $session,
            storeUserMessage: false,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Connector builder agent job failed', [
            'session_id' => $this->sessionId,
            'error' => $exception?->getMessage(),
        ]);

        $session = ConnectorBuilderSession::query()->find($this->sessionId);

        if ($session) {
            $session->update(['status' => ConnectorBuilderSessionStatus::Failed]);
            app(AiBroadcastService::class)->connectorBuilderSessionUpdated($session->fresh());
        }
    }
}
