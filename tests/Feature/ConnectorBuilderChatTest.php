<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateConnectorBuilderResponseJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBuilderMessage;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConnectorBuilderChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_message_queues_job_and_status_endpoint_tracks_completion(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-chat'])->id,
            'name' => 'Main',
            'slug' => 'main-chat',
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.dashboards.connector-builder.sessions.store', $dashboard),
            ['message' => 'Connect HubSpot deals and contacts'],
        );

        $session = ConnectorBuilderSession::query()->first();
        $this->assertNotNull($session);
        $response->assertRedirect(route('admin.dashboards.connections.ai-create', [$dashboard, $session]));

        Queue::assertPushed(GenerateConnectorBuilderResponseJob::class);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.ai-create', [$dashboard, $session]))
            ->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboards.connector-builder.sessions.status', [$dashboard, $session]))
            ->assertOk()
            ->assertJson(['status' => ConnectorBuilderSessionStatus::Processing->value]);
    }

    public function test_completed_session_page_loads_with_blueprint_and_connections(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-chat-done'])->id,
            'name' => 'Main',
            'slug' => 'main-chat-done',
        ]);

        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $admin->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => 'HubSpot',
        ]);

        ConnectorBuilderMessage::query()->create([
            'connector_builder_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'HubSpot blueprint saved.',
        ]);

        ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'connector_builder_session_id' => $session->id,
            'slug' => 'hubspot',
            'label' => 'HubSpot',
            'status' => ConnectorBlueprintStatus::Draft,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.ai-create', [$dashboard, $session]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Connections/AiCreate')
                ->where('session.status', ConnectorBuilderSessionStatus::Active->value)
                ->has('session.messages', 1)
                ->has('session.blueprint'));
    }

    public function test_connector_builder_tools_resolve_agent_context(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-tools'])->id,
            'name' => 'Main',
            'slug' => 'main-tools',
        ]);

        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $admin->id,
            'status' => ConnectorBuilderSessionStatus::Processing,
            'title' => 'HubSpot',
        ]);

        $context = new \App\Agents\ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $admin,
        );

        $tool = app()->makeWith(\App\Ai\Tools\ConnectorBuilder\SaveConnectorBlueprintTool::class, [
            'context' => $context,
        ]);

        $this->assertInstanceOf(\App\Ai\Tools\ConnectorBuilder\SaveConnectorBlueprintTool::class, $tool);
    }

    public function test_admin_can_resume_ai_chat_from_existing_blueprint(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-resume'])->id,
            'name' => 'Main',
            'slug' => 'main-resume',
        ]);

        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $admin->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => 'Shopware',
        ]);

        ConnectorBuilderMessage::query()->create([
            'connector_builder_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Shopware blueprint saved.',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'connector_builder_session_id' => $session->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.ai-connectors.chat', $blueprint))
            ->assertRedirect(route('admin.dashboards.connections.ai-create', [$dashboard, $session]));

        $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.ai-create', [$dashboard, $session]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Connections/AiCreate')
                ->where('isResuming', true)
                ->has('session.messages', 1)
                ->where('session.blueprint.id', $blueprint->id));
    }

    public function test_blueprint_without_session_gets_a_new_session_when_resuming_chat(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-new-session'])->id,
            'name' => 'Main',
            'slug' => 'main-new-session',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.ai-connectors.chat', $blueprint))
            ->assertRedirect();

        $blueprint->refresh();

        $this->assertNotNull($blueprint->connector_builder_session_id);

        $session = ConnectorBuilderSession::query()->find($blueprint->connector_builder_session_id);
        $this->assertNotNull($session);
        $this->assertSame($dashboard->id, $session->client_dashboard_id);
    }
}
