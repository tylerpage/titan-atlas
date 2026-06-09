<?php

namespace App\Services\Client;

use App\Models\ClientDashboard;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use App\Services\Analytics\ReportBlockResolver;
use Carbon\Carbon;

class SavedDashboardService
{
    public function __construct(protected ReportBlockResolver $reportBlocks) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ClientDashboard $dashboard, User $user, array $data): SavedDashboard
    {
        $sortOrder = (int) SavedDashboard::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->max('sort_order');

        return SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'created_by' => $user->id,
            'sort_order' => $sortOrder + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SavedDashboard $board, array $data): SavedDashboard
    {
        $board->fill([
            'title' => $data['title'] ?? $board->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $board->description,
        ]);
        $board->save();

        return $board->fresh();
    }

    public function delete(SavedDashboard $board): void
    {
        $board->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function pinBlock(SavedDashboard $board, array $data): SavedDashboardBlock
    {
        $sortOrder = (int) $board->blocks()->max('sort_order');

        return SavedDashboardBlock::query()->create([
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $data['analytics_report_id'],
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'column_span' => max(1, min(2, (int) ($data['column_span'] ?? 1))),
            'sort_order' => $sortOrder + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBlock(SavedDashboardBlock $block, array $data): SavedDashboardBlock
    {
        $block->fill([
            'title' => array_key_exists('title', $data) ? $data['title'] : $block->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $block->description,
            'column_span' => array_key_exists('column_span', $data)
                ? max(1, min(2, (int) $data['column_span']))
                : $block->column_span,
        ]);
        $block->save();

        return $block->fresh();
    }

    public function unpinBlock(SavedDashboardBlock $block): void
    {
        $block->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveBoard(
        SavedDashboard $board,
        ClientDashboard $dashboard,
        Carbon $start,
        Carbon $end,
    ): array {
        $board->load(['blocks.report']);

        return [
            'id' => $board->id,
            'title' => $board->title,
            'description' => $board->description,
            'blocks' => $board->blocks
                ->map(function (SavedDashboardBlock $block) use ($dashboard, $start, $end) {
                    $report = $block->report;

                    if (! $report) {
                        return null;
                    }

                    $resolved = $this->reportBlocks->resolve(
                        $report,
                        $dashboard,
                        $start,
                        $end,
                        $block->title,
                        $block->description,
                    );

                    if (! $resolved) {
                        return null;
                    }

                    return array_merge($resolved, [
                        'id' => $block->id,
                        'column_span' => $block->column_span,
                        'description' => $block->description,
                    ]);
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
