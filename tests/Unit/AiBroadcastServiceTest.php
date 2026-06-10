<?php

namespace Tests\Unit;

use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Enums\UserRole;
use App\Events\AiReportSessionUpdated;
use App\Events\ConnectorBuilderSessionUpdated;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use App\Services\AI\AiBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AiBroadcastServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboard(): ClientDashboard
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-broadcast']);

        return ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-broadcast',
        ]);
    }

    public function test_it_skips_broadcast_when_connection_is_log(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([AiReportSessionUpdated::class]);

        $dashboard = $this->createDashboard();
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => AnalyticsReportSessionStatus::Completed,
            'title' => 'Revenue',
        ]);

        app(AiBroadcastService::class)->reportSessionUpdated($session);

        Event::assertNotDispatched(AiReportSessionUpdated::class);
    }

    public function test_it_broadcasts_report_session_updates_when_reverb_enabled(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Event::fake([AiReportSessionUpdated::class]);

        $dashboard = $this->createDashboard();
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => AnalyticsReportSessionStatus::Completed,
            'title' => 'Revenue',
        ]);

        app(AiBroadcastService::class)->reportSessionUpdated($session);

        Event::assertDispatched(AiReportSessionUpdated::class, function (AiReportSessionUpdated $event) use ($session) {
            return $event->sessionId === $session->id
                && $event->status === AnalyticsReportSessionStatus::Completed->value;
        });
    }

    public function test_it_broadcasts_connector_builder_session_updates_when_reverb_enabled(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Event::fake([ConnectorBuilderSessionUpdated::class]);

        $dashboard = $this->createDashboard();
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => 'HubSpot',
        ]);

        app(AiBroadcastService::class)->connectorBuilderSessionUpdated($session);

        Event::assertDispatched(ConnectorBuilderSessionUpdated::class, function (ConnectorBuilderSessionUpdated $event) use ($session) {
            return $event->sessionId === $session->id
                && $event->status === ConnectorBuilderSessionStatus::Active->value;
        });
    }
}
