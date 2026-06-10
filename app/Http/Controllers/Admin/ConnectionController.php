<?php

namespace App\Http\Controllers\Admin;

use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestExistingConnectionRequest;
use App\Http\Requests\Admin\UpdateConnectionRequest;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\Connection;
use App\Models\SavedDashboard;
use App\Support\DynamicConnectorCredentials;
use App\Services\Admin\ConnectionService;
use App\Services\Admin\TestConnectionService;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use App\Services\ConnectorBuilder\RebuildConnectorDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    public function show(Connection $connection): Response
    {
        $connection->load([
            'clientDashboard.company',
            'connectorBlueprint',
            'syncRuns' => fn ($q) => $q->limit(10),
        ]);

        return Inertia::render('Admin/Dashboards/Connections/Show', [
            'connection' => $this->serializeConnection($connection),
        ]);
    }

    public function rebuildDashboard(
        Connection $connection,
        RebuildConnectorDashboardService $service,
    ): RedirectResponse {
        $connection->loadMissing(['clientDashboard', 'connectorBlueprint']);

        $layouts = app(\App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService::class);
        $hadLayout = $connection->connectorBlueprint !== null
            && $layouts->currentSpec($connection->connectorBlueprint, $connection->clientDashboard) !== null;

        try {
            $result = $service->rebuild($connection, request()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Could not build connector dashboard.';

            return back()
                ->withErrors($e->errors())
                ->with('error', $message);
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withErrors(['dashboard' => $e->getMessage()])
                ->with('error', $e->getMessage());
        }

        if (! ($result['success'] ?? false)) {
            $message = $this->rebuildFailureMessage($result);

            return back()
                ->withErrors(['dashboard' => $message])
                ->with('error', $message);
        }

        return back()->with(
            'status',
            $hadLayout
                ? 'Connector dashboard rebuilt with corrected widget SQL.'
                : 'Connector dashboard built for this client dashboard.',
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function rebuildFailureMessage(array $result): string
    {
        $message = (string) ($result['error'] ?? 'Could not build connector dashboard.');

        $firstWidgetError = collect($result['errors'] ?? [])
            ->pluck('error')
            ->filter()
            ->first();

        if (is_string($firstWidgetError) && $firstWidgetError !== '') {
            $message .= ' '.$firstWidgetError;
        }

        return trim($message);
    }

    public function edit(Connection $connection): Response
    {
        $connection->load(['clientDashboard.company', 'connectorBlueprint']);

        return Inertia::render('Admin/Dashboards/Connections/Edit', [
            'connection' => $this->serializeConnection($connection),
            'connectors' => $this->connectorOptions(),
            'googleOauth' => app(\App\Services\Google\GoogleOAuthPendingSession::class)
                ->propsForDashboard($connection->connector_type, $connection->client_dashboard_id),
        ]);
    }

    public function update(
        UpdateConnectionRequest $request,
        Connection $connection,
        ConnectionService $service,
    ): RedirectResponse {
        try {
            $connection = $service->update($connection, $request->validated());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('admin.connections.show', $connection)
            ->with('status', 'Connection "'.$connection->name.'" updated.');
    }

    public function destroy(Connection $connection, ConnectionService $service): RedirectResponse
    {
        $dashboard = $connection->clientDashboard;
        $name = $connection->name;

        $service->delete($connection);

        return redirect()
            ->route('admin.dashboards.show', $dashboard)
            ->with('status', 'Connection "'.$name.'" deleted.');
    }

    public function sync(Connection $connection): RedirectResponse
    {
        SyncConnectionJob::dispatch($connection, SyncRunType::Incremental);

        return back()->with('status', 'Sync queued for '.$connection->name);
    }

    public function backfill(Connection $connection): RedirectResponse
    {
        SyncConnectionJob::dispatch($connection, SyncRunType::Backfill);

        return back()->with('status', 'Backfill queued for '.$connection->name);
    }

    public function clearData(Connection $connection, ConnectionService $service): RedirectResponse
    {
        try {
            $cleared = $service->clearData($connection);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with(
            'status',
            sprintf(
                'Cleared synced data for %s (%d payloads, %d sync runs, %d metrics). Run backfill to reload history.',
                $connection->name,
                $cleared['payloads'],
                $cleared['sync_runs'],
                $cleared['metrics'],
            ),
        );
    }

    public function test(
        TestExistingConnectionRequest $request,
        Connection $connection,
        TestConnectionService $service,
    ): JsonResponse {
        $result = $service->testExisting(
            $connection,
            $request->validated('credentials') ?? [],
        );

        return $this->testConnectionResponse($result);
    }

    protected function testConnectionResponse(ValidationResult $result): JsonResponse
    {
        return response()->json([
            'valid' => $result->valid,
            'message' => $result->message,
            'debug' => $result->debug,
        ]);
    }

    /**
     * @return list<array{value: string, label: string, fields: list<array<string, mixed>>, access_summary: string|null, supports_test: bool}>
     */
    protected function connectorOptions(): array
    {
        return collect(ConnectorType::cases())
            ->map(fn (ConnectorType $type) => $type->toConnectorOption())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    protected function credentialHints(Connection $connection, array $credentials): array
    {
        return match ($connection->connector_type) {
            ConnectorType::SearchConsole => is_string($credentials['site_url'] ?? null) && $credentials['site_url'] !== ''
                ? ['site_url' => $credentials['site_url']]
                : [],
            ConnectorType::GoogleAnalytics => is_string($credentials['property_id'] ?? null) && $credentials['property_id'] !== ''
                ? ['property_id' => $credentials['property_id']]
                : [],
            ConnectorType::GoogleAds => array_filter([
                'customer_id' => is_string($credentials['customer_id'] ?? null) && $credentials['customer_id'] !== ''
                    ? $credentials['customer_id']
                    : null,
                'login_customer_id' => is_string($credentials['login_customer_id'] ?? null) && $credentials['login_customer_id'] !== ''
                    ? $credentials['login_customer_id']
                    : null,
            ]),
            ConnectorType::StackAdapt => is_string($credentials['advertiser_id'] ?? null) && $credentials['advertiser_id'] !== ''
                ? ['advertiser_id' => $credentials['advertiser_id']]
                : [],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeConnection(Connection $connection): array
    {
        $credentials = $connection->credentials();
        $blueprint = DynamicConnectorCredentials::blueprintFor($connection);
        $credentialFields = $connection->isDynamic()
            ? DynamicConnectorCredentials::fields($blueprint)
            : $connection->connector_type->credentialFields();

        $layouts = app(ConnectorBlueprintDashboardVersionService::class);
        $layoutSpec = $blueprint !== null
            ? $layouts->currentSpec($blueprint, $connection->clientDashboard)
            : null;
        $hasWidgetTemplate = $blueprint !== null && $layouts->hasWidgetTemplate($blueprint);
        $hasDashboardLayout = $layoutSpec !== null && is_numeric($layoutSpec['saved_dashboard_id'] ?? null);
        $connectorDashboard = null;
        $savedDashboardUrl = null;

        if ($hasDashboardLayout) {
            $savedDashboardId = (int) $layoutSpec['saved_dashboard_id'];
            $boardExists = SavedDashboard::query()
                ->where('client_dashboard_id', $connection->clientDashboard->id)
                ->whereKey($savedDashboardId)
                ->exists();

            if ($boardExists) {
                $savedDashboardUrl = route('client.dashboard.saved.show', [
                    $connection->clientDashboard->slug,
                    $savedDashboardId,
                ]);

                $connectorDashboard = [
                    'title' => $layoutSpec['title'] ?? ($blueprint->label.' Dashboard'),
                    'saved_dashboard_id' => $savedDashboardId,
                    'saved_dashboard_url' => $savedDashboardUrl,
                    'widget_count' => count($layoutSpec['widgets'] ?? $blueprint->dashboard_spec['widgets'] ?? []),
                    'report_count' => count($layoutSpec['created_report_ids'] ?? []),
                ];
            }
        }

        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'connector_type' => $connection->connector_type->value,
            'connector_label' => $connection->isDynamic()
                ? ($blueprint?->label ?? 'AI Connector')
                : $connection->connector_type->label(),
            'is_active' => $connection->is_active,
            'sync_status' => $connection->sync_status->value,
            'sync_error' => $connection->sync_error,
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'data_from_date' => $connection->data_from_date?->toDateString(),
            'data_through_date' => $connection->data_through_date?->toDateString(),
            'backfill_started_at' => $connection->backfill_started_at?->toIso8601String(),
            'backfill_completed_at' => $connection->backfill_completed_at?->toIso8601String(),
            'credential_fields' => $credentialFields,
            'credential_hints' => $this->credentialHints($connection, $credentials),
            'connector_blueprint' => $blueprint ? [
                'id' => $blueprint->id,
                'label' => $blueprint->label,
                'slug' => $blueprint->slug,
            ] : null,
            'has_dashboard_layout' => $hasDashboardLayout && $connectorDashboard !== null,
            'can_build_dashboard' => $connection->isDynamic() && $blueprint !== null && $hasWidgetTemplate,
            'connector_dashboard' => $connectorDashboard,
            'dashboard' => [
                'id' => $connection->clientDashboard->id,
                'name' => $connection->clientDashboard->name,
                'slug' => $connection->clientDashboard->slug,
                'company_name' => $connection->clientDashboard->company->name,
            ],
            'sync_runs' => $connection->relationLoaded('syncRuns')
                ? $connection->syncRuns->map(fn ($run) => [
                    'id' => $run->id,
                    'type' => $run->type->value,
                    'status' => $run->status->value,
                    'records_fetched' => $run->records_fetched,
                    'records_written' => $run->records_written,
                    'progress_from_date' => $run->progress_from_date?->toDateString(),
                    'progress_through_date' => $run->progress_through_date?->toDateString(),
                    'error_message' => $run->error_message,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                ])->values()
                : [],
        ];
    }
}
