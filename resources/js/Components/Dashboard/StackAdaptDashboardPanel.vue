<script setup>
import { computed } from 'vue';
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

function formatRoas(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return formatDecimal(value);
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
    conversions: 0,
    conversions_value: 0,
    secondary_conversions: 0,
    roas: null,
    cost_change_percent: null,
    impressions_change_percent: null,
    clicks_change_percent: null,
    ctr_change_percent: null,
    conversions_change_percent: null,
    conversions_value_change_percent: null,
    roas_change_percent: null,
});

const campaigns = computed(() => props.connectorData.campaigns ?? []);
const channels = computed(() => props.connectorData.channels ?? []);
const topGeos = computed(() => props.connectorData.top_geos ?? []);
const topDomains = computed(() => props.connectorData.top_domains ?? []);
const devices = computed(() => props.connectorData.devices ?? []);
const videoAudio = computed(() => props.connectorData.video_audio ?? { show: false, video_starts: 0, audio_starts: 0 });
const showPriorYearSpend = computed(() => (props.connectorData.prior_year_spend_series ?? []).length > 0);

const maxChannelCost = computed(() => {
    const values = channels.value.map((channel) => channel.cost ?? 0);

    return Math.max(...values, 1);
});
</script>

<template>
    <div>
        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Spend</p>
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
                <p class="text-sm text-slate-500">Conversions</p>
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
                <p class="text-sm text-slate-500">ROAS</p>
                <div class="mt-1 flex flex-wrap items-end gap-3">
                    <p class="text-3xl font-semibold">{{ formatRoas(summary.roas) }}</p>
                    <p
                        v-if="summary.roas_change_percent !== null"
                        class="pb-1 text-lg font-medium"
                        :class="changeClass(summary.roas_change_percent)"
                    >
                        {{ formatChange(summary.roas_change_percent) }}
                    </p>
                </div>
            </div>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">Spend over time</h2>
            <p class="mb-4 text-sm text-slate-500">
                Daily StackAdapt spend for {{ connectionName }} with prior-year overlay.
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

        <div class="mb-8 grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Channel mix</h2>
                <p class="mb-4 text-sm text-slate-500">Spend by StackAdapt channel type.</p>
                <div v-if="channels.length" class="space-y-3">
                    <div v-for="channel in channels" :key="channel.channel_type" class="space-y-1">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ channel.channel_type }}</span>
                            <span class="text-slate-600">{{ formatCurrency(channel.cost) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width: `${Math.max(4, (channel.cost / maxChannelCost) * 100)}%`,
                                    backgroundColor: primaryColor,
                                }"
                            />
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-500">No channel data in this date range.</p>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold">Conversion summary</h2>
                <p class="mb-4 text-sm text-slate-500">Revenue and secondary conversions from delivery data.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Conversion revenue</p>
                        <p class="mt-1 text-2xl font-semibold">{{ formatCurrency(summary.conversions_value) }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Secondary conversions</p>
                        <p class="mt-1 text-2xl font-semibold">{{ formatNumber(summary.secondary_conversions) }}</p>
                    </div>
                </div>
                <div v-if="videoAudio.show" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Video starts</p>
                        <p class="mt-1 text-2xl font-semibold">{{ formatNumber(videoAudio.video_starts) }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Audio starts</p>
                        <p class="mt-1 text-2xl font-semibold">{{ formatNumber(videoAudio.audio_starts) }}</p>
                    </div>
                </div>
            </section>
        </div>

        <section class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold">Top campaigns</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Top campaigns by spend for {{ connectionName }} in this date range.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Campaign</th>
                            <th class="px-4 py-3 font-medium">Group</th>
                            <th class="px-4 py-3 font-medium">Channel</th>
                            <th class="px-4 py-3 font-medium">Spend</th>
                            <th class="px-4 py-3 font-medium">Impr.</th>
                            <th class="px-4 py-3 font-medium">Clicks</th>
                            <th class="px-4 py-3 font-medium">Conv.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="campaign in campaigns"
                            :key="campaign.campaign_id"
                            class="border-t border-slate-100"
                        >
                            <td class="px-4 py-3 font-medium">{{ campaign.campaign_name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ campaign.campaign_group_name || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ campaign.channel_type || '—' }}</td>
                            <td class="px-4 py-3">{{ formatCurrency(campaign.cost) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.impressions) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.clicks) }}</td>
                            <td class="px-4 py-3">{{ formatNumber(campaign.conversions) }}</td>
                        </tr>
                        <tr v-if="campaigns.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                                No campaign data in this date range. Run a sync on this connection to pull StackAdapt history.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">Top geos</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Geo</th>
                                <th class="px-4 py-3 font-medium">Spend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in topGeos" :key="row.dimension_key" class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ row.dimension_label }}</td>
                                <td class="px-4 py-3">{{ formatCurrency(row.cost) }}</td>
                            </tr>
                            <tr v-if="topGeos.length === 0">
                                <td colspan="2" class="px-4 py-6 text-center text-slate-500">No geo insight data yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">Top domains</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Domain</th>
                                <th class="px-4 py-3 font-medium">Spend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in topDomains" :key="row.dimension_key" class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ row.dimension_label }}</td>
                                <td class="px-4 py-3">{{ formatCurrency(row.cost) }}</td>
                            </tr>
                            <tr v-if="topDomains.length === 0">
                                <td colspan="2" class="px-4 py-6 text-center text-slate-500">No domain insight data yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">Device split</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">Device</th>
                                <th class="px-4 py-3 font-medium">Spend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in devices" :key="row.dimension_key" class="border-t border-slate-100">
                                <td class="px-4 py-3">{{ row.dimension_label }}</td>
                                <td class="px-4 py-3">{{ formatCurrency(row.cost) }}</td>
                            </tr>
                            <tr v-if="devices.length === 0">
                                <td colspan="2" class="px-4 py-6 text-center text-slate-500">No device insight data yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
