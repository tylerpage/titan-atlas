<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ConnectorDataLagNotice from './ConnectorDataLagNotice.vue';
import CoverBarChart from '../CoverBarChart.vue';
import RevenueLineChart from '../RevenueLineChart.vue';

const props = defineProps({
    connectorData: {
        type: Object,
        required: true,
    },
    connectionName: {
        type: String,
        default: '',
    },
    dashboardId: {
        type: Number,
        default: null,
    },
    primaryColor: {
        type: String,
        default: '#0f172a',
    },
    comparing: {
        type: Boolean,
        default: false,
    },
});

function formatNumber(value) {
    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits: 0,
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

const summary = computed(() => props.connectorData.summary ?? {
    visitors: 0,
    active_users: 0,
    sessions: 0,
    impressions: 0,
    url_clicks: 0,
});

const eventItems = computed(() => props.connectorData.events?.map((row) => ({
    label: row.event_name,
    value: row.event_count,
})) ?? []);

const topQueries = computed(() => props.connectorData.top_queries ?? []);
const topKeywords = computed(() => props.connectorData.top_keywords ?? []);
const opportunities = computed(() => props.connectorData.opportunities ?? {
    high_impression_low_ctr: [],
    striking_distance: [],
    traffic_drop_pages: [],
});

const lagNoticeItems = computed(() => {
    const items = [];

    if (props.connectorData.data_lag) {
        items.push({
            label: 'Google Analytics 4',
            ...props.connectorData.data_lag,
        });
    }

    if (!props.connectorData.gsc_required && props.connectorData.gsc_data_lag) {
        items.push({
            label: 'Search Console',
            ...props.connectorData.gsc_data_lag,
        });
    }

    return items;
});
</script>

<template>
    <div>
        <div
            v-if="connectorData.gsc_required"
            class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900"
        >
            <p class="font-medium">Search Console connection required</p>
            <p class="mt-1">
                The unified GA4 dashboard needs an active Google Search Console connection on this dashboard for impressions, URL clicks, queries, keywords, and opportunities.
            </p>
            <Link
                v-if="dashboardId"
                :href="route('admin.dashboards.show', dashboardId)"
                class="mt-3 inline-block font-medium text-amber-900 underline"
            >
                Add a Search Console connection in admin
            </Link>
        </div>

        <ConnectorDataLagNotice
            v-if="lagNoticeItems.length"
            :items="lagNoticeItems"
        />

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Visitors</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.visitors) }}</p>
                    <p
                        v-if="summary.visitors_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.visitors_change_percent)"
                    >
                        {{ formatChange(summary.visitors_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">1-day active users</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.active_users) }}</p>
                    <p
                        v-if="summary.active_users_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.active_users_change_percent)"
                    >
                        {{ formatChange(summary.active_users_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Sessions</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.sessions) }}</p>
                    <p
                        v-if="summary.sessions_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.sessions_change_percent)"
                    >
                        {{ formatChange(summary.sessions_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">URL clicks</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.url_clicks) }}</p>
                    <p
                        v-if="summary.url_clicks_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.url_clicks_change_percent)"
                    >
                        {{ formatChange(summary.url_clicks_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Impressions</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.impressions) }}</p>
                    <p
                        v-if="summary.impressions_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.impressions_change_percent)"
                    >
                        {{ formatChange(summary.impressions_change_percent) }}
                    </p>
                </div>
            </div>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">Traffic</h2>
            <RevenueLineChart
                :series="connectorData.traffic_series ?? []"
                :comparison-series="connectorData.comparison_traffic_series ?? []"
                :comparing="comparing"
                :color="primaryColor"
                value-format="number"
                series-label="Sessions"
            />
        </section>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">Event triggers</h2>
            <p class="mb-4 text-sm text-slate-500">Top GA4 events by count for {{ connectionName }}.</p>
            <CoverBarChart
                v-if="eventItems.length"
                :items="eventItems"
                label-key="label"
                value-key="value"
                :color="primaryColor"
            />
            <p v-else class="py-8 text-center text-sm text-slate-500">
                No event data yet. Run a sync on this connection to pull GA4 events.
            </p>
        </section>

        <section class="mb-8 space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">Opportunities</h2>
                    <p class="mt-1 text-sm text-slate-500">SEO opportunities derived from GA4 and Search Console data.</p>
                </div>
                <div class="grid gap-6 p-5 lg:grid-cols-3">
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-slate-900">High impression, low CTR</h3>
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="(row, index) in opportunities.high_impression_low_ctr"
                                :key="`low-ctr-${index}`"
                                class="rounded-lg border border-slate-100 px-3 py-2"
                            >
                                <p class="font-medium">{{ row.query }}</p>
                                <p class="text-slate-500">{{ formatNumber(row.impressions) }} impressions · {{ row.ctr }}% CTR</p>
                            </li>
                            <li v-if="!opportunities.high_impression_low_ctr.length" class="text-slate-500">No matches in this range.</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-slate-900">Striking distance</h3>
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="(row, index) in opportunities.striking_distance"
                                :key="`strike-${index}`"
                                class="rounded-lg border border-slate-100 px-3 py-2"
                            >
                                <p class="font-medium">{{ row.query }}</p>
                                <p class="text-slate-500">Pos {{ row.avg_position }} · {{ formatNumber(row.impressions) }} impressions</p>
                            </li>
                            <li v-if="!opportunities.striking_distance.length" class="text-slate-500">No matches in this range.</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-slate-900">Traffic drop pages</h3>
                        <ul class="space-y-2 text-sm">
                            <li
                                v-for="(row, index) in opportunities.traffic_drop_pages"
                                :key="`drop-${index}`"
                                class="rounded-lg border border-slate-100 px-3 py-2"
                            >
                                <p class="truncate font-medium" :title="row.landing_page">{{ row.landing_page }}</p>
                                <p class="text-slate-500">
                                    {{ formatNumber(row.sessions) }} sessions
                                    <span :class="changeClass(row.change_percent)">({{ formatChange(row.change_percent) }})</span>
                                </p>
                            </li>
                            <li v-if="!opportunities.traffic_drop_pages.length" class="text-slate-500">Enable comparison to surface drops.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold">Search queries</h2>
                <p class="mt-1 text-sm text-slate-500">Top queries from {{ connectorData.gsc_connection?.name ?? 'Search Console' }}.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Query</th>
                            <th class="px-4 py-3 font-medium">Impressions</th>
                            <th class="px-4 py-3 font-medium">Change %</th>
                            <th class="px-4 py-3 font-medium">URL clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in topQueries"
                            :key="`query-${index}`"
                            class="border-t border-slate-100"
                        >
                            <td class="px-4 py-3 font-medium text-slate-900">{{ row.query }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatNumber(row.impressions) }}</td>
                            <td class="px-4 py-3 font-medium" :class="changeClass(row.impressions_change_percent)">
                                {{ row.impressions_change_percent !== null ? formatChange(row.impressions_change_percent) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ formatNumber(row.clicks) }}</td>
                        </tr>
                        <tr v-if="topQueries.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                {{ connectorData.gsc_required ? 'Add Search Console to populate query data.' : 'No query data in this date range.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold">Top tracked keywords</h2>
                <p class="mt-1 text-sm text-slate-500">Highest-impression keywords with average position.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Keyword</th>
                            <th class="px-4 py-3 font-medium">Position</th>
                            <th class="px-4 py-3 font-medium">Impressions</th>
                            <th class="px-4 py-3 font-medium">Clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in topKeywords"
                            :key="`keyword-${index}`"
                            class="border-t border-slate-100"
                        >
                            <td class="px-4 py-3 font-medium text-slate-900">{{ row.keyword }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ row.position }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatNumber(row.impressions) }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatNumber(row.clicks) }}</td>
                        </tr>
                        <tr v-if="topKeywords.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                {{ connectorData.gsc_required ? 'Add Search Console to populate keyword data.' : 'No keyword data in this date range.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
