<script setup>
import { computed } from 'vue';
import ConnectorDataLagNotice from './ConnectorDataLagNotice.vue';
import CoverPieChart from '../CoverPieChart.vue';
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

function formatPercent(value) {
    return `${Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 })}%`;
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

const deviceSegments = computed(() => props.connectorData.device_breakdown?.map((row) => ({
    label: row.device,
    value: row.clicks,
})) ?? []);

const pieChartColors = computed(() => [
    props.primaryColor,
    '#3b82f6',
    '#10b981',
    '#f59e0b',
    '#8b5cf6',
]);

const topQueries = computed(() => props.connectorData.top_queries ?? []);

const summary = computed(() => props.connectorData.summary ?? {
    impressions: 0,
    clicks: 0,
    ctr: 0,
    impressions_change_percent: null,
    clicks_change_percent: null,
    ctr_change_percent: null,
});

const lagNoticeItems = computed(() => {
    if (!props.connectorData.data_lag) {
        return [];
    }

    return [{
        label: 'Google Search Console',
        ...props.connectorData.data_lag,
    }];
});
</script>

<template>
    <div>
        <ConnectorDataLagNotice
            v-if="lagNoticeItems.length"
            :items="lagNoticeItems"
        />

        <div class="mb-6 grid gap-4 md:grid-cols-3">
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
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">URL clicks</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.clicks) }}</p>
                    <p
                        v-if="summary.clicks_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.clicks_change_percent)"
                    >
                        {{ formatChange(summary.clicks_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">URLs CTR</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatPercent(summary.ctr) }}</p>
                    <p
                        v-if="summary.ctr_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.ctr_change_percent)"
                    >
                        {{ formatChange(summary.ctr_change_percent) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mb-8 grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">Impressions by day</h2>
                <RevenueLineChart
                    :series="connectorData.impressions_series ?? []"
                    :comparison-series="connectorData.comparison_impressions_series ?? []"
                    :comparing="comparing"
                    :color="primaryColor"
                    value-format="number"
                    series-label="Impressions"
                />
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">URL clicks by day</h2>
                <RevenueLineChart
                    :series="connectorData.clicks_series ?? []"
                    :comparison-series="connectorData.comparison_clicks_series ?? []"
                    :comparing="comparing"
                    :color="primaryColor"
                    value-format="number"
                    series-label="URL clicks"
                />
            </section>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">Clicks by device</h2>
            <p class="mb-4 text-sm text-slate-500">
                Search traffic split by device for {{ connectionName }} in this date range.
            </p>
            <CoverPieChart
                v-if="connectorData.device_breakdown?.length"
                :segments="deviceSegments"
                label-key="label"
                value-key="value"
                :colors="pieChartColors"
            />
            <p v-else class="py-8 text-center text-sm text-slate-500">
                No device data yet. Run a sync on this connection to pull device breakdown metrics.
            </p>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold">Top queries</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Highest-impression search queries for {{ connectionName }} in this date range.
                </p>
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
                            :key="`${row.query}-${index}`"
                            class="border-t border-slate-100"
                        >
                            <td class="px-4 py-3 font-medium text-slate-900">{{ row.query }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatNumber(row.impressions) }}</td>
                            <td
                                class="px-4 py-3 font-medium"
                                :class="changeClass(row.impressions_change_percent)"
                            >
                                {{
                                    row.impressions_change_percent !== null
                                        ? formatChange(row.impressions_change_percent)
                                        : '—'
                                }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ formatNumber(row.clicks) }}</td>
                        </tr>
                        <tr v-if="topQueries.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-slate-500">
                                No query data in this date range. Run a sync on this connection to pull search analytics.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
