<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\MetricSnapshot;
use App\Models\User;
use App\Services\Analytics\WidgetDataService;
use App\Services\Auth\ImpersonationService;
use App\Support\MetricDimensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TitanPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_admin_can_view_management_dashboards(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Index')
                ->has('companies')
                ->has('dashboards')
            );
    }

    public function test_client_cannot_access_admin_area(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($client)
            ->get(route('admin.dashboards.index'))
            ->assertForbidden();
    }

    public function test_client_can_view_assigned_dashboard(): void
    {
        $this->seed();

        $client = User::query()->where('email', 'client@acme.test')->firstOrFail();
        $dashboard = ClientDashboard::query()->where('slug', 'main')->firstOrFail();

        $this->actingAs($client)
            ->get(route('client.dashboard.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('dashboard.name', 'Acme Main Dashboard')
                ->where('dashboard.logo_url', null)
            );
    }

    public function test_client_dashboard_includes_logo_url_when_set(): void
    {
        Storage::fake('public');
        $this->seed();

        $client = User::query()->where('email', 'client@acme.test')->firstOrFail();
        $dashboard = ClientDashboard::query()->where('slug', 'main')->firstOrFail();
        $logoPath = UploadedFile::fake()->image('logo.png')->store('dashboard-logos/'.$dashboard->id, 'public');
        $dashboard->update(['logo_path' => $logoPath]);

        $this->actingAs($client)
            ->get(route('client.dashboard.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('dashboard.logo_url', Storage::disk('public')->url($logoPath))
            );
    }

    public function test_connector_registry_resolves_all_connectors(): void
    {
        $registry = app(\App\Ingestion\ConnectorRegistry::class);

        foreach ($registry->connectors() as $type => $class) {
            $connector = $registry->make($type);
            $this->assertSame($type, $connector->type());
        }

        $this->assertCount(6, $registry->connectors());
    }

    public function test_metric_snapshots_support_dimensional_rows(): void
    {
        $this->seed();

        $dashboard = ClientDashboard::query()->where('slug', 'main')->firstOrFail();
        $date = '2020-01-15';

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => $date,
            'metric_key' => 'orders',
            'metric_value' => 1,
            'currency' => 'USD',
            'dimensions' => ['source' => 'google', 'medium' => 'cpc'],
        ]);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => $date,
            'metric_key' => 'orders',
            'metric_value' => 2,
            'currency' => 'USD',
            'dimensions' => ['source' => 'direct', 'medium' => 'none'],
        ]);

        $this->assertSame(2, MetricSnapshot::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->where('metric_key', 'orders')
            ->whereDate('snapshot_date', $date)
            ->count());
    }

    public function test_custom_date_range_filters_widget_data(): void
    {
        $this->seed();

        $dashboard = ClientDashboard::query()->where('slug', 'main')->firstOrFail();
        $service = app(WidgetDataService::class);

        [$start, $end] = $service->resolveDateRange($dashboard, 'custom', [
            'start' => now()->subDays(2)->toDateString(),
            'end' => now()->toDateString(),
        ]);

        $this->assertTrue($start->isToday() || $start->lt(now()));
        $this->assertTrue($end->isToday());
    }

    public function test_user_can_belong_to_multiple_companies(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client]);
        $alpha = Company::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Company::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $user->companies()->attach([$alpha->id, $beta->id]);

        $this->assertTrue($user->belongsToCompany($alpha));
        $this->assertTrue($user->belongsToCompany($beta));
    }

    public function test_admin_can_impersonate_client(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@titan.test')->firstOrFail();
        $client = User::query()->where('email', 'client@acme.test')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.impersonate.store', $client))
            ->assertRedirect();

        $this->assertTrue(app(ImpersonationService::class)->isImpersonating());
        $this->assertSame($client->id, auth()->id());
    }

    public function test_dimension_hash_is_stable(): void
    {
        $hashA = MetricDimensions::hash(['source' => 'google', 'medium' => 'cpc']);
        $hashB = MetricDimensions::hash(['medium' => 'cpc', 'source' => 'google']);

        $this->assertSame($hashA, $hashB);
    }
}
