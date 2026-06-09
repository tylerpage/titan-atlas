<?php

namespace Tests\Feature;

use App\Enums\CoverPageBlockType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CoverPageBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function createCoverPage(): CoverPage
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'June 2025 Summary',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_admin_can_add_reorder_and_update_blocks(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $coverPage = $this->createCoverPage();

        $response = $this->actingAs($admin)
            ->post(route('admin.cover-pages.blocks.store', $coverPage), [
                'block_type' => CoverPageBlockType::StatCard->value,
            ])
            ->assertRedirect();

        $block = CoverPageBlock::query()->first();

        $response->assertSessionHas('focused_block_id', $block->id);

        $this->assertNotNull($block);
        $this->assertSame(CoverPageBlockType::StatCard, $block->block_type);

        $this->actingAs($admin)
            ->post(route('admin.cover-page-blocks.update', $block), [
                'configuration' => [
                    'header' => 'Revenue',
                    'text' => '$10k',
                    'tooltip' => 'Month over month',
                    'improvement_percent' => 12.5,
                    'data_source' => 'manual',
                ],
            ])
            ->assertRedirect();

        $this->assertSame('Revenue', $block->fresh()->configuration['header']);

        $second = CoverPageBlock::query()->create([
            'cover_page_id' => $coverPage->id,
            'block_type' => CoverPageBlockType::LineChart,
            'sort_order' => 2,
            'column_span' => 2,
            'configuration' => CoverPageBlockType::LineChart->defaultConfiguration(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.cover-page-blocks.move-up', $second))
            ->assertRedirect();

        $this->assertTrue($block->fresh()->sort_order > $second->fresh()->sort_order);
    }

    public function test_admin_can_import_csv_into_line_chart_block(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $coverPage = $this->createCoverPage();
        $block = CoverPageBlock::query()->create([
            'cover_page_id' => $coverPage->id,
            'block_type' => CoverPageBlockType::LineChart,
            'sort_order' => 1,
            'column_span' => 2,
            'configuration' => CoverPageBlockType::LineChart->defaultConfiguration(),
        ]);

        $csv = UploadedFile::fake()->createWithContent('series.csv', "date,value\n2025-06-01,100\n2025-06-02,150\n");

        $this->actingAs($admin)
            ->post(route('admin.cover-page-blocks.import-csv', $block), [
                'csv' => $csv,
            ])
            ->assertRedirect();

        $series = $block->fresh()->configuration['series'];

        $this->assertCount(2, $series);
        $this->assertSame('2025-06-01', $series[0]['date']);
        $this->assertEquals(100.0, $series[0]['value']);
    }

    public function test_line_chart_block_includes_title_and_insights_on_client_view(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $coverPage = CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Summary',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        CoverPageBlock::query()->create([
            'cover_page_id' => $coverPage->id,
            'block_type' => CoverPageBlockType::LineChart,
            'sort_order' => 1,
            'column_span' => 2,
            'configuration' => [
                'title' => 'Daily revenue',
                'insights' => '<p>Revenue peaked mid-month.</p>',
                'data_source' => 'manual',
                'series' => [
                    ['date' => '2025-06-01', 'value' => 100],
                    ['date' => '2025-06-02', 'value' => 150],
                ],
            ],
        ]);

        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get(route('client.dashboard.show', ['dashboard' => $dashboard->id, 'tab' => 'cover']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('coverPageData.blocks.0.type', 'line_chart')
                ->where('coverPageData.blocks.0.title', 'Daily revenue')
                ->where('coverPageData.blocks.0.insights', '<p>Revenue peaked mid-month.</p>')
            );
    }
}
