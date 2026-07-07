<script setup>
import { computed, ref } from 'vue';
import RevenueLineChart from '../RevenueLineChart.vue';
import { formatCurrency, formatDecimal, formatNumber, formatPercent } from '../../lib/formatters';

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

const rankBy = ref('cost');
const sortKey = ref('cost');
const sortDirection = ref('desc');
const campaignSearch = ref('');

function formatRoas(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return formatDecimal(value);
}

function formatOptionalCurrency(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return formatCurrency(value);
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

function roasChangeClass(value) {
    if (value === null || value === undefined || value === 0) {
        return 'text-slate-500';
    }

    return value > 0 ? 'text-emerald-600' : 'text-red-600';
}

function cpaChangeClass(value) {
    if (value === null || value === undefined || value === 0) {
        return 'text-slate-500';
    }

    return value > 0 ? 'text-red-600' : 'text-emerald-600';
}

const summary = computed(() => props.connectorData.summary ?? {
    cost: 0,
    conversions_value: 0,
    roas: null,
    conversions: 0,
    cpa: null,
    impressions: 0,
    reach: 0,
    clicks: 0,
    ctr: 0,
    cpc: null,
    cpm: null,
});

const campaigns = computed(() => props.connectorData.campaigns ?? []);
const objectives = computed(() => props.connectorData.objectives ?? []);
const placements = computed(() => props.connectorData.placements ?? []);
const devices = computed(() => props.connectorData.devices ?? []);
const showPriorYearSpend = computed(() => (props.connectorData.prior_year_spend_series ?? []).length > 0);

const rankedTopCampaigns = computed(() => {
    const sorted = [...campaigns.value];

    sorted.sort((left, right) => {
        const leftValue = Number(left[rankBy.value] ?? 0);
        const rightValue = Number(right[rankBy.value] ?? 0);

        return rightValue - leftValue;
    });

    return sorted.slice(0, 10);
});

const sortedCampaigns = computed(() => {
    let rows = campaigns.value;

    const query = campaignSearch.value.trim().toLowerCase();

    if (query) {
        rows = rows.filter((row) => String(row.campaign_name ?? '').toLowerCase().includes(query));
    }

    const direction = sortDirection.value === 'asc' ? 1 : -1;

    return [...rows].sort((left, right) => {
        const leftValue = left[sortKey.value];
        const rightValue = right[sortKey.value];

        if (typeof leftValue === 'string' || typeof rightValue === 'string') {
            return direction * String(leftValue ?? '').localeCompare(String(rightValue ?? ''));
        }

        return direction * (Number(leftValue ?? 0) - Number(rightValue ?? 0));
    });
});

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';

        return;
    }

    sortKey.value = key;
    sortDirection.value = key === 'campaign_name' ? 'asc' : 'desc';
}

function sortIndicator(key) {
    if (sortKey.value !== key) {
        return '';
    }

    return sortDirection.value === 'asc' ? ' ↑' : ' ↓';
}

function exportCampaignCsv() {
    const headers = [
        'Campaign',
        'Objective',
        'Spend',
        'Revenue',
        'Purchases',
        'ROAS',
        'CTR',
        'CPC',
        'CPM',
        'CPA',
        'Reach',
        'Impressions',
    ];

    const lines = sortedCampaigns.value.map((row) => [
        row.campaign_name,
        row.objective,
        row.cost,
        row.conversions_value,
        row.conversions,
        row.roas ?? '',
        row.ctr,
        row.cpc,
        row.cpm,
        row.cpa ?? '',
        row.reach,
        row.impressions,
    ].map((value) => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','));

    const csv = [headers.join(','), ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'meta-campaign-performance.csv';
    link.click();
    URL.revokeObjectURL(url);
}

const maxObjectiveCost = computed(() => Math.max(...objectives.value.map((row) => row.cost ?? 0), 1));
const maxPlacementCost = computed(() => Math.max(...placements.value.map((row) => row.cost ?? 0), 1));
const maxDeviceCost = computed(() => Math.max(...devices.value.map((row) => row.cost ?? 0), 1));
</script>

<template>
    <div>
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Ad spend</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatCurrency(summary.cost) }}</p>
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
                <p class="text-sm text-slate-500">Purchase revenue</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatCurrency(summary.conversions_value) }}</p>
                    <p
                        v-if="summary.conversions_value_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.conversions_value_change_percent)"
                    >
                        {{ formatChange(summary.conversions_value_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">ROAS</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatRoas(summary.roas) }}</p>
                    <p
                        v-if="summary.roas_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="roasChangeClass(summary.roas_change_percent)"
                    >
                        {{ formatChange(summary.roas_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Purchases</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatNumber(summary.conversions) }}</p>
                    <p
                        v-if="summary.conversions_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.conversions_change_percent)"
                    >
                        {{ formatChange(summary.conversions_change_percent) }}
                    </p>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Cost per purchase</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatOptionalCurrency(summary.cpa) }}</p>
                    <p
                        v-if="summary.cpa_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="cpaChangeClass(summary.cpa_change_percent)"
                    >
                        {{ formatChange(summary.cpa_change_percent) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Impressions</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatNumber(summary.impressions) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Reach</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatNumber(summary.reach) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Link clicks</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatNumber(summary.clicks) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">CTR</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatPercent(summary.ctr) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">CPC</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatOptionalCurrency(summary.cpc) }}</p>
            </div>
        </div>

        <div class="mb-8 grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Spend over time</h2>
                <p class="mb-4 text-sm text-slate-500">Daily Meta ad spend for {{ connectionName }}.</p>
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

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Revenue vs spend</h2>
                <p class="mb-4 text-sm text-slate-500">Daily purchase revenue compared to ad spend.</p>
                <RevenueLineChart
                    :series="connectorData.spend_series ?? []"
                    :comparison-series="connectorData.revenue_series ?? []"
                    comparing
                    comparison-series-label="Purchase revenue"
                    :color="primaryColor"
                    value-format="currency"
                    series-label="Spend"
                />
            </section>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">ROAS over time</h2>
            <p class="mb-4 text-sm text-slate-500">Daily return on ad spend (purchase revenue / spend).</p>
            <RevenueLineChart
                :series="connectorData.roas_series ?? []"
                :color="primaryColor"
                value-format="decimal"
                series-label="ROAS"
            />
        </section>

        <div class="mb-8 grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Top campaigns</h2>
                        <p class="text-sm text-slate-500">Campaigns with spend in this date range.</p>
                    </div>
                    <select v-model="rankBy" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="cost">Highest spend</option>
                        <option value="conversions_value">Highest revenue</option>
                        <option value="roas">Highest ROAS</option>
                        <option value="conversions">Most purchases</option>
                    </select>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Campaign</th>
                                <th class="px-4 py-3 font-medium">Spend</th>
                                <th class="px-4 py-3 font-medium">Revenue</th>
                                <th class="px-4 py-3 font-medium">ROAS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="campaign in rankedTopCampaigns" :key="campaign.campaign_id" class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ campaign.campaign_name }}</td>
                                <td class="px-4 py-3">{{ formatCurrency(campaign.cost) }}</td>
                                <td class="px-4 py-3">{{ formatCurrency(campaign.conversions_value) }}</td>
                                <td class="px-4 py-3">{{ formatRoas(campaign.roas) }}</td>
                            </tr>
                            <tr v-if="!rankedTopCampaigns.length">
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500">No campaign data yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Lowest ROAS campaigns</h2>
                <p class="mb-4 text-sm text-slate-500">Optimization candidates with spend and attributed revenue.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Campaign</th>
                                <th class="px-4 py-3 font-medium">Spend</th>
                                <th class="px-4 py-3 font-medium">ROAS</th>
                                <th class="px-4 py-3 font-medium">CPA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="campaign in (connectorData.bottom_campaigns ?? [])"
                                :key="`bottom-${campaign.campaign_id}`"
                                class="border-t border-slate-100"
                            >
                                <td class="px-4 py-3">{{ campaign.campaign_name }}</td>
                                <td class="px-4 py-3">{{ formatCurrency(campaign.cost) }}</td>
                                <td class="px-4 py-3">{{ formatRoas(campaign.roas) }}</td>
                                <td class="px-4 py-3">{{ formatOptionalCurrency(campaign.cpa) }}</td>
                            </tr>
                            <tr v-if="!(connectorData.bottom_campaigns ?? []).length">
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500">No optimization candidates yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold">Campaign performance</h2>
                    <p class="text-sm text-slate-500">All campaigns with spend in this date range.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <input
                        v-model="campaignSearch"
                        type="search"
                        placeholder="Search campaigns"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        @click="exportCampaignCsv"
                    >
                        Export CSV
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('campaign_name')">
                                Campaign{{ sortIndicator('campaign_name') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('objective')">
                                Objective{{ sortIndicator('objective') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('cost')">
                                Spend{{ sortIndicator('cost') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('conversions_value')">
                                Revenue{{ sortIndicator('conversions_value') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('conversions')">
                                Purchases{{ sortIndicator('conversions') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('roas')">
                                ROAS{{ sortIndicator('roas') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('ctr')">
                                CTR{{ sortIndicator('ctr') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('cpc')">
                                CPC{{ sortIndicator('cpc') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('cpm')">
                                CPM{{ sortIndicator('cpm') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('cpa')">
                                CPA{{ sortIndicator('cpa') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('reach')">
                                Reach{{ sortIndicator('reach') }}
                            </th>
                            <th class="cursor-pointer px-4 py-3 font-medium" @click="toggleSort('impressions')">
                                Impressions{{ sortIndicator('impressions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="campaign in sortedCampaigns" :key="campaign.campaign_id" class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ campaign.campaign_name }}</td>
                            <td class="px-4 py-3">{{ campaign.objective }}</td>
                            <td class="px-4 py-3">{{ formatCurrency(campaign.cost) }}</td>
                            <td class="px-4 py-3">{{ formatCurrency(campaign.conversions_value) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.conversions) }}</td>
                            <td class="px-4 py-3">{{ formatRoas(campaign.roas) }}</td>
                            <td class="px-4 py-3">{{ formatPercent(campaign.ctr) }}</td>
                            <td class="px-4 py-3">{{ formatOptionalCurrency(campaign.cpc) }}</td>
                            <td class="px-4 py-3">{{ formatOptionalCurrency(campaign.cpm) }}</td>
                            <td class="px-4 py-3">{{ formatOptionalCurrency(campaign.cpa) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.reach) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.impressions) }}</td>
                        </tr>
                        <tr v-if="!sortedCampaigns.length">
                            <td colspan="12" class="px-4 py-8 text-center text-slate-500">No campaign data yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Spend by objective</h2>
                <div v-if="objectives.length" class="space-y-3">
                    <div v-for="row in objectives" :key="row.dimension_key" class="space-y-1">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ row.dimension_label }}</span>
                            <span class="text-slate-600">{{ formatCurrency(row.cost) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width: `${Math.max(4, (row.cost / maxObjectiveCost) * 100)}%`,
                                    backgroundColor: primaryColor,
                                }"
                            />
                        </div>
                    </div>
                </div>
                <p v-else class="py-8 text-center text-sm text-slate-500">No objective breakdown yet.</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Spend by placement</h2>
                <div v-if="placements.length" class="space-y-3">
                    <div v-for="row in placements" :key="row.dimension_key" class="space-y-1">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ row.dimension_label }}</span>
                            <span class="text-slate-600">{{ formatCurrency(row.cost) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width: `${Math.max(4, (row.cost / maxPlacementCost) * 100)}%`,
                                    backgroundColor: primaryColor,
                                }"
                            />
                        </div>
                    </div>
                </div>
                <p v-else class="py-8 text-center text-sm text-slate-500">No placement breakdown yet.</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Spend by device</h2>
                <div v-if="devices.length" class="space-y-3">
                    <div v-for="row in devices" :key="row.dimension_key" class="space-y-1">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ row.dimension_label }}</span>
                            <span class="text-slate-600">{{ formatCurrency(row.cost) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width: `${Math.max(4, (row.cost / maxDeviceCost) * 100)}%`,
                                    backgroundColor: primaryColor,
                                }"
                            />
                        </div>
                    </div>
                </div>
                <p v-else class="py-8 text-center text-sm text-slate-500">No device breakdown yet.</p>
            </section>
        </div>
    </div>
</template>
