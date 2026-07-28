<?php

namespace App\Http\Controllers\Client;

use App\Enums\ConnectorType;
use App\Enums\DateComparison;
use App\Http\Controllers\Controller;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\CoverPage;
use App\Services\Analytics\AmazonAdsDashboardService;
use App\Services\Analytics\CommerceDashboardService;
use App\Services\Analytics\ConnectorDashboardCache;
use App\Services\Analytics\DynamicConnectorDashboardService;
use App\Services\Analytics\EbayAdsDashboardService;
use App\Services\Analytics\GoogleAdsDashboardService;
use App\Services\Analytics\GoogleAnalyticsDashboardService;
use App\Services\Analytics\MetaAdsDashboardService;
use App\Services\Analytics\RedditAdsDashboardService;
use App\Services\Analytics\SearchConsoleDashboardService;
use App\Services\Analytics\StackAdaptDashboardService;
use App\Services\Analytics\WalmartConnectDashboardService;
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
        MetaAdsDashboardService $metaAds,
        AmazonAdsDashboardService $amazonAds,
        WalmartConnectDashboardService $walmartConnect,
        EbayAdsDashboardService $ebayAds,
        RedditAdsDashboardService $redditAds,
        DynamicConnectorDashboardService $dynamicConnector,
        CoverPageDataResolver $coverPages,
        ClientDashboardTabDataService $tabData,
        ConnectorDashboardCache $connectorCache,
    ): Response {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);

        $dashboard->load([
            'company',
            'connections' => fn ($q) => $q->where('is_active', true)->orderBy('name')->with('connectorBlueprint'),
            'coverPages' => fn ($q) => $q->orderByDesc('sort_order'),
        ]);

        $publishedCoverPages = $dashboard->coverPages->where('is_draft', false)->values();
        $showSummaryTab = $dashboard->showsSummaryTab();
        $activeCoverPage = $publishedCoverPages->firstWhere('is_active', true);
        $defaultTab = $showSummaryTab && $activeCoverPage ? 'cover' : 'data';
        $tab = (string) $request->query('tab', $defaultTab);

        if ($tab === 'cover' && ! $showSummaryTab) {
            $tab = 'data';
        }

        if ($tab === 'data') {
            $dashboard->load('widgetPlacements');
        }

        if ($tab === 'cover') {
            $dashboard->load('coverPages.blocks');
        }

        $selectedCoverPageId = (int) $request->query('cover_page', 0);
        $selectedCoverPage = null;
        $coverPageData = null;
        $coverPageOptions = [];

        if ($tab === 'cover') {
            $selectedCoverPage = $selectedCoverPageId
                ? $publishedCoverPages->firstWhere('id', $selectedCoverPageId)
                : ($activeCoverPage ?? $publishedCoverPages->first());

            $coverPageData = $selectedCoverPage
                ? $coverPages->resolveForClient($selectedCoverPage, $dashboard)
                : null;

            $coverPageOptions = $publishedCoverPages->map(fn (CoverPage $page) => [
                'id' => $page->id,
                'title' => $page->title,
                'period_start' => $page->period_start?->toDateString(),
                'period_end' => $page->period_end?->toDateString(),
                'is_active' => $page->is_active,
            ])->values()->all();
        }

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

        $isDataTab = $tab === 'data';
        $connectorData = null;
        $widgetData = [];

        if ($isDataTab && $selectedConnection !== null) {
            $connectorData = $this->connectorDataFor(
                $connectorCache,
                $dashboard,
                $selectedConnection,
                $commerce,
                $searchConsole,
                $googleAnalytics,
                $googleAds,
                $stackAdapt,
                $metaAds,
                $amazonAds,
                $walmartConnect,
                $ebayAds,
                $redditAds,
                $dynamicConnector,
                $dateRange,
                $customRange,
                $comparison,
            );

            if ($connectorData === null) {
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
        }

        [$rangeStart, $rangeEnd] = $widgets->resolveDateRange($dashboard, $dateRange, $customRange);
        $comparisonRange = $widgets->resolveComparisonRange($rangeStart, $rangeEnd, $comparison);

        $rangeStartString = $rangeStart->toDateString();
        $rangeEndString = $rangeEnd->toDateString();

        $aiTabData = $tab === 'ai'
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
                'widget_placements' => $isDataTab
                    ? $dashboard->widgetPlacements->map(fn ($placement) => [
                        'id' => $placement->id,
                        'title' => $placement->title,
                        'widget_type' => $placement->widget_type->value,
                        'widget_type_label' => $placement->widget_type->label(),
                        'column_span' => $placement->column_span,
                        'is_visible' => $placement->is_visible,
                    ])->values()
                    : collect(),
            ],
            'connections' => $connections->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'connector_type' => $connection->connector_type->value,
                'connector_label' => $connection->isDynamic()
                    ? ($connection->connectorBlueprint?->label ?? $connection->connector_type->label())
                    : $connection->connector_type->label(),
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
            'showSummaryTab' => $showSummaryTab,
            'hasCoverPages' => $showSummaryTab,
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

    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     * @return array<string, mixed>|null
     */
    protected function connectorDataFor(
        ConnectorDashboardCache $connectorCache,
        ClientDashboard $dashboard,
        Connection $connection,
        CommerceDashboardService $commerce,
        SearchConsoleDashboardService $searchConsole,
        GoogleAnalyticsDashboardService $googleAnalytics,
        GoogleAdsDashboardService $googleAds,
        StackAdaptDashboardService $stackAdapt,
        MetaAdsDashboardService $metaAds,
        AmazonAdsDashboardService $amazonAds,
        WalmartConnectDashboardService $walmartConnect,
        EbayAdsDashboardService $ebayAds,
        RedditAdsDashboardService $redditAds,
        DynamicConnectorDashboardService $dynamicConnector,
        string $dateRange,
        ?array $customRange,
        DateComparison $comparison,
    ): ?array {
        $resolver = match (true) {
            $connection->connector_type->isCommerce() => fn () => $commerce->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::SearchConsole => fn () => $searchConsole->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::GoogleAnalytics => fn () => $googleAnalytics->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
                $dashboard->connections,
            ),
            $connection->connector_type === ConnectorType::GoogleAds => fn () => $googleAds->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::RedditAds => fn () => $redditAds->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::StackAdapt => fn () => $stackAdapt->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::MetaAds => fn () => $metaAds->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::AmazonAds => fn () => $amazonAds->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::WalmartConnect => fn () => $walmartConnect->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::EbayAds => fn () => $ebayAds->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            $connection->connector_type === ConnectorType::Dynamic => fn () => $dynamicConnector->dataFor(
                $dashboard,
                $connection,
                $dateRange,
                $customRange,
                $comparison,
            ),
            default => null,
        };

        if ($resolver === null) {
            return null;
        }

        return $connectorCache->remember(
            $connection,
            $dateRange,
            $customRange,
            $comparison,
            $resolver,
        );
    }
}
