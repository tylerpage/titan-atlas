<?php

namespace Tests\Unit;

use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\DashboardAgentMemory;
use App\Models\User;
use App\Services\AI\DashboardAgentMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAgentMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_upserts_by_memory_key(): void
    {
        $dashboard = $this->dashboard();
        $service = app(DashboardAgentMemoryService::class);

        $service->remember($dashboard, [
            'memory_key' => 'google_ads:schema',
            'category' => 'schema',
            'title' => 'Schema v1',
            'content' => 'first',
        ], User::factory()->create());

        $service->remember($dashboard, [
            'memory_key' => 'google_ads:schema',
            'category' => 'schema',
            'title' => 'Schema v2',
            'content' => 'updated',
        ], User::factory()->create());

        $this->assertSame(1, DashboardAgentMemory::query()->count());
        $this->assertSame('updated', DashboardAgentMemory::query()->first()->content);
    }

    public function test_for_prompt_respects_character_budget(): void
    {
        config(['titan.agent_memory.max_injected' => 10, 'titan.agent_memory.max_content_chars' => 600]);

        $dashboard = $this->dashboard();
        $service = app(DashboardAgentMemoryService::class);

        for ($i = 1; $i <= 5; $i++) {
            $service->remember($dashboard, [
                'memory_key' => "key:{$i}",
                'category' => 'general',
                'title' => "Memory {$i}",
                'content' => str_repeat('x', 200),
            ]);
        }

        $block = $service->forPrompt($dashboard, 'reporting');

        $this->assertStringContainsString('Dashboard memory', $block);
        $this->assertLessThanOrEqual(600, strlen($block));
        $this->assertLessThan(5, substr_count($block, '[general]'));
    }

    protected function dashboard(): ClientDashboard
    {
        return ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-mem'])->id,
            'name' => 'Main',
            'slug' => 'main-mem',
        ]);
    }
}
