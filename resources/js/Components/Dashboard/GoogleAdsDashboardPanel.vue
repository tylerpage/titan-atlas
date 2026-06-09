<script setup>
import { computed } from 'vue';
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

function formatNumber(value, currency = false) {
    if (currency) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            maximumFractionDigits: 0,
        }).format(value ?? 0);
    }

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

const summary = computed(() => props.connectorData.summary ?? {
    cost: 0,
    impressions: 0,
    clicks: 0,
    ctr: 0,
    conversions_value: 0,
    cost_change_percent: null,
    impressions_change_percent: null,
    clicks_change_percent: null,
    ctr_change_percent: null,
    conversions_value_change_percent: null,
});

const campaigns = computed(() => props.connectorData.campaigns ?? []);
const showPriorYearSpend = computed(() => (props.connectorData.prior_year_spend_series ?? []).length > 0);
</script>

<template>
    <div>
        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Cost</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.cost, true) }}</p>
                    <p
                        v-if="summary.cost_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.cost_change_percent)"
                    >
                        {{ formatChange(summary.cost_change_percent) }}
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
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Clicks</p>
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
                <p class="text-sm text-slate-500">CTR</p>
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
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">All conv. value</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.conversions_value, true) }}</p>
                    <p
                        v-if="summary.conversions_value_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.conversions_value_change_percent)"
                    >
                        {{ formatChange(summary.conversions_value_change_percent) }}
                    </p>
                </div>
            </div>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">Daily spend</h2>
            <p class="mb-4 text-sm text-slate-500">
                Daily ad spend for {{ connectionName }} with prior-year overlay.
            </p>
            <RevenueLineChart
                :series="connectorData.spend_series ?? []"
                :comparison-series="connectorData.prior_year_spend_series ?? []"
                :comparing="showPriorYearSpend"
                comparison-series-label="Prior year"
                :color="primaryColor"
                value-format="currency"
                series-label="Daily spend"
            />
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold">Campaign breakdown</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Top campaigns by spend for {{ connectionName }} in this date range.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Campaign</th>
                            <th class="px-4 py-3 font-medium">Cost</th>
                            <th class="px-4 py-3 font-medium">Impressions</th>
                            <th class="px-4 py-3 font-medium">Clicks</th>
                            <th class="px-4 py-3 font-medium">CTR</th>
                            <th class="px-4 py-3 font-medium">All conv. value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="campaign in campaigns"
                            :key="campaign.campaign_id"
                            class="border-t border-slate-100"
                        >
                            <td class="px-4 py-3 font-medium">{{ campaign.campaign_name }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.cost, true) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.impressions) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.clicks) }}</td>
                            <td class="px-4 py-3">{{ formatPercent(campaign.ctr) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.conversions_value, true) }}</td>
                        </tr>
                        <tr v-if="campaigns.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                                No campaign data in this date range. Run a sync on this connection to pull Google Ads history.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
