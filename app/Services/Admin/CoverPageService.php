<?php

namespace App\Services\Admin;

use App\Models\ClientDashboard;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use Illuminate\Support\Facades\DB;

class CoverPageService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ClientDashboard $dashboard, array $data): CoverPage
    {
        $sortOrder = (int) ($dashboard->coverPages()->max('sort_order') ?? 0) + 1;
        $isActive = (bool) ($data['is_active'] ?? false);

        return DB::transaction(function () use ($dashboard, $data, $sortOrder, $isActive) {
            if ($isActive) {
                $this->deactivateSiblings($dashboard);
            }

            return CoverPage::query()->create([
                'client_dashboard_id' => $dashboard->id,
                'title' => $data['title'],
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CoverPage $coverPage, array $data): CoverPage
    {
        $isActive = (bool) ($data['is_active'] ?? $coverPage->is_active);

        return DB::transaction(function () use ($coverPage, $data, $isActive) {
            if ($isActive && ! $coverPage->is_active) {
                $this->deactivateSiblings($coverPage->clientDashboard);
            }

            $coverPage->update([
                'title' => $data['title'],
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'is_active' => $isActive,
            ]);

            return $coverPage->fresh();
        });
    }

    public function activate(CoverPage $coverPage): CoverPage
    {
        return DB::transaction(function () use ($coverPage) {
            $this->deactivateSiblings($coverPage->clientDashboard);
            $coverPage->update(['is_active' => true]);

            return $coverPage->fresh();
        });
    }

    public function duplicate(CoverPage $coverPage): CoverPage
    {
        return DB::transaction(function () use ($coverPage) {
            $sortOrder = (int) ($coverPage->clientDashboard->coverPages()->max('sort_order') ?? 0) + 1;

            $duplicate = CoverPage::query()->create([
                'client_dashboard_id' => $coverPage->client_dashboard_id,
                'title' => $coverPage->title.' (copy)',
                'period_start' => $coverPage->period_start,
                'period_end' => $coverPage->period_end,
                'is_active' => false,
                'sort_order' => $sortOrder,
            ]);

            foreach ($coverPage->blocks as $block) {
                CoverPageBlock::query()->create([
                    'cover_page_id' => $duplicate->id,
                    'block_type' => $block->block_type,
                    'sort_order' => $block->sort_order,
                    'column_span' => $block->column_span,
                    'configuration' => $block->configuration,
                ]);
            }

            return $duplicate->load('blocks');
        });
    }

    public function delete(CoverPage $coverPage): void
    {
        $coverPage->delete();
    }

    protected function deactivateSiblings(ClientDashboard $dashboard): void
    {
        CoverPage::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->update(['is_active' => false]);
    }
}
