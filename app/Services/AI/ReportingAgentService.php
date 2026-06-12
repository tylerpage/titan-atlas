<?php

namespace App\Services\AI;

use App\Agents\ReportingAgentContext;
use App\Ai\Agents\ClientReportingAgent;
use App\Ai\Agents\ReportingAgent;
use App\Enums\AnalyticsReportSessionStatus;
use App\Jobs\GenerateReportResponseJob;
use App\Models\AnalyticsReportMessage;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\User;
use App\Support\AgentAssistantTextResolver;
use App\Support\AiTraceContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

class ReportingAgentService
{
    public function __construct(
        protected SimpleMetricFastPathService $fastPath,
        protected AiBroadcastService $broadcasts,
        protected AgentAssistantTextResolver $assistantText,
    ) {}
    /**
     * @return array{session: AnalyticsReportSession}
     */
    public function queueMessage(
        ClientDashboard $dashboard,
        User $user,
        string $message,
        ?AnalyticsReportSession $session = null,
        ?string $previewStart = null,
        ?string $previewEnd = null,
        bool $clientMode = false,
    ): array {
        $session ??= AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => AnalyticsReportSessionStatus::Active,
            'title' => $this->sessionTitleFromMessage($message),
        ]);

        if (! $session->title) {
            $session->update(['title' => $this->sessionTitleFromMessage($message)]);
        }

        $this->storeMessage($session, 'user', $message);
        $session->update(['status' => AnalyticsReportSessionStatus::Processing]);

        $fastPathStartedAt = microtime(true);

        $fastPathResult = $this->fastPath->tryRespond(
            dashboard: $dashboard,
            user: $user,
            message: $message,
            session: $session,
            previewStart: $previewStart,
            previewEnd: $previewEnd,
        );

        if ($fastPathResult !== null) {
            $durationMs = (int) round((microtime(true) - $fastPathStartedAt) * 1000);
            $session->update(['duration_ms' => $durationMs]);

            Log::info('titan_ai.session_completed', [
                'session_id' => $session->id,
                'dashboard_id' => $dashboard->id,
                'duration_ms' => $durationMs,
                'client_mode' => $clientMode,
                'model' => 'fast_path',
                'saved_report' => true,
            ]);

            $this->broadcasts->reportSessionUpdated($fastPathResult['session']);

            return ['session' => $fastPathResult['session']];
        }

        GenerateReportResponseJob::dispatch(
            sessionId: $session->id,
            dashboardId: $dashboard->id,
            userId: $user->id,
            message: $message,
            previewStart: $previewStart,
            previewEnd: $previewEnd,
            clientMode: $clientMode,
        );

        return ['session' => $session->fresh()];
    }

    /**
     * @return array{session: AnalyticsReportSession, response: string, report: mixed}
     */
    public function sendMessage(
        ClientDashboard $dashboard,
        User $user,
        string $message,
        ?AnalyticsReportSession $session = null,
        ?string $previewStart = null,
        ?string $previewEnd = null,
        bool $storeUserMessage = true,
        bool $clientMode = false,
    ): array {
        $session ??= AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => AnalyticsReportSessionStatus::Processing,
            'title' => $this->sessionTitleFromMessage($message),
        ]);

        if (! $session->title) {
            $session->update(['title' => $this->sessionTitleFromMessage($message)]);
        }

        if ($storeUserMessage) {
            $this->storeMessage($session, 'user', $message);
        }

        $startedAt = microtime(true);

        @ini_set('max_execution_time', (string) config('titan.reporting.response_timeout_seconds', 120));

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

        $agent = $clientMode
            ? ClientReportingAgent::make(context: $context)
            : ReportingAgent::make(context: $context);

        $historyMessages = $agent->messages();
        $historyCount = is_countable($historyMessages)
            ? count($historyMessages)
            : iterator_count($historyMessages);

        AiTraceContext::begin([
            'flow' => 'reporting',
            'session_id' => $session->id,
            'dashboard_id' => $dashboard->id,
            'model' => config('titan.reporting.model', 'gpt-4o-mini'),
            'max_steps' => $agent->maxSteps(),
            'instructions_chars' => strlen((string) $agent->instructions()),
            'history_messages' => $historyCount,
            'client_mode' => $clientMode,
        ]);

        try {
            $response = $agent->prompt(
                $message,
                provider: $this->provider(),
                model: config('titan.reporting.model', 'gpt-4o-mini'),
            );
        } finally {
            if (AiTraceContext::active()) {
                AiTraceContext::clear();
            }
        }

        $text = $this->assistantText->forReporting($response, $context);

        if (trim($response->text) === '') {
            Log::warning('titan_ai.empty_agent_text', [
                'session_id' => $session->id,
                'dashboard_id' => $dashboard->id,
                'client_mode' => $clientMode,
                'steps' => $response->steps->count(),
                'tool_calls' => $response->toolCalls->count(),
                'saved_report' => $context->lastSavedReport !== null,
            ]);
        }

        $metadata = $this->buildAssistantMetadata($context);

        $this->storeMessage($session, 'assistant', $text, $metadata);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($context->lastSavedReport) {
            $session->update([
                'status' => AnalyticsReportSessionStatus::Completed,
                'duration_ms' => $durationMs,
            ]);
        } else {
            $session->update([
                'status' => AnalyticsReportSessionStatus::Active,
                'duration_ms' => $durationMs,
            ]);
        }

        Log::info('titan_ai.session_completed', [
            'session_id' => $session->id,
            'dashboard_id' => $dashboard->id,
            'duration_ms' => $durationMs,
            'client_mode' => $clientMode,
            'model' => config('titan.reporting.model'),
            'saved_report' => $context->lastSavedReport !== null,
        ]);

        $session = $session->fresh(['messages', 'report']);
        $this->broadcasts->reportSessionUpdated($session);

        return [
            'session' => $session,
            'response' => $text,
            'report' => $context->lastSavedReport?->fresh(),
        ];
    }

    public function storeMessage(AnalyticsReportSession $session, string $role, string $content, ?array $metadata = null): AnalyticsReportMessage
    {
        return AnalyticsReportMessage::query()->create([
            'analytics_report_session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildAssistantMetadata(ReportingAgentContext $context): ?array
    {
        $metadata = ['agent' => 'titan_ai'];

        if ($context->lastSavedReport) {
            $metadata['report_id'] = $context->lastSavedReport->id;
            $metadata['visualization_type'] = $context->lastSavedReport->visualization_type->value;
        }

        if ($context->lastMetricDefinition) {
            $metadata['metric_id'] = $context->lastMetricDefinition->id;
            $metadata['metric_slug'] = $context->lastMetricDefinition->slug;
        }

        if ($context->lastDashboardSpec) {
            $metadata['dashboard_spec'] = $context->lastDashboardSpec;
        }

        if ($context->lastQualityReport) {
            $metadata['quality_report'] = [
                'summary' => $context->lastQualityReport['summary'] ?? [],
                'checks' => collect($context->lastQualityReport['checks'] ?? [])
                    ->where('severity', '!=', 'ok')
                    ->values()
                    ->all(),
            ];
        }

        if ($context->lastDocumentation) {
            $metadata['documentation'] = $context->lastDocumentation;
        }

        return count($metadata) > 1 ? $metadata : null;
    }

    protected function sessionTitleFromMessage(string $message): string
    {
        return Str::limit(trim($message), 80);
    }

    protected function provider(): Lab|string
    {
        $provider = config('titan.reporting.provider', 'openai');

        return Lab::tryFrom($provider) ?? $provider;
    }
}
