<?php

namespace Tests\Feature;

use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Services\Analytics\MetricRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_builtin_metrics_are_seeded(): void
    {
        $registry = app(MetricRegistry::class);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $metrics = $registry->forDashboard($dashboard);

        $this->assertGreaterThanOrEqual(12, $metrics->count());
        $this->assertNotNull($registry->findForDashboard($dashboard, 'revenue'));
        $this->assertNotNull($registry->findForDashboard($dashboard, 'avg_order_value'));
        $this->assertNotNull($registry->findForDashboard($dashboard, 'search_clicks'));
    }

    public function test_explain_returns_metric_documentation(): void
    {
        $registry = app(MetricRegistry::class);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $metric = $registry->findForDashboard($dashboard, 'revenue');
        $explained = $registry->explain($metric);

        $this->assertSame('revenue', $explained['slug']);
        $this->assertArrayHasKey('sql_template', $explained);
        $this->assertArrayHasKey('visualization_type', $explained);
    }
}
