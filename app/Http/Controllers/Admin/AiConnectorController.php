<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiConnectorConnectionRequest;
use App\Http\Requests\Admin\TestAiConnectorConnectionRequest;
use App\Http\Requests\Admin\UpdateAiConnectorRequest;
use App\Enums\ConnectorType;
use App\Ingestion\ConnectorRegistry;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBlueprint;
use App\Services\ConnectorBuilder\AiConnectorService;
use App\Services\ConnectorBuilder\ConnectorBuilderResumeService;
use App\Services\ConnectorBuilder\CreateDynamicConnectionService;
use App\Support\DynamicConnectorBaseUrl;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiConnectorController extends Controller
{
    public function index(AiConnectorService $connectors): Response
    {
        $companies = Company::query()
            ->withCount('connectorBlueprints')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $blueprints = ConnectorBlueprint::query()
            ->with(['company', 'dashboard'])
            ->withCount('connections')
            ->latest('updated_at')
            ->get()
            ->map(fn (ConnectorBlueprint $blueprint) => $this->serializeBlueprintSummary($blueprint));

        return Inertia::render('Admin/AiConnectors/Index', [
            'companies' => $companies,
            'blueprints' => $blueprints,
        ]);
    }

    public function companyIndex(Company $company, AiConnectorService $connectors): Response
    {
        return Inertia::render('Admin/AiConnectors/Index', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
            'blueprints' => $connectors->listForCompany($company)
                ->map(fn (ConnectorBlueprint $blueprint) => $this->serializeBlueprintSummary($blueprint)),
        ]);
    }

    public function edit(ConnectorBlueprint $blueprint): Response
    {
        $blueprint->load(['company', 'dashboard'])->loadCount('connections');

        return Inertia::render('Admin/AiConnectors/Edit', [
            'blueprint' => $this->serializeBlueprintSummary($blueprint),
            'statuses' => collect(\App\Enums\ConnectorBlueprintStatus::cases())
                ->map(fn ($status) => ['value' => $status->value, 'label' => str($status->value)->headline()])
                ->values(),
        ]);
    }

    public function update(
        UpdateAiConnectorRequest $request,
        ConnectorBlueprint $blueprint,
        AiConnectorService $connectors,
    ): RedirectResponse {
        $connectors->update($blueprint, $request->validated());

        return redirect()
            ->route('admin.ai-connectors.show', $blueprint)
            ->with('status', 'AI connector updated.');
    }

    public function destroy(ConnectorBlueprint $blueprint, AiConnectorService $connectors): RedirectResponse
    {
        $companyId = $blueprint->company_id;

        try {
            $connectors->delete($blueprint);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route($blueprint->isGlobal() ? 'admin.ai-connectors.index' : 'admin.companies.ai-connectors.index', $blueprint->isGlobal() ? [] : $companyId)
            ->with('status', 'AI connector deleted.');
    }

    public function share(ConnectorBlueprint $blueprint, AiConnectorService $connectors): RedirectResponse
    {
        $blueprint->load('company');
        $connectors->share($blueprint);

        return back()->with('status', 'AI connector is now shared across all dashboards in '.$blueprint->company->name.'.');
    }

    public function shareGlobally(ConnectorBlueprint $blueprint, AiConnectorService $connectors): RedirectResponse
    {
        try {
            $connectors->shareGlobally($blueprint);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', 'AI connector is now available to all companies.');
    }

    public function resumeChat(
        ConnectorBlueprint $blueprint,
        ConnectorBuilderResumeService $resume,
    ): RedirectResponse {
        try {
            $resolved = $resume->resolve($blueprint, request()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('admin.dashboards.connections.ai-create', [
            'dashboard' => $resolved['dashboard']->id,
            'session' => $resolved['session']->id,
        ]);
    }

    public function createConnection(
        ClientDashboard $dashboard,
        ConnectorBlueprint $blueprint,
        AiConnectorService $connectors,
    ): Response|RedirectResponse {
        if (! $connectors->isAvailableForDashboard($blueprint, $dashboard)) {
            abort(404);
        }

        $blueprint->load('streams');

        return Inertia::render('Admin/Dashboards/Connections/ConnectTemplate', [
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'company_name' => $dashboard->company->name,
            ],
            'blueprint' => $this->serializeBlueprintForConnect($blueprint),
        ]);
    }

    public function storeConnection(
        StoreAiConnectorConnectionRequest $request,
        ClientDashboard $dashboard,
        ConnectorBlueprint $blueprint,
        AiConnectorService $connectors,
        CreateDynamicConnectionService $creator,
    ): RedirectResponse {
        if (! $connectors->isAvailableForDashboard($blueprint, $dashboard)) {
            abort(404);
        }

        $validated = $request->validated();

        $connection = $creator->create(
            dashboard: $dashboard,
            blueprint: $blueprint,
            name: $validated['name'],
            credentials: $validated['credentials'],
        );

        return redirect()
            ->route('admin.connections.show', $connection)
            ->with('status', 'Connection "'.$connection->name.'" added. Backfill queued.');
    }

    public function testConnection(
        TestAiConnectorConnectionRequest $request,
        ClientDashboard $dashboard,
        ConnectorBlueprint $blueprint,
        AiConnectorService $connectors,
        ConnectorRegistry $connectorRegistry,
    ): \Illuminate\Http\JsonResponse {
        if (! $connectors->isAvailableForDashboard($blueprint, $dashboard)) {
            abort(404);
        }

        $connection = new \App\Models\Connection([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'encrypted_credentials' => array_map(
                fn ($value) => is_string($value) ? trim($value) : $value,
                $request->validated('credentials'),
            ),
        ]);

        $result = $connectorRegistry->make(ConnectorType::Dynamic)->validateCredentials($connection);

        return response()->json([
            'valid' => $result->valid,
            'message' => $result->message,
            'debug' => $result->debug,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeBlueprintSummary(ConnectorBlueprint $blueprint): array
    {
        return [
            'id' => $blueprint->id,
            'slug' => $blueprint->slug,
            'label' => $blueprint->label,
            'status' => $blueprint->status->value,
            'is_shared' => $blueprint->isShared(),
            'is_global' => $blueprint->isGlobal(),
            'chat_url' => route('admin.ai-connectors.chat', $blueprint),
            'original_prompt' => $blueprint->original_prompt,
            'connections_count' => $blueprint->connections_count ?? $blueprint->connections()->count(),
            'streams_count' => $blueprint->streams_count ?? $blueprint->streams()->count(),
            'company' => [
                'id' => $blueprint->company_id,
                'name' => $blueprint->company?->name,
            ],
            'dashboard' => $blueprint->dashboard ? [
                'id' => $blueprint->dashboard->id,
                'name' => $blueprint->dashboard->name,
            ] : null,
            'updated_at' => $blueprint->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeBlueprintForConnect(ConnectorBlueprint $blueprint): array
    {
        return [
            'id' => $blueprint->id,
            'slug' => $blueprint->slug,
            'label' => $blueprint->label,
            'status' => $blueprint->status->value,
            'credential_fields' => $blueprint->credential_schema ?? [],
            'sync_config' => $blueprint->sync_config ?? [],
            'requires_base_url_per_dashboard' => DynamicConnectorBaseUrl::requiresPerDashboard($blueprint),
            'streams_count' => $blueprint->streams->count(),
        ];
    }
}
