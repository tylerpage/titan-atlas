<?php

namespace Tests\Feature;

use App\Enums\DateComparison;
use App\Enums\WidgetType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\MetricSnapshot;
use App\Services\Analytics\WidgetDataService;
use App\Support\MetricComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboard(): ClientDashboard
    {
        $company = Company::query()->create(['name' => 'Test Co', 'slug' => 'test-co']);

        return ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Test Dashboard',
            'slug' => 'test',
        ]);
    }

    public function test_previous_period_comparison_calculates_percent_change(): void
    {
        $dashboard = $this->createDashboard();
        $service = app(WidgetDataService::class);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2024-06-01',
            'metric_key' => 'revenue',
            'metric_value' => 200,
            'currency' => 'USD',
        ]);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2024-06-02',
            'metric_key' => 'revenue',
            'metric_value' => 300,
            'currency' => 'USD',
        ]);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2024-05-31',
            'metric_key' => 'revenue',
            'metric_value' => 100,
            'currency' => 'USD',
        ]);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2024-05-30',
            'metric_key' => 'revenue',
            'metric_value' => 100,
            'currency' => 'USD',
        ]);

        $data = $service->dataFor(
            $dashboard,
            WidgetType::Revenue,
            'custom',
            ['start' => '2024-06-01', 'end' => '2024-06-02'],
            DateComparison::PreviousPeriod,
        );

        $this->assertSame(500.0, $data['total']);
        $this->assertSame(200.0, $data['comparison_total']);
        $this->assertSame(150.0, $data['change_percent']);
    }

    public function test_year_over_year_comparison_uses_same_dates_last_year(): void
    {
        $dashboard = $this->createDashboard();
        $service = app(WidgetDataService::class);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2025-06-01',
            'metric_key' => 'orders',
            'metric_value' => 10,
            'currency' => 'USD',
        ]);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2024-06-01',
            'metric_key' => 'orders',
            'metric_value' => 5,
            'currency' => 'USD',
        ]);

        [$start, $end] = $service->resolveDateRange($dashboard, 'custom', [
            'start' => '2025-06-01',
            'end' => '2025-06-01',
        ]);

        $range = $service->resolveComparisonRange($start, $end, DateComparison::YearOverYear);

        $this->assertSame('2024-06-01', $range[0]->toDateString());
        $this->assertSame('2024-06-01', $range[1]->toDateString());

        $data = $service->dataFor(
            $dashboard,
            WidgetType::Orders,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
            DateComparison::YearOverYear,
        );

        $this->assertSame(10.0, $data['total']);
        $this->assertSame(5.0, $data['comparison_total']);
        $this->assertSame(100.0, $data['change_percent']);
    }

    public function test_percent_change_helper(): void
    {
        $this->assertSame(50.0, MetricComparison::percentChange(150, 100));
        $this->assertSame(-25.0, MetricComparison::percentChange(75, 100));
        $this->assertSame(100.0, MetricComparison::percentChange(10, 0));
    }
}
