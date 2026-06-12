<?php

namespace App\Jobs;

use App\Enums\AnalyticsReportSessionStatus;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\User;
use App\Services\AI\AiBroadcastService;
use App\Services\AI\ReportingAgentService;
use App\Support\AiTraceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateReportResponseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 2;

    public function __construct(
        public int $sessionId,
        public int $dashboardId,
        public int $userId,
        public string $message,
        public ?string $previewStart = null,
        public ?string $previewEnd = null,
        public bool $clientMode = false,
    ) {
        $this->timeout = (int) config('titan.reporting.response_timeout_seconds', 120);
        $this->onQueue(config('titan.queues.ai', 'ai'));
    }

    public function handle(ReportingAgentService $agent): void
    {
        @ini_set('max_execution_time', (string) ($this->timeout + 30));

        $session = AnalyticsReportSession::query()->findOrFail($this->sessionId);
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
            previewStart: $this->previewStart,
            previewEnd: $this->previewEnd,
            storeUserMessage: false,
            clientMode: $this->clientMode,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Report agent job failed', [
            'session_id' => $this->sessionId,
            'error' => $exception?->getMessage(),
        ]);

        $session = AnalyticsReportSession::query()->find($this->sessionId);

        if ($session) {
            $session->update(['status' => AnalyticsReportSessionStatus::Failed]);
            $session->messages()->create([
                'role' => 'assistant',
                'content' => 'Sorry, the reporting assistant timed out or failed. Please try a simpler question or try again.',
            ]);

            app(AiBroadcastService::class)->reportSessionUpdated($session->fresh());
        }
    }
}
