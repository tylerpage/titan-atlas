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
use Tests\TestCase;

class CoverPageRichTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_save_rich_text_block(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
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

        $this->actingAs($admin)
            ->post(route('admin.cover-pages.blocks.store', $coverPage), [
                'block_type' => CoverPageBlockType::RichText->value,
            ])
            ->assertRedirect();

        $block = CoverPageBlock::query()->first();
        $this->assertSame(CoverPageBlockType::RichText, $block->block_type);

        $this->actingAs($admin)
            ->post(route('admin.cover-page-blocks.update', $block), [
                'column_span' => 2,
                'configuration' => [
                    'title' => 'Highlights',
                    'body' => '<p>Strong month</p><script>alert(1)</script><p><a href="https://example.com">Read more</a></p>',
                ],
            ])
            ->assertRedirect();

        $block->refresh();
        $this->assertStringContainsString('<p>Strong month</p>', $block->configuration['body']);
        $this->assertStringNotContainsString('<script>', $block->configuration['body']);
        $this->assertStringContainsString('https://example.com', $block->configuration['body']);
    }

    public function test_rich_text_block_renders_on_client_summary_tab(): void
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
            'block_type' => CoverPageBlockType::RichText,
            'sort_order' => 1,
            'column_span' => 2,
            'configuration' => [
                'title' => 'Notes',
                'body' => '<p>June performed well.</p>',
            ],
        ]);

        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get(route('client.dashboard.show', ['dashboard' => $dashboard->id, 'tab' => 'cover']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('coverPageData.blocks.0.type', 'rich_text')
                ->where('coverPageData.blocks.0.title', 'Notes')
                ->where('coverPageData.blocks.0.body', '<p>June performed well.</p>')
            );
    }
}
