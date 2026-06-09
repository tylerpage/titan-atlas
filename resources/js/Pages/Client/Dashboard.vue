<script setup>
import { computed, onUnmounted, ref, toRef, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DashboardSyncingBadge from '../../Components/DashboardSyncingBadge.vue';
import CoverPageBlockGrid from '../../Components/CoverPageBlockGrid.vue';
import { useDashboardSyncPoll } from '../../Composables/useDashboardSyncPoll';
import CoverTable from '../../Components/CoverTable.vue';
import DashboardSavedBoardsPanel from '../../Components/Dashboard/DashboardSavedBoardsPanel.vue';
import DashboardTitanAiPanel from '../../Components/Dashboard/DashboardTitanAiPanel.vue';
import SearchConsoleDashboardPanel from '../../Components/Dashboard/SearchConsoleDashboardPanel.vue';
import GoogleAnalyticsDashboardPanel from '../../Components/Dashboard/GoogleAnalyticsDashboardPanel.vue';
import GoogleAdsDashboardPanel from '../../Components/Dashboard/GoogleAdsDashboardPanel.vue';
import StackAdaptDashboardPanel from '../../Components/Dashboard/StackAdaptDashboardPanel.vue';
import RevenueLineChart from '../../Components/RevenueLineChart.vue';
import { useAppBranding } from '../../Composables/useAppBranding';

const { aiName } = useAppBranding();

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    connections: {
        type: Array,
        default: () => [],
    },
    selectedConnectionId: {
        type: Number,
        default: null,
    },
    connectorData: {
        type: Object,
        default: null,
    },
    widgetData: {
        type: Object,
        required: true,
    },
    dateRange: {
        type: String,
        required: true,
    },
    rangeStart: {
        type: String,
        required: true,
    },
    rangeEnd: {
        type: String,
        required: true,
    },
    dateRangePresets: {
        type: Object,
        required: true,
    },
    dateComparisons: {
        type: Object,
        required: true,
    },
    comparison: {
        type: String,
        default: 'none',
    },
    comparisonRangeStart: {
        type: String,
        default: null,
    },
    comparisonRangeEnd: {
        type: String,
        default: null,
    },
    poweredByText: {
        type: String,
        required: true,
    },
    tab: {
        type: String,
        default: 'data',
    },
    hasCoverPages: {
        type: Boolean,
        default: false,
    },
    coverPageData: {
        type: Object,
        default: null,
    },
    coverPageOptions: {
        type: Array,
        default: () => [],
    },
    selectedCoverPageId: {
        type: Number,
        default: null,
    },
    aiView: {
        type: String,
        default: 'chat',
    },
    aiSession: {
        type: Object,
        default: null,
    },
    aiSavedDashboards: {
        type: Array,
        default: () => [],
    },
    aiSessions: {
        type: Array,
        default: () => [],
    },
    previewStart: {
        type: String,
        default: null,
    },
    previewEnd: {
        type: String,
        default: null,
    },
    savedBoards: {
        type: Array,
        default: () => [],
    },
    savedBoard: {
        type: Object,
        default: null,
    },
});

useDashboardSyncPoll(toRef(() => props.dashboard.is_syncing));

const isNavigating = ref(false);

const removeNavigationListeners = [
    router.on('start', () => {
        isNavigating.value = true;
    }),
    router.on('finish', () => {
        isNavigating.value = false;
    }),
];

onUnmounted(() => {
    removeNavigationListeners.forEach((removeListener) => removeListener());
});

const dataTabOnlyProps = [
    'dashboard',
    'connectorData',
    'widgetData',
    'dateRange',
    'rangeStart',
    'rangeEnd',
    'comparison',
    'comparisonRangeStart',
    'comparisonRangeEnd',
    'selectedConnectionId',
    'tab',
];

function onlyPropsForTab(tab) {
    if (tab === 'cover') {
        return ['tab', 'coverPageData', 'coverPageOptions', 'selectedCoverPageId'];
    }

    if (tab === 'ai') {
        return ['tab', 'aiView', 'aiSession', 'aiSessions', 'aiSavedDashboards', 'previewStart', 'previewEnd'];
    }

    if (tab === 'saved') {
        return ['tab', 'savedBoards', 'savedBoard', 'previewStart', 'previewEnd'];
    }

    return dataTabOnlyProps;
}

function visitDashboard(query, overrides = {}, extraOptions = {}) {
    const tab = overrides.tab ?? query.tab ?? activeTab.value;

    router.get(route('client.dashboard.show', props.dashboard.slug), query, {
        preserveState: true,
        preserveScroll: true,
        only: onlyPropsForTab(tab),
        ...extraOptions,
    });
}

const selectedRange = ref(props.dateRange);
const selectedComparison = ref(props.comparison);
const startDate = ref(props.rangeStart);
const endDate = ref(props.rangeEnd);
const activeConnectionId = ref(props.selectedConnectionId);
const activeTab = ref(props.tab);
const activeCoverPageId = ref(props.selectedCoverPageId);

watch(
    () => [props.dateRange, props.rangeStart, props.rangeEnd, props.comparison, props.selectedConnectionId, props.tab, props.selectedCoverPageId],
    ([range, start, end, comparison, connectionId, tab, coverPageId]) => {
        selectedRange.value = range;
        startDate.value = start;
        endDate.value = end;
        selectedComparison.value = comparison;
        activeConnectionId.value = connectionId;
        activeTab.value = tab;
        activeCoverPageId.value = coverPageId;
    },
);

const showCustomFields = computed(() => selectedRange.value === 'custom');
const comparing = computed(() => selectedComparison.value !== 'none');
const hasConnections = computed(() => props.connections.length > 0);
const activeConnection = computed(() =>
    props.connections.find((connection) => connection.id === activeConnectionId.value) ?? null,
);
const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.is_admin ?? false);
const showCoverTab = computed(() => props.hasCoverPages);
const isCoverTab = computed(() => activeTab.value === 'cover' && showCoverTab.value);
const isAiTab = computed(() => activeTab.value === 'ai');
const isSavedTab = computed(() => activeTab.value === 'saved');
const isDataTab = computed(() => !isCoverTab.value && !isAiTab.value && !isSavedTab.value);
const canEditCoverPage = computed(() => isAdmin.value && isCoverTab.value && props.coverPageData?.id);
const showCommerceView = computed(() => isDataTab.value && props.connectorData?.kind === 'commerce');
const showSearchConsoleView = computed(() => isDataTab.value && props.connectorData?.kind === 'search_console');
const showGoogleAnalyticsView = computed(() => isDataTab.value && props.connectorData?.kind === 'google_analytics');
const showGoogleAdsView = computed(() => isDataTab.value && props.connectorData?.kind === 'google_ads');
const showStackAdaptView = computed(() => isDataTab.value && props.connectorData?.kind === 'stackadapt');
const showConnectorView = computed(() => showCommerceView.value || showSearchConsoleView.value || showGoogleAnalyticsView.value || showGoogleAdsView.value || showStackAdaptView.value);
const showLegacyWidgets = computed(() => isDataTab.value && (!hasConnections.value || (!showConnectorView.value && hasConnections.value)));
const showTabFilters = computed(() => isDataTab.value);
const shareStatus = ref(null);
const sharing = ref(false);
let shareResetTimer = null;

const shareButtonLabel = computed(() => {
    if (sharing.value) {
        return 'Creating link…';
    }

    if (shareStatus.value === 'copied') {
        return 'Copied!';
    }

    if (shareStatus.value === 'error') {
        return 'Share failed';
    }

    return 'Share';
});

const shareButtonClass = computed(() => {
    if (shareStatus.value === 'copied') {
        return 'border-emerald-300 bg-emerald-50 text-emerald-700';
    }

    if (shareStatus.value === 'error') {
        return 'border-red-300 bg-red-50 text-red-700';
    }

    return 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50';
});

function currentShareQuery() {
    const query = {
        tab: activeTab.value,
        range: selectedRange.value,
        compare: selectedComparison.value,
    };

    if (activeTab.value === 'cover' && activeCoverPageId.value) {
        query.cover_page = activeCoverPageId.value;
    }

    if (selectedRange.value === 'custom') {
        query.start = startDate.value;
        query.end = endDate.value;
    }

    if (activeTab.value !== 'cover' && activeConnectionId.value) {
        query.connection = activeConnectionId.value;
    }

    return query;
}

function dashboardQuery(overrides = {}) {
    const tab = overrides.tab ?? activeTab.value;
    const query = {
        tab,
        range: selectedRange.value,
        compare: selectedComparison.value,
        ...overrides,
    };

    if (tab === 'cover' && (overrides.cover_page ?? activeCoverPageId.value)) {
        query.cover_page = overrides.cover_page ?? activeCoverPageId.value;
    }

    if (selectedRange.value === 'custom') {
        query.start = startDate.value;
        query.end = endDate.value;
    }

    if (tab === 'data' && activeConnectionId.value) {
        query.connection = overrides.connection ?? activeConnectionId.value;
    }

    if (tab === 'ai') {
        if (overrides.preview_start ?? props.previewStart) {
            query.preview_start = overrides.preview_start ?? props.previewStart;
        }

        if (overrides.preview_end ?? props.previewEnd) {
            query.preview_end = overrides.preview_end ?? props.previewEnd;
        }

        if (overrides.ai_view ?? props.aiView) {
            query.ai_view = overrides.ai_view ?? props.aiView;
        }

        if ('session' in overrides) {
            if (overrides.session) {
                query.session = overrides.session;
            }
        } else if (props.aiSession?.id) {
            query.session = props.aiSession.id;
        }
    }

    if (tab === 'saved') {
        if (overrides.preview_start ?? props.previewStart) {
            query.preview_start = overrides.preview_start ?? props.previewStart;
        }

        if (overrides.preview_end ?? props.previewEnd) {
            query.preview_end = overrides.preview_end ?? props.previewEnd;
        }

        if ('board' in overrides) {
            if (overrides.board) {
                query.board = overrides.board;
            }
        } else if (props.savedBoard?.id) {
            query.board = props.savedBoard.id;
        }
    }

    return query;
}

function navigateDashboard(overrides = {}) {
    if (overrides.tab) {
        activeTab.value = overrides.tab;
    }

    visitDashboard(dashboardQuery(overrides), overrides);
}

function selectAiTab() {
    navigateDashboard({ tab: 'ai', ai_view: 'chat', session: null });
}

function selectSavedTab() {
    navigateDashboard({ tab: 'saved', board: null });
}

function selectCoverTab() {
    activeTab.value = 'cover';
    visitDashboard(dashboardQuery({ tab: 'cover' }), { tab: 'cover' });
}

function selectCoverPage(coverPageId) {
    activeCoverPageId.value = coverPageId;
    visitDashboard(dashboardQuery({ tab: 'cover', cover_page: coverPageId }), { tab: 'cover' });
}

function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function shareDashboard() {
    sharing.value = true;
    shareStatus.value = null;

    try {
        const response = await fetch(route('client.dashboard.share', props.dashboard.slug), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(currentShareQuery()),
        });

        if (!response.ok) {
            throw new Error('Share failed');
        }

        const { url } = await response.json();

        await navigator.clipboard.writeText(url);
        shareStatus.value = 'copied';

        if (shareResetTimer) {
            clearTimeout(shareResetTimer);
        }

        shareResetTimer = setTimeout(() => {
            shareStatus.value = null;
        }, 2000);
    } catch {
        shareStatus.value = 'error';

        if (shareResetTimer) {
            clearTimeout(shareResetTimer);
        }

        shareResetTimer = setTimeout(() => {
            shareStatus.value = null;
        }, 2500);
    } finally {
        sharing.value = false;
    }
}

function applyFilters() {
    visitDashboard(dashboardQuery());
}

function selectConnection(connectionId) {
    activeConnectionId.value = connectionId;
    activeTab.value = 'data';
    visitDashboard(dashboardQuery({ tab: 'data', connection: connectionId }), { tab: 'data' });
}

function formatNumber(value, currency = false) {
    const formatted = new Intl.NumberFormat('en-US', {
        maximumFractionDigits: currency ? 0 : 0,
        minimumFractionDigits: currency ? 0 : 0,
    }).format(value ?? 0);

    return currency ? `$${formatted}` : formatted;
}

function formatMoney(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    }).format(value ?? 0);
}

function formatChange(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const prefix = value > 0 ? '+' : '';

    return `${prefix}${Number(value).toFixed(1)}%`;
}

function changeClass(value) {
    if (value === null || value === undefined || value === 0) {
        return 'text-slate-500';
    }

    return value > 0 ? 'text-emerald-600' : 'text-red-600';
}

function widgetDataFor(placementId) {
    return props.widgetData[placementId] ?? {};
}

function visiblePlacements() {
    return (props.dashboard.widget_placements ?? []).filter((placement) => placement.is_visible);
}

function sourceMediumLabel(order) {
    if (order.source_medium) {
        return order.source_medium;
    }

    if (order.source && order.medium) {
        return `${order.source} / ${order.medium}`;
    }

    return order.source || order.medium || '—';
}
</script>

<template>
    <AppLayout :title="dashboard.name">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-center gap-4">
                <img
                    v-if="dashboard.logo_url"
                    :src="dashboard.logo_url"
                    :alt="`${dashboard.name} logo`"
                    class="h-12 w-auto rounded border border-slate-200 bg-white p-1"
                />
                <div>
                    <p class="text-sm text-slate-500">{{ dashboard.company.name }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-semibold" :style="{ color: dashboard.primary_color }">
                            {{ dashboard.name }}
                        </h1>
                        <DashboardSyncingBadge :show="dashboard.is_syncing" />
                    </div>
                </div>
            </div>

            <p v-if="isNavigating && isDataTab" class="mb-4 text-sm text-slate-500">Updating dashboard…</p>

            <form v-if="showTabFilters" class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <div>
                    <label for="range" class="mb-1 block text-sm text-slate-600">Date range</label>
                    <select
                        id="range"
                        v-model="selectedRange"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        :disabled="isNavigating"
                    >
                        <option v-for="(label, value) in dateRangePresets" :key="value" :value="value">
                            {{ label }}
                        </option>
                        <option value="custom">Custom range</option>
                    </select>
                </div>

                <div>
                    <label for="compare" class="mb-1 block text-sm text-slate-600">Compare</label>
                    <select
                        id="compare"
                        v-model="selectedComparison"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        :disabled="isNavigating"
                    >
                        <option v-for="(label, value) in dateComparisons" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                </div>

                <div v-show="showCustomFields" class="flex gap-2">
                    <div>
                        <label for="start" class="mb-1 block text-sm text-slate-600">Start</label>
                        <input
                            id="start"
                            v-model="startDate"
                            type="date"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label for="end" class="mb-1 block text-sm text-slate-600">End</label>
                        <input
                            id="end"
                            v-model="endDate"
                            type="date"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                </div>

                <button
                    type="submit"
                    class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="isNavigating"
                >
                    {{ isNavigating ? 'Updating…' : 'Apply' }}
                </button>

                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm transition disabled:opacity-50"
                    :class="shareButtonClass"
                    :disabled="sharing"
                    @click="shareDashboard"
                >
                    {{ shareButtonLabel }}
                </button>
            </form>

            <div v-else class="flex flex-wrap items-center gap-2">
                <Link
                    v-if="canEditCoverPage"
                    :href="route('admin.cover-pages.edit', coverPageData.id)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Edit
                </Link>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm transition disabled:opacity-50"
                    :class="shareButtonClass"
                    :disabled="sharing"
                    @click="shareDashboard"
                >
                    {{ shareButtonLabel }}
                </button>
            </div>
        </div>

        <div class="mb-6 border-b border-slate-200">
            <nav class="-mb-px flex gap-1 overflow-x-auto">
                <button
                    v-if="showCoverTab"
                    type="button"
                    class="shrink-0 border-b-2 px-4 py-3 text-sm font-medium transition disabled:opacity-50"
                    :disabled="isNavigating"
                    :class="
                        isCoverTab
                            ? 'border-slate-900 text-slate-900'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="selectCoverTab"
                >
                    Summary
                </button>
                <button
                    v-for="connection in connections"
                    :key="connection.id"
                    type="button"
                    class="shrink-0 border-b-2 px-4 py-3 text-sm font-medium transition disabled:opacity-50"
                    :disabled="isNavigating"
                    :class="
                        isDataTab && connection.id === activeConnectionId
                            ? 'border-slate-900 text-slate-900'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="selectConnection(connection.id)"
                >
                    {{ connection.name }}
                    <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                        {{ connection.connector_label }}
                    </span>
                </button>
                <button
                    type="button"
                    class="shrink-0 border-b-2 px-4 py-3 text-sm font-medium transition disabled:opacity-50"
                    :disabled="isNavigating"
                    :class="
                        isAiTab
                            ? 'border-slate-900 text-slate-900'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="selectAiTab"
                >
                    {{ aiName }}
                </button>
                <button
                    type="button"
                    class="shrink-0 border-b-2 px-4 py-3 text-sm font-medium transition disabled:opacity-50"
                    :disabled="isNavigating"
                    :class="
                        isSavedTab
                            ? 'border-slate-900 text-slate-900'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="selectSavedTab"
                >
                    Saved dashboards
                </button>
            </nav>
        </div>

        <p v-if="isDataTab && comparing && comparisonRangeStart && comparisonRangeEnd" class="mb-6 text-sm text-slate-500">
            Comparing {{ rangeStart }} – {{ rangeEnd }} vs {{ comparisonRangeStart }} – {{ comparisonRangeEnd }}
        </p>

        <template v-if="isCoverTab && coverPageData">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold">{{ coverPageData.title }}</h2>
                    <p v-if="coverPageData.period_start && coverPageData.period_end" class="mt-1 text-sm text-slate-500">
                        {{ coverPageData.period_start }} – {{ coverPageData.period_end }}
                    </p>
                </div>
                <div v-if="coverPageOptions.length > 1">
                    <label for="cover-page" class="mb-1 block text-sm text-slate-600">Summary</label>
                    <select
                        id="cover-page"
                        :value="activeCoverPageId"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        @change="selectCoverPage(Number($event.target.value))"
                    >
                        <option v-for="option in coverPageOptions" :key="option.id" :value="option.id">
                            {{ option.title }}{{ option.is_active ? ' (active)' : '' }}
                        </option>
                    </select>
                </div>
            </div>

            <CoverPageBlockGrid
                :blocks="coverPageData.blocks"
                :color="dashboard.primary_color"
            />

            <p v-if="coverPageData.blocks.length === 0" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500 shadow-sm">
                This summary has no blocks yet.
            </p>
        </template>

        <DashboardTitanAiPanel
            v-else-if="isAiTab"
            :dashboard="dashboard"
            :ai-view="aiView"
            :session="aiSession"
            :sessions="aiSessions"
            :saved-dashboards="aiSavedDashboards"
            :preview-start="previewStart ?? rangeStart"
            :preview-end="previewEnd ?? rangeEnd"
            @navigate="navigateDashboard"
        />

        <DashboardSavedBoardsPanel
            v-else-if="isSavedTab"
            :dashboard="dashboard"
            :boards="savedBoards"
            :board="savedBoard"
            :preview-start="previewStart ?? rangeStart"
            :preview-end="previewEnd ?? rangeEnd"
            @navigate="navigateDashboard"
        />

        <template v-else-if="showCommerceView">
            <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Revenue</p>
                    <div class="mt-1 flex flex-wrap items-end gap-3">
                        <p class="text-3xl font-semibold">{{ formatNumber(connectorData.summary.revenue, true) }}</p>
                        <p
                            v-if="connectorData.summary.revenue_change_percent !== null"
                            class="pb-1 text-lg font-medium"
                            :class="changeClass(connectorData.summary.revenue_change_percent)"
                        >
                            {{ formatChange(connectorData.summary.revenue_change_percent) }}
                        </p>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Orders</p>
                    <div class="mt-1 flex flex-wrap items-end gap-3">
                        <p class="text-3xl font-semibold">{{ formatNumber(connectorData.summary.orders) }}</p>
                        <p
                            v-if="connectorData.summary.orders_change_percent !== null"
                            class="pb-1 text-lg font-medium"
                            :class="changeClass(connectorData.summary.orders_change_percent)"
                        >
                            {{ formatChange(connectorData.summary.orders_change_percent) }}
                        </p>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Sessions</p>
                    <div class="mt-1 flex flex-wrap items-end gap-3">
                        <p class="text-3xl font-semibold">{{ formatNumber(connectorData.summary.sessions) }}</p>
                        <p
                            v-if="connectorData.summary.sessions_change_percent !== null"
                            class="pb-1 text-lg font-medium"
                            :class="changeClass(connectorData.summary.sessions_change_percent)"
                        >
                            {{ formatChange(connectorData.summary.sessions_change_percent) }}
                        </p>
                    </div>
                    <p
                        v-if="connectorData.summary.visitors"
                        class="mt-1 text-sm text-slate-500"
                    >
                        {{ formatNumber(connectorData.summary.visitors) }} visitors
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Avg. order value</p>
                    <div class="mt-1 flex flex-wrap items-end gap-3">
                        <p class="text-3xl font-semibold">{{ formatNumber(connectorData.summary.avg_order_value, true) }}</p>
                        <p
                            v-if="connectorData.summary.avg_order_value_change_percent !== null"
                            class="pb-1 text-lg font-medium"
                            :class="changeClass(connectorData.summary.avg_order_value_change_percent)"
                        >
                            {{ formatChange(connectorData.summary.avg_order_value_change_percent) }}
                        </p>
                    </div>
                </div>
            </div>

            <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Revenue by day</h2>
                <RevenueLineChart
                    :series="connectorData.revenue_series"
                    :comparison-series="connectorData.comparison_revenue_series"
                    :comparing="comparing"
                    :color="dashboard.primary_color"
                />
            </section>

            <section
                v-if="connectorData.sessions_by_source_medium?.length"
                class="mb-8"
            >
                <CoverTable
                    title="Sessions by source / medium"
                    :columns="[
                        { key: 'source_medium', label: 'Source / medium' },
                        { key: 'sessions', label: 'Sessions' },
                        { key: 'visitors', label: 'Visitors' },
                    ]"
                    :rows="connectorData.sessions_by_source_medium"
                    :filterable="true"
                />
            </section>

            <section
                v-if="connectorData.top_products?.length"
                class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm"
            >
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">Top products</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Best sellers by revenue for {{ activeConnection?.name }} in this date range.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Product</th>
                                <th class="px-4 py-3 font-medium">SKU</th>
                                <th class="px-4 py-3 font-medium">Units</th>
                                <th class="px-4 py-3 font-medium">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(product, index) in connectorData.top_products"
                                :key="`${product.sku || product.name}-${index}`"
                                class="border-t border-slate-100"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <img
                                            v-if="product.image_url"
                                            :src="product.image_url"
                                            :alt="product.name"
                                            class="h-10 w-10 rounded-md border border-slate-200 object-cover"
                                        />
                                        <span class="font-medium text-slate-900">{{ product.name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ product.sku || '—' }}</td>
                                <td class="px-4 py-3">{{ formatNumber(product.units_sold) }}</td>
                                <td class="px-4 py-3">{{ formatMoney(product.revenue) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">Orders</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Order totals with source/medium and channel for {{ activeConnection?.name }}.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Order</th>
                                <th class="px-4 py-3 font-medium">Date</th>
                                <th class="px-4 py-3 font-medium">Total</th>
                                <th class="px-4 py-3 font-medium">Source / medium</th>
                                <th class="px-4 py-3 font-medium">Channel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="order in connectorData.orders ?? []" :key="order.external_id" class="border-t border-slate-100">
                                <td class="px-4 py-3 font-medium">{{ order.order_number }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ order.date }}</td>
                                <td class="px-4 py-3">{{ formatMoney(order.total) }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ sourceMediumLabel(order) }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ order.channel || '—' }}</td>
                            </tr>
                            <tr v-if="(connectorData.orders ?? []).length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-slate-500">
                                    No orders in this date range. Run a sync on this connection to pull up to 5 years of order history.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <SearchConsoleDashboardPanel
            v-else-if="showSearchConsoleView"
            :connector-data="connectorData"
            :connection-name="activeConnection?.name ?? ''"
            :primary-color="dashboard.primary_color"
            :comparing="comparing"
        />

        <GoogleAnalyticsDashboardPanel
            v-else-if="showGoogleAnalyticsView"
            :connector-data="connectorData"
            :connection-name="activeConnection?.name ?? ''"
            :dashboard-id="dashboard.id"
            :primary-color="dashboard.primary_color"
            :comparing="comparing"
        />

        <GoogleAdsDashboardPanel
            v-else-if="showGoogleAdsView"
            :connector-data="connectorData"
            :connection-name="activeConnection?.name ?? ''"
            :primary-color="dashboard.primary_color"
            :comparing="comparing"
        />

        <StackAdaptDashboardPanel
            v-else-if="showStackAdaptView"
            :connector-data="connectorData"
            :connection-name="activeConnection?.name ?? ''"
            :primary-color="dashboard.primary_color"
            :comparing="comparing"
        />

        <div
            v-else-if="hasConnections && activeConnection"
            class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm"
        >
            <h2 class="text-lg font-semibold">{{ activeConnection.connector_label }}</h2>
            <p class="mt-2 text-sm text-slate-500">
                Connector-specific reporting for {{ activeConnection.name }} is coming soon.
            </p>
        </div>

        <div v-else-if="showLegacyWidgets" class="grid gap-4 md:grid-cols-2">
            <div
                v-for="placement in visiblePlacements()"
                :key="placement.id"
                class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                :class="placement.column_span === 2 ? 'md:col-span-2' : ''"
            >
                <h2 class="mb-4 text-lg font-semibold">
                    {{ placement.title ?? placement.widget_type_label }}
                </h2>

                <template v-if="placement.widget_type === 'roas'">
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <p class="text-sm text-slate-500">Revenue</p>
                            <p class="text-2xl font-semibold">
                                {{ formatNumber(widgetDataFor(placement.id).revenue, true) }}
                            </p>
                            <p
                                v-if="widgetDataFor(placement.id).revenue_change_percent !== undefined"
                                class="mt-1 text-sm font-medium"
                                :class="changeClass(widgetDataFor(placement.id).revenue_change_percent)"
                            >
                                {{ formatChange(widgetDataFor(placement.id).revenue_change_percent) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-500">Ad spend</p>
                            <p class="text-2xl font-semibold">
                                {{ formatNumber(widgetDataFor(placement.id).ad_spend, true) }}
                            </p>
                            <p
                                v-if="widgetDataFor(placement.id).ad_spend_change_percent !== undefined"
                                class="mt-1 text-sm font-medium"
                                :class="changeClass(widgetDataFor(placement.id).ad_spend_change_percent)"
                            >
                                {{ formatChange(widgetDataFor(placement.id).ad_spend_change_percent) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-500">ROAS</p>
                            <p class="text-2xl font-semibold">{{ widgetDataFor(placement.id).roas ?? 0 }}x</p>
                            <p
                                v-if="widgetDataFor(placement.id).roas_change_percent !== undefined"
                                class="mt-1 text-sm font-medium"
                                :class="changeClass(widgetDataFor(placement.id).roas_change_percent)"
                            >
                                {{ formatChange(widgetDataFor(placement.id).roas_change_percent) }}
                            </p>
                        </div>
                    </div>
                </template>

                <template v-else-if="placement.widget_type === 'top_keywords'">
                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="item in widgetDataFor(placement.id).items ?? []"
                            :key="item.keyword"
                            class="flex justify-between border-b border-slate-100 pb-2"
                        >
                            <span>{{ item.keyword }}</span>
                            <span class="text-slate-500">#{{ Number(item.position).toFixed(1) }}</span>
                        </li>
                        <li v-if="!(widgetDataFor(placement.id).items ?? []).length" class="text-slate-500">
                            No keyword data yet.
                        </li>
                    </ul>
                </template>

                <template v-else>
                    <div class="flex flex-wrap items-end gap-3">
                        <p class="text-3xl font-semibold">
                            {{
                                ['revenue', 'ad_spend'].includes(placement.widget_type)
                                    ? formatNumber(widgetDataFor(placement.id).total, true)
                                    : formatNumber(widgetDataFor(placement.id).total)
                            }}
                        </p>
                        <p
                            v-if="widgetDataFor(placement.id).change_percent !== undefined"
                            class="pb-1 text-lg font-medium"
                            :class="changeClass(widgetDataFor(placement.id).change_percent)"
                        >
                            {{ formatChange(widgetDataFor(placement.id).change_percent) }}
                        </p>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ (widgetDataFor(placement.id).series ?? []).length }} daily data points
                    </p>
                </template>
            </div>
        </div>

        <p class="mt-10 text-center text-sm text-slate-400">
            {{ dashboard.powered_by_text ?? poweredByText }}
        </p>
    </AppLayout>
</template>
