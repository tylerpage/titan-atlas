<?php

namespace Tests\Unit;

use App\Agents\ConnectorBuilderAgentContext;
use App\Ai\Agents\ConnectorBuilderAgent;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorBuilderAgentToolsTest extends TestCase
{
    use RefreshDatabase;
    public function test_connector_builder_tools_resolve_with_agent_context(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-tools']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-tools',
        ]);
        $user = User::factory()->create();
        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => ConnectorBuilderSessionStatus::Active,
        ]);

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
        );

        $agent = app(ConnectorBuilderAgent::class, ['context' => $context]);
        $tools = iterator_to_array($agent->tools());

        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $this->assertNotNull($tool->description());
        }
    }
}
