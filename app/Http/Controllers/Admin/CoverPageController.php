<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CoverPageDataSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCoverPageRequest;
use App\Http\Requests\Admin\UpdateCoverPageRequest;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\CoverPage;
use App\Services\Admin\CoverPageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CoverPageController extends Controller
{
    public function index(ClientDashboard $dashboard): Response
    {
        $dashboard->load('company');

        $coverPages = $dashboard->coverPages()
            ->withCount('blocks')
            ->get()
            ->map(fn (CoverPage $page) => $this->serializeCoverPage($page));

        return Inertia::render('Admin/Dashboards/CoverPages/Index', [
            'dashboard' => [
                'id' => $dashboard->id,
                'slug' => $dashboard->slug,
                'name' => $dashboard->name,
                'company_name' => $dashboard->company->name,
            ],
            'coverPages' => $coverPages,
        ]);
    }

    public function create(ClientDashboard $dashboard): Response
    {
        $dashboard->load('company');

        return Inertia::render('Admin/Dashboards/CoverPages/Create', [
            'dashboard' => [
                'id' => $dashboard->id,
                'slug' => $dashboard->slug,
                'name' => $dashboard->name,
                'company_name' => $dashboard->company->name,
            ],
        ]);
    }

    public function store(
        StoreCoverPageRequest $request,
        ClientDashboard $dashboard,
        CoverPageService $service,
    ): RedirectResponse {
        $coverPage = $service->create($dashboard, $request->validated());

        return redirect()
            ->route('admin.cover-pages.edit', $coverPage)
            ->with('status', 'Cover page "'.$coverPage->title.'" created.');
    }

    public function edit(CoverPage $coverPage): Response
    {
        $coverPage->load(['clientDashboard.company', 'blocks', 'clientDashboard.connections']);

        $savedReports = $coverPage->clientDashboard->analyticsReports()
            ->active()
            ->get(['id', 'prompt', 'visualization_type'])
            ->map(fn ($report) => [
                'id' => $report->id,
                'prompt' => $report->prompt,
                'visualization_type' => $report->visualization_type->value,
            ]);

        return Inertia::render('Admin/Dashboards/CoverPages/Edit', [
            'dashboard' => [
                'id' => $coverPage->client_dashboard_id,
                'slug' => $coverPage->clientDashboard->slug,
                'name' => $coverPage->clientDashboard->name,
                'company_name' => $coverPage->clientDashboard->company->name,
            ],
            'coverPage' => $this->serializeCoverPage($coverPage, includeBlocks: true),
            'savedReports' => $savedReports,
            'connections' => $coverPage->clientDashboard->connections->map(fn ($connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'connector_label' => $connection->connector_type->label(),
            ])->values(),
            'blockTypes' => collect(\App\Enums\CoverPageBlockType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ])->values(),
            'metricKeys' => [
                ['value' => 'revenue', 'label' => 'Revenue'],
                ['value' => 'orders', 'label' => 'Orders'],
                ['value' => 'avg_order_value', 'label' => 'Avg. order value'],
                ['value' => 'ad_spend', 'label' => 'Ad spend'],
                ['value' => 'organic_sessions', 'label' => 'Organic sessions'],
            ],
        ]);
    }

    public function update(
        UpdateCoverPageRequest $request,
        CoverPage $coverPage,
        CoverPageService $service,
    ): RedirectResponse {
        $coverPage = $service->update($coverPage, $request->validated());

        return redirect()
            ->route('admin.cover-pages.edit', $coverPage)
            ->with('status', 'Cover page "'.$coverPage->title.'" updated.');
    }

    public function activate(CoverPage $coverPage, CoverPageService $service): RedirectResponse
    {
        $service->activate($coverPage);

        return back()->with('status', 'Cover page "'.$coverPage->title.'" is now active.');
    }

    public function duplicate(CoverPage $coverPage, CoverPageService $service): RedirectResponse
    {
        $duplicate = $service->duplicate($coverPage);

        return redirect()
            ->route('admin.cover-pages.edit', $duplicate)
            ->with('status', 'Cover page duplicated.');
    }

    public function destroy(CoverPage $coverPage, CoverPageService $service): RedirectResponse
    {
        $dashboard = $coverPage->clientDashboard;
        $title = $coverPage->title;
        $service->delete($coverPage);

        return redirect()
            ->route('admin.dashboards.cover-pages.index', $dashboard)
            ->with('status', 'Cover page "'.$title.'" deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeCoverPage(CoverPage $coverPage, bool $includeBlocks = false): array
    {
        $data = [
            'id' => $coverPage->id,
            'title' => $coverPage->title,
            'period_start' => $coverPage->period_start?->toDateString(),
            'period_end' => $coverPage->period_end?->toDateString(),
            'is_active' => $coverPage->is_active,
            'is_draft' => $coverPage->is_draft,
            'sort_order' => $coverPage->sort_order,
            'blocks_count' => $coverPage->blocks_count ?? $coverPage->blocks->count(),
        ];

        if ($includeBlocks) {
            $reportIds = $coverPage->blocks
                ->map(fn ($block) => ($block->configuration ?? [])['report_id'] ?? null)
                ->filter()
                ->unique()
                ->values();

            $reports = AnalyticsReport::query()
                ->where('client_dashboard_id', $coverPage->client_dashboard_id)
                ->whereIn('id', $reportIds)
                ->get()
                ->keyBy('id');

            $data['blocks'] = $coverPage->blocks->map(function ($block) use ($reports) {
                $configuration = $block->configuration ?? $block->block_type->defaultConfiguration();
                $payload = [
                    'id' => $block->id,
                    'block_type' => $block->block_type->value,
                    'block_type_label' => $block->block_type->label(),
                    'sort_order' => $block->sort_order,
                    'column_span' => $block->column_span,
                    'configuration' => $configuration,
                ];

                if (($configuration['data_source'] ?? '') === CoverPageDataSource::Report->value) {
                    $report = $reports->get((int) ($configuration['report_id'] ?? 0));
                    $payload['ai_report'] = [
                        'id' => (int) ($configuration['report_id'] ?? 0),
                        'prompt' => $report?->prompt ?? 'AI report (missing)',
                    ];
                }

                return $payload;
            })->values();
        }

        return $data;
    }
}
