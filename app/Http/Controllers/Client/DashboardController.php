<?php

namespace App\Http\Controllers\Client;

use App\Enums\ConnectorType;
use App\Enums\DateComparison;
use App\Http\Controllers\Controller;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\CoverPage;
use App\Services\Analytics\CommerceDashboardService;
use App\Services\Analytics\GoogleAdsDashboardService;
use App\Services\Analytics\StackAdaptDashboardService;
use App\Services\Analytics\GoogleAnalyticsDashboardService;
use App\Services\Analytics\SearchConsoleDashboardService;
use App\Services\Analytics\CoverPageDataResolver;
use App\Services\Analytics\WidgetDataService;
use App\Services\Client\ClientDashboardTabDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function show(
        Request $request,
        ClientDashboard $dashboard,
        WidgetDataService $widgets,
        CommerceDashboardService $commerce,
        SearchConsoleDashboardService $searchConsole,
        GoogleAnalyticsDashboardService $googleAnalytics,
        GoogleAdsDashboardService $googleAds,
        StackAdaptDashboardService $stackAdapt,
        CoverPageDataResolver $coverPages,
        ClientDashboardTabDataService $tabData,
    ): Response {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);

        $dashboard->load([
            'company',
            'widgetPlacements',
            'connections' => fn ($q) => $q->where('is_active', true)->orderBy('name'),
            'coverPages.blocks',
        ]);

        $activeCoverPage = $dashboard->coverPages->firstWhere('is_active', true);
        $hasCoverPages = $dashboard->coverPages->isNotEmpty();
        $tab = (string) $request->query('tab', $hasCoverPages && $activeCoverPage ? 'cover' : 'data');
        $selectedCoverPageId = (int) $request->query('cover_page', 0);

        $selectedCoverPage = $selectedCoverPageId
            ? $dashboard->coverPages->firstWhere('id', $selectedCoverPageId)
            : ($tab === 'cover' ? ($activeCoverPage ?? $dashboard->coverPages->first()) : null);

        $coverPageData = $selectedCoverPage
            ? $coverPages->resolveForClient($selectedCoverPage, $dashboard)
            : null;

        $coverPageOptions = $dashboard->coverPages->map(fn (CoverPage $page) => [
            'id' => $page->id,
            'title' => $page->title,
            'period_start' => $page->period_start?->toDateString(),
            'period_end' => $page->period_end?->toDateString(),
            'is_active' => $page->is_active,
        ])->values();

        $dateRange = $request->query('range', $dashboard->default_date_range);
        $comparison = DateComparison::tryFrom((string) $request->query('compare', 'none'))
            ?? DateComparison::None;
        $customRange = null;

        if ($dateRange === 'custom') {
            $customRange = [
                'start' => $request->query('start'),
                'end' => $request->query('end'),
            ];
        }

        $connections = $dashboard->connections;
        $selectedConnectionId = (int) $request->query('connection', $connections->first()?->id ?? 0);
        $selectedConnection = $connections->firstWhere('id', $selectedConnectionId) ?? $connections->first();

        $isDataTab = ! in_array($tab, ['cover', 'ai', 'saved'], true);

        $connectorData = null;

        if ($isDataTab && $selectedConnection?->connector_type->isCommerce()) {
            $connectorData = $commerce->dataFor(
                $dashboard,
                $selectedConnection,
                $dateRange,
                $customRange,
                $comparison,
            );
        } elseif ($isDataTab && $selectedConnection?->connector_type === ConnectorType::SearchConsole) {
            $connectorData = $searchConsole->dataFor(
                $dashboard,
                $selectedConnection,
                $dateRange,
                $customRange,
                $comparison,
            );
        } elseif ($isDataTab && $selectedConnection?->connector_type === ConnectorType::GoogleAnalytics) {
            $connectorData = $googleAnalytics->dataFor(
                $dashboard,
                $selectedConnection,
                $dateRange,
                $customRange,
                $comparison,
            );
        } elseif ($isDataTab && $selectedConnection?->connector_type === ConnectorType::GoogleAds) {
            $connectorData = $googleAds->dataFor(
                $dashboard,
                $selectedConnection,
                $dateRange,
                $customRange,
                $comparison,
            );
        } elseif ($isDataTab && $selectedConnection?->connector_type === ConnectorType::StackAdapt) {
            $connectorData = $stackAdapt->dataFor(
                $dashboard,
                $selectedConnection,
                $dateRange,
                $customRange,
                $comparison,
            );
        }

        $widgetData = [];

        if ($isDataTab && $connectorData === null) {
            foreach ($dashboard->widgetPlacements->where('is_visible', true) as $placement) {
                $widgetData[$placement->id] = $widgets->dataFor(
                    $dashboard,
                    $placement->widget_type,
                    $dateRange,
                    $customRange,
                    $comparison,
                );
            }
        }

        [$rangeStart, $rangeEnd] = $widgets->resolveDateRange($dashboard, $dateRange, $customRange);
        $comparisonRange = $widgets->resolveComparisonRange($rangeStart, $rangeEnd, $comparison);

        $rangeStartString = $rangeStart->toDateString();
        $rangeEndString = $rangeEnd->toDateString();

        $aiTabData = $tab === 'ai' || $request->query('session')
            ? $tabData->aiTabData($request, $dashboard, $rangeStartString, $rangeEndString)
            : null;

        $savedTabData = $tab === 'saved'
            ? $tabData->savedTabData($request, $dashboard, $rangeStartString, $rangeEndString)
            : null;

        return Inertia::render('Client/Dashboard', [
            'dashboard' => [
                'id' => $dashboard->id,
                'slug' => $dashboard->slug,
                'name' => $dashboard->name,
                'is_syncing' => $dashboard->isSyncing(),
                'logo_url' => $dashboard->logo_path
                    ? Storage::disk('public')->url($dashboard->logo_path)
                    : null,
                'primary_color' => $dashboard->primary_color,
                'powered_by_text' => $dashboard->powered_by_text,
                'company' => [
                    'name' => $dashboard->company->name,
                ],
                'widget_placements' => $dashboard->widgetPlacements->map(fn ($placement) => [
                    'id' => $placement->id,
                    'title' => $placement->title,
                    'widget_type' => $placement->widget_type->value,
                    'widget_type_label' => $placement->widget_type->label(),
                    'column_span' => $placement->column_span,
                    'is_visible' => $placement->is_visible,
                ])->values(),
            ],
            'connections' => $connections->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'connector_type' => $connection->connector_type->value,
                'connector_label' => $connection->connector_type->label(),
                'is_commerce' => $connection->connector_type->isCommerce(),
            ])->values(),
            'selectedConnectionId' => $selectedConnection?->id,
            'connectorData' => $connectorData,
            'widgetData' => $widgetData,
            'dateRange' => $dateRange,
            'rangeStart' => $rangeStartString,
            'rangeEnd' => $rangeEndString,
            'dateRangePresets' => config('titan.date_range_presets'),
            'dateComparisons' => config('titan.date_comparisons'),
            'comparison' => $comparison->value,
            'comparisonRangeStart' => $comparisonRange ? $comparisonRange[0]->toDateString() : null,
            'comparisonRangeEnd' => $comparisonRange ? $comparisonRange[1]->toDateString() : null,
            'poweredByText' => config('titan.branding.powered_by_text'),
            'tab' => $tab,
            'hasCoverPages' => $hasCoverPages,
            'coverPageData' => $coverPageData,
            'coverPageOptions' => $coverPageOptions,
            'selectedCoverPageId' => $selectedCoverPage?->id,
            'aiView' => $aiTabData['aiView'] ?? 'chat',
            'aiSession' => $aiTabData['aiSession'] ?? null,
            'aiSavedDashboards' => $aiTabData['aiSavedDashboards'] ?? [],
            'aiSessions' => $aiTabData['aiSessions'] ?? [],
            'previewStart' => $aiTabData['previewStart'] ?? $savedTabData['previewStart'] ?? $rangeStartString,
            'previewEnd' => $aiTabData['previewEnd'] ?? $savedTabData['previewEnd'] ?? $rangeEndString,
            'savedBoards' => $savedTabData['savedBoards'] ?? [],
            'savedBoard' => $savedTabData['savedBoard'] ?? null,
        ]);
    }
}
