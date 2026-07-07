<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConnectorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreConnectionRequest;
use App\Http\Requests\Admin\TestConnectionRequest;
use App\Http\Requests\Admin\UpdateClientDashboardRequest;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\DashboardTemplate;
use App\Services\Admin\CreateConnectionService;
use App\Services\Admin\TestConnectionService;
use App\Services\Admin\UpdateClientDashboardService;
use App\Services\ConnectorBuilder\AiConnectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboards/Index', [
            'companies' => Company::query()->withCount('clientDashboards')->orderBy('name')->get(),
            'dashboards' => ClientDashboard::query()->with('company')->latest()->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Dashboards/Create', [
            ...$this->dashboardFormOptions(),
            'defaults' => [
                'timezone' => 'America/Chicago',
                'currency' => config('titan.currency', 'USD'),
                'default_date_range' => 'last_30_days',
                'attribution_window_days' => 30,
            ],
        ]);
    }

    public function store(
        \App\Http\Requests\Admin\StoreClientDashboardRequest $request,
        \App\Services\Admin\CreateClientDashboardService $creator,
    ): RedirectResponse {
        $dashboard = $creator->create($request->validated());

        return redirect()
            ->route('admin.dashboards.show', $dashboard)
            ->with('status', 'Dashboard "'.$dashboard->name.'" created.');
    }

    public function show(ClientDashboard $dashboard): Response
    {
        $dashboard->load([
            'company',
            'connections.syncRuns' => fn ($q) => $q->limit(5),
            'widgetPlacements',
        ]);

        return Inertia::render('Admin/Dashboards/Show', [
            'dashboard' => $this->serializeDashboard($dashboard),
        ]);
    }

    public function edit(ClientDashboard $dashboard): Response
    {
        return Inertia::render('Admin/Dashboards/Edit', [
            'dashboard' => [
                'id' => $dashboard->id,
                'company_id' => $dashboard->company_id,
                'company_name' => $dashboard->company->name,
                'name' => $dashboard->name,
                'slug' => $dashboard->slug,
                'timezone' => $dashboard->timezone,
                'default_date_range' => $dashboard->default_date_range,
                'show_summary_tab' => $dashboard->show_summary_tab,
                'attribution_window_days' => $dashboard->attribution_window_days,
                'primary_color' => $dashboard->primary_color,
                'secondary_color' => $dashboard->secondary_color,
                'custom_domain' => $dashboard->custom_domain,
                'logo_url' => $dashboard->logo_path
                    ? Storage::disk('public')->url($dashboard->logo_path)
                    : null,
            ],
            ...$this->dashboardFormOptions(),
        ]);
    }

    public function update(
        UpdateClientDashboardRequest $request,
        ClientDashboard $dashboard,
        UpdateClientDashboardService $updater,
    ): RedirectResponse {
        $updater->update(
            $dashboard,
            $request->validated(),
            $request->file('logo'),
        );

        return redirect()
            ->route('admin.dashboards.show', $dashboard)
            ->with('status', 'Dashboard "'.$dashboard->name.'" updated.');
    }

    public function createConnection(ClientDashboard $dashboard, AiConnectorService $aiConnectors): Response
    {
        $pendingOAuth = app(\App\Services\Google\GoogleOAuthPendingSession::class);

        return Inertia::render('Admin/Dashboards/Connections/Create', [
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'company_name' => $dashboard->company->name,
            ],
            'connectors' => collect(ConnectorType::cases())
                ->reject(fn (ConnectorType $type) => $type->isDynamic())
                ->map(fn (ConnectorType $type) => $type->toConnectorOption())
                ->values(),
            'aiConnectors' => $aiConnectors->templatesForDashboard($dashboard)->map(fn ($blueprint) => [
                'id' => $blueprint->id,
                'label' => $blueprint->label,
                'slug' => $blueprint->slug,
                'status' => $blueprint->status->value,
            'is_shared' => $blueprint->isShared(),
            'is_global' => $blueprint->isGlobal(),
            'streams_count' => $blueprint->streams()->count(),
            ])->values(),
            'defaultConnectorType' => $pendingOAuth->defaultConnectorTypeForDashboard($dashboard->id),
            'googleOauth' => $pendingOAuth->propsForDashboardDefault($dashboard->id),
        ]);
    }

    public function storeConnection(
        StoreConnectionRequest $request,
        ClientDashboard $dashboard,
        CreateConnectionService $creator,
    ): RedirectResponse {
        $connection = $creator->create($dashboard, $request->validated());

        return redirect()
            ->route('admin.connections.show', $connection)
            ->with('status', 'Connection "'.$connection->name.'" added. Backfill queued.');
    }

    public function testConnection(
        TestConnectionRequest $request,
        TestConnectionService $service,
    ): JsonResponse {
        $validated = $request->validated();
        $result = $service->test(
            ConnectorType::from($validated['connector_type']),
            $validated['credentials'],
        );

        return response()->json([
            'valid' => $result->valid,
            'message' => $result->message,
            'debug' => $result->debug,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dashboardFormOptions(): array
    {
        return [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'templates' => DashboardTemplate::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'description']),
            'dateRangePresets' => config('titan.date_range_presets'),
            'attributionWindows' => config('titan.attribution_windows'),
            'timezones' => [
                'America/New_York' => 'Eastern (US)',
                'America/Chicago' => 'Central (US)',
                'America/Denver' => 'Mountain (US)',
                'America/Los_Angeles' => 'Pacific (US)',
                'UTC' => 'UTC',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDashboard(ClientDashboard $dashboard): array
    {
        return [
            'id' => $dashboard->id,
            'slug' => $dashboard->slug,
            'name' => $dashboard->name,
            'is_syncing' => $dashboard->isSyncing(),
            'company' => [
                'id' => $dashboard->company->id,
                'name' => $dashboard->company->name,
            ],
            'connections' => $dashboard->connections->map(fn ($connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'connector_type' => $connection->connector_type->value,
                'connector_label' => $connection->connector_type->label(),
                'is_active' => $connection->is_active,
                'sync_status' => $connection->sync_status->value,
                'sync_error' => $connection->sync_error,
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                'data_from_date' => $connection->data_from_date?->toDateString(),
                'data_through_date' => $connection->data_through_date?->toDateString(),
                'sync_runs' => $connection->syncRuns->map(fn ($run) => [
                    'id' => $run->id,
                    'type' => $run->type->value,
                    'status' => $run->status->value,
                    'records_fetched' => $run->records_fetched,
                    'records_written' => $run->records_written,
                    'progress_from_date' => $run->progress_from_date?->toDateString(),
                    'progress_through_date' => $run->progress_through_date?->toDateString(),
                ])->values(),
            ])->values(),
            'widget_placements' => $dashboard->widgetPlacements->map(fn ($widget) => [
                'id' => $widget->id,
                'title' => $widget->title,
                'widget_type' => $widget->widget_type->value,
                'widget_type_label' => $widget->widget_type->label(),
            ])->values(),
        ];
    }
}
