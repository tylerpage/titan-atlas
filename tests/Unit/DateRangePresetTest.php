<?php

namespace Tests\Unit;

use App\Models\ClientDashboard;
use App\Models\Company;
use App\Services\Analytics\WidgetDataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateRangePresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_this_month_is_current_calendar_month_through_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 15:00:00'));

        $dashboard = $this->dashboard();
        [$start, $end] = app(WidgetDataService::class)->resolveDateRange($dashboard, 'this_month');

        $this->assertSame('2026-06-01', $start->toDateString());
        $this->assertSame('2026-06-24', $end->toDateString());
    }

    public function test_this_month_from_january_starts_on_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $dashboard = $this->dashboard();
        [$start, $end] = app(WidgetDataService::class)->resolveDateRange($dashboard, 'this_month');

        $this->assertSame('2026-01-01', $start->toDateString());
        $this->assertSame('2026-01-10', $end->toDateString());
    }

    public function test_last_month_is_previous_full_calendar_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 15:00:00'));

        $dashboard = $this->dashboard();
        [$start, $end] = app(WidgetDataService::class)->resolveDateRange($dashboard, 'last_month');

        $this->assertSame('2026-05-01', $start->toDateString());
        $this->assertSame('2026-05-31', $end->toDateString());
    }

    public function test_last_month_from_january_wraps_to_december(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

        $dashboard = $this->dashboard();
        [$start, $end] = app(WidgetDataService::class)->resolveDateRange($dashboard, 'last_month');

        $this->assertSame('2025-12-01', $start->toDateString());
        $this->assertSame('2025-12-31', $end->toDateString());
    }

    public function test_last_year_is_previous_full_calendar_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 15:00:00'));

        $dashboard = $this->dashboard();
        [$start, $end] = app(WidgetDataService::class)->resolveDateRange($dashboard, 'last_year');

        $this->assertSame('2025-01-01', $start->toDateString());
        $this->assertSame('2025-12-31', $end->toDateString());
    }

    public function test_last_year_from_january_uses_prior_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-03 09:00:00'));

        $dashboard = $this->dashboard();
        [$start, $end] = app(WidgetDataService::class)->resolveDateRange($dashboard, 'last_year');

        $this->assertSame('2025-01-01', $start->toDateString());
        $this->assertSame('2025-12-31', $end->toDateString());
    }

    protected function dashboard(): ClientDashboard
    {
        return ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-range'])->id,
            'name' => 'Main',
            'slug' => 'main-range',
        ]);
    }
}
