<?php

namespace App\Services\AI;

use App\Agents\ConnectorBuilderAgentContext;
use App\Ai\Agents\ConnectorBuilderAgent;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Jobs\GenerateConnectorBuilderResponseJob;
use App\Models\ClientDashboard;
use App\Models\ConnectorBuilderMessage;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use App\Support\DynamicConnectorReadOnlyGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

class ConnectorBuilderAgentService
{
    public function __construct(
        protected DynamicConnectorReadOnlyGuard $readOnlyGuard,
        protected AiBroadcastService $broadcasts,
    ) {}
    /**
     * @return array{session: ConnectorBuilderSession}
     */
    public function queueMessage(
        ClientDashboard $dashboard,
        User $user,
        string $message,
        ?ConnectorBuilderSession $session = null,
        ?array $credentials = null,
    ): array {
        $session ??= ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => $this->sessionTitleFromMessage($message),
        ]);

        if (! $session->title) {
            $session->update(['title' => $this->sessionTitleFromMessage($message)]);
        }

        if ($credentials !== null && $credentials !== []) {
            $session->update([
                'pending_credentials' => array_merge($session->pending_credentials ?? [], $credentials),
            ]);
        }

        $this->storeMessage($session, 'user', $message);
        $session->update(['status' => ConnectorBuilderSessionStatus::Processing]);

        GenerateConnectorBuilderResponseJob::dispatch(
            sessionId: $session->id,
            dashboardId: $dashboard->id,
            userId: $user->id,
            message: $this->agentMessage($message),
        );

        return ['session' => $session->fresh()];
    }

    /**
     * @return array{session: ConnectorBuilderSession, response: string}
     */
    public function sendMessage(
        ClientDashboard $dashboard,
        User $user,
        string $message,
        ?ConnectorBuilderSession $session = null,
        bool $storeUserMessage = true,
    ): array {
        $session ??= ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => ConnectorBuilderSessionStatus::Processing,
            'title' => $this->sessionTitleFromMessage($message),
        ]);

        if (! $session->title) {
            $session->update(['title' => $this->sessionTitleFromMessage($message)]);
        }

        if ($storeUserMessage) {
            $this->storeMessage($session, 'user', $message);
        }

        $startedAt = microtime(true);

        @ini_set('max_execution_time', (string) config('titan.connector_builder.response_timeout_seconds', 180));

        $session->load(['blueprint.streams', 'blueprint.connections']);

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
            blueprint: $session->blueprint,
            connection: $session->blueprint?->connections->first(),
            currentUserMessage: $message,
        );

        $agent = ConnectorBuilderAgent::make(context: $context);

        $response = $agent->prompt(
            $this->agentMessage($message),
            provider: $this->provider(),
            model: config('titan.connector_builder.model', 'gpt-4o-mini'),
        );

        $text = trim($response->text ?: 'I was unable to generate a response.');
        $metadata = $this->buildAssistantMetadata($context);

        $this->storeMessage($session, 'assistant', $text, $metadata);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $session->update([
            'status' => ConnectorBuilderSessionStatus::Active,
            'duration_ms' => $durationMs,
        ]);

        Log::info('connector_builder.session_completed', [
            'session_id' => $session->id,
            'dashboard_id' => $dashboard->id,
            'duration_ms' => $durationMs,
            'blueprint_id' => $context->blueprint?->id,
            'connection_id' => $context->connection?->id,
        ]);

        $session = $session->fresh(['messages', 'blueprint.streams', 'blueprint.connections']);
        $this->broadcasts->connectorBuilderSessionUpdated($session);

        return [
            'session' => $session,
            'response' => $text,
        ];
    }

    public function storeMessage(
        ConnectorBuilderSession $session,
        string $role,
        string $content,
        ?array $metadata = null,
    ): ConnectorBuilderMessage {
        return ConnectorBuilderMessage::query()->create([
            'connector_builder_session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildAssistantMetadata(ConnectorBuilderAgentContext $context): ?array
    {
        $metadata = ['agent' => 'connector_builder'];

        if ($context->blueprint) {
            $metadata['blueprint_id'] = $context->blueprint->id;
            $metadata['blueprint_status'] = $context->blueprint->status->value;
        }

        if ($context->connection) {
            $metadata['connection_id'] = $context->connection->id;
        }

        if ($context->lastDashboardSpec) {
            $metadata['dashboard_spec'] = $context->lastDashboardSpec;
        }

        if ($context->lastDevTasks) {
            $metadata['dev_tasks'] = $context->lastDevTasks;
        }

        if ($context->lastTestResult) {
            $metadata['test_result'] = $context->lastTestResult;
        }

        return count($metadata) > 1 ? $metadata : null;
    }

    protected function sessionTitleFromMessage(string $message): string
    {
        return Str::limit(trim($message), 80);
    }

    protected function provider(): Lab|string
    {
        $provider = config('titan.connector_builder.provider', 'openai');

        return Lab::tryFrom($provider) ?? $provider;
    }

    protected function agentMessage(string $message): string
    {
        if (! $this->readOnlyGuard->detectsWriteIntent($message)) {
            return $message;
        }

        return $this->readOnlyGuard->writeIntentReminder()."\n\n".$message;
    }
}
