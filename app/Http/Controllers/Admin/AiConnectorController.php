<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportAiConnectorRequest;
use App\Http\Requests\Admin\StoreAiConnectorConnectionRequest;
use App\Http\Requests\Admin\StoreGlobalAiConnectorRequest;
use App\Http\Requests\Admin\TestAiConnectorConnectionRequest;
use App\Http\Requests\Admin\UpdateAiConnectorRequest;
use App\Enums\ConnectorType;
use App\Ingestion\ConnectorRegistry;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBlueprint;
use App\Services\ConnectorBuilder\AiConnectorExportService;
use App\Services\ConnectorBuilder\AiConnectorImportService;
use App\Services\ConnectorBuilder\AiConnectorService;
use App\Services\ConnectorBuilder\ConnectorBuilderResumeService;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use App\Services\ConnectorBuilder\CreateDynamicConnectionService;
use App\Support\DynamicConnectorBaseUrl;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function create(): Response
    {
        $dashboards = ClientDashboard::query()
            ->with('company')
            ->orderBy('company_id')
            ->orderBy('name')
            ->get()
            ->map(fn (ClientDashboard $dashboard) => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'company_name' => $dashboard->company->name,
            ]);

        return Inertia::render('Admin/AiConnectors/Create', [
            'dashboards' => $dashboards,
            'defaultSandboxDashboardId' => $dashboards->first()['id'] ?? null,
        ]);
    }

    public function importForm(): Response
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Admin/AiConnectors/Import', [
            'companies' => $companies,
        ]);
    }

    public function import(
        ImportAiConnectorRequest $request,
        AiConnectorImportService $importer,
    ): RedirectResponse {
        $package = $this->decodeImportPackage($request);

        $blueprint = $importer->import($package, [
            'scope' => $request->string('scope')->toString(),
            'mode' => $request->string('mode')->toString(),
            'company_id' => $request->integer('company_id') ?: null,
        ]);

        return redirect()
            ->route('admin.ai-connectors.show', $blueprint)
            ->with('status', 'AI connector "'.$blueprint->label.'" imported. Credentials and synced data were not included — add a connection to test.');
    }

    public function export(
        ConnectorBlueprint $blueprint,
        AiConnectorExportService $exporter,
    ): StreamedResponse {
        $export = $exporter->export($blueprint);
        $json = json_encode($export['package'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            fn () => print($json),
            $export['filename'],
            ['Content-Type' => 'application/json'],
        );
    }

    public function store(
        StoreGlobalAiConnectorRequest $request,
        AiConnectorService $connectors,
    ): RedirectResponse {
        $sandboxDashboard = ClientDashboard::query()->findOrFail($request->integer('sandbox_dashboard_id'));

        $session = $connectors->startGlobalBuilder($request->user(), $sandboxDashboard);

        return redirect()
            ->route('admin.dashboards.connections.ai-create', [
                'dashboard' => $sandboxDashboard->id,
                'session' => $session->id,
            ])
            ->with('status', 'Describe the API you want to connect. This connector will be global across all companies.');
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
        ConnectorBlueprintDashboardVersionService $layouts,
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
            user: $request->user(),
        );

        $status = 'Connection "'.$connection->name.'" added. Backfill queued.';

        if ($layouts->currentSpec($blueprint, $dashboard) !== null) {
            $status .= ' Connector dashboard widgets are ready on the client Data tab.';
        } elseif ($layouts->hasWidgetTemplate($blueprint)) {
            $status .= ' Dashboard widget auto-build did not complete — use Build dashboard widgets on the connection page.';
        }

        return redirect()
            ->route('admin.connections.show', $connection)
            ->with('status', $status);
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
    protected function decodeImportPackage(ImportAiConnectorRequest $request): array
    {
        $raw = $request->string('payload')->toString();

        if ($raw === '' && $request->hasFile('file')) {
            $raw = (string) file_get_contents($request->file('file')->getRealPath());
        }

        $package = json_decode(trim($raw), true);

        if (! is_array($package)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payload' => 'Import file must contain valid JSON.',
            ]);
        }

        return $package;
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
