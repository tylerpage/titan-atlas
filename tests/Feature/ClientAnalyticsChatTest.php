<?php

namespace Tests\Feature;

use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Jobs\GenerateReportResponseJob;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportMessage;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientAnalyticsChatTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboardWithClient(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => [
                'date' => '2025-06-01',
                'total' => 500,
                'source' => 'google',
                'medium' => 'cpc',
                'source_medium' => 'google / cpc',
            ],
            'payload_hash' => hash('sha256', '1001'),
            'fetched_at' => now(),
        ]);

        return compact('dashboard', 'client', 'connection');
    }

    public function test_client_can_start_ai_chat_session(): void
    {
        Queue::fake();

        ['dashboard' => $dashboard, 'client' => $client] = $this->createDashboardWithClient();

        $response = $this->actingAs($client)
            ->post(route('client.dashboard.ai.sessions.store', $dashboard), [
                'message' => 'What was total revenue?',
                'preview_start' => '2025-06-01',
                'preview_end' => '2025-06-30',
            ]);

        $session = AnalyticsReportSession::query()->first();

        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('ai', $query['tab'] ?? null);
        $this->assertSame('chat', $query['ai_view'] ?? null);
        $this->assertSame((string) $session->id, $query['session'] ?? null);
        $this->assertSame('2025-06-01', $query['preview_start'] ?? null);
        $this->assertSame('2025-06-30', $query['preview_end'] ?? null);

        $this->assertNotNull($session);
        $this->assertSame($client->id, $session->user_id);
        $this->assertSame('What was total revenue?', $session->title);
        $this->assertSame(AnalyticsReportSessionStatus::Completed, $session->status);
        $this->assertTrue($session->used_fast_path);

        $this->assertDatabaseHas('analytics_report_messages', [
            'analytics_report_session_id' => $session->id,
            'role' => 'assistant',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_client_can_view_chat_and_session_history(): void
    {
        ['dashboard' => $dashboard, 'client' => $client] = $this->createDashboardWithClient();

        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $client->id,
            'title' => 'Revenue question',
            'status' => AnalyticsReportSessionStatus::Active,
        ]);

        $this->actingAs($client)
            ->get(route('client.dashboard.ai.chat', [$dashboard, $session]))
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'ai',
                'ai_view' => 'chat',
                'session' => $session->id,
            ]));

        $this->actingAs($client)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'ai',
                'session' => $session->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('tab', 'ai')
                ->where('aiSession.id', $session->id)
            );

        $this->actingAs($client)
            ->get(route('client.dashboard.ai.sessions', $dashboard))
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'ai',
                'ai_view' => 'history',
            ]));

        $this->actingAs($client)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'ai',
                'ai_view' => 'history',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('tab', 'ai')
                ->where('aiView', 'history')
                ->has('aiSessions', 1)
            );
    }

    public function test_client_cannot_access_other_users_chat_session(): void
    {
        ['dashboard' => $dashboard, 'client' => $client] = $this->createDashboardWithClient();
        $otherClient = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($otherClient);

        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $client->id,
            'status' => AnalyticsReportSessionStatus::Active,
        ]);

        $this->actingAs($otherClient)
            ->get(route('client.dashboard.ai.chat', [$dashboard, $session]))
            ->assertNotFound();
    }

    public function test_unassigned_user_cannot_access_client_ai_chat(): void
    {
        ['dashboard' => $dashboard] = $this->createDashboardWithClient();
        $stranger = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($stranger)
            ->get(route('client.dashboard.ai.chat', $dashboard))
            ->assertForbidden();
    }

    public function test_chat_renders_inline_report_preview_from_message_metadata(): void
    {
        ['dashboard' => $dashboard, 'client' => $client] = $this->createDashboardWithClient();

        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $client->id,
            'status' => AnalyticsReportSessionStatus::Completed,
        ]);

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'analytics_report_session_id' => $session->id,
            'created_by' => $client->id,
            'prompt' => 'Total revenue',
            'sql' => 'SELECT SUM(CAST(json_extract(payload, \'$.total\') AS REAL)) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = \'order\' AND json_extract(r.payload, \'$.date\') BETWEEN :start_date AND :end_date',
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Revenue',
                'format' => 'currency',
                'value_column' => 'revenue',
            ],
        ]);

        AnalyticsReportMessage::query()->create([
            'analytics_report_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Here is your revenue total.',
            'metadata' => [
                'report_id' => $report->id,
                'visualization_type' => ReportVisualizationType::StatCard->value,
            ],
        ]);

        $this->actingAs($client)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'ai',
                'session' => $session->id,
                'preview_start' => '2025-06-01',
                'preview_end' => '2025-06-30',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('aiSession.messages.0.metadata.report_id', $report->id)
                ->where('aiSession.messages.0.report_preview.report_id', $report->id)
                ->where('aiSession.messages.0.report_preview.visualization_type', 'stat_card')
            );
    }
}
