<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    view: {
        type: String,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
    records: {
        type: Object,
        required: true,
    },
    filter_options: {
        type: Object,
        required: true,
    },
    sort_options: {
        type: Array,
        required: true,
    },
});

const isPayloadView = computed(() => props.view === 'payloads');

function applyFilters(overrides = {}) {
    router.get(route('admin.gathered-analytics.index'), {
        ...props.filters,
        ...overrides,
    }, {
        preserveState: true,
        replace: true,
    });
}

function switchView(nextView) {
    applyFilters({
        view: nextView,
        sort: nextView === 'metrics' ? 'snapshot_date' : 'fetched_at',
    });
}

function toggleDirection(column) {
    if (props.filters.sort === column) {
        applyFilters({ direction: props.filters.direction === 'asc' ? 'desc' : 'asc' });

        return;
    }

    applyFilters({ sort: column, direction: 'desc' });
}

function sortIndicator(column) {
    if (props.filters.sort !== column) {
        return '';
    }

    return props.filters.direction === 'asc' ? ' ↑' : ' ↓';
}

function clearFilters() {
    router.get(route('admin.gathered-analytics.index'), { view: props.view });
}

function formatNumber(value) {
    return new Intl.NumberFormat().format(value);
}
</script>

<template>
    <AppLayout title="Gathered analytics">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Gathered analytics</h1>
            <p class="mt-2 text-sm text-slate-600">
                Browse and sort synced raw payloads and transformed metric snapshots across dashboards.
            </p>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Raw payloads</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatNumber(summary.payload_count) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Metric snapshots</p>
                <p class="mt-1 text-2xl font-semibold">{{ formatNumber(summary.metric_count) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 md:col-span-2 xl:col-span-2">
                <p class="text-xs uppercase tracking-wide text-slate-500">In scope</p>
                <p class="mt-1 text-sm text-slate-700">
                    {{ summary.resource_types.length }} resource type{{ summary.resource_types.length === 1 ? '' : 's' }}
                    · {{ summary.metric_keys.length }} metric key{{ summary.metric_keys.length === 1 ? '' : 's' }}
                </p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :class="isPayloadView ? 'bg-slate-900 text-white' : 'border border-slate-300 hover:bg-slate-50'"
                @click="switchView('payloads')"
            >
                Raw payloads
            </button>
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :class="!isPayloadView ? 'bg-slate-900 text-white' : 'border border-slate-300 hover:bg-slate-50'"
                @click="switchView('metrics')"
            >
                Metric snapshots
            </button>
        </div>

        <form
            class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2 xl:grid-cols-6"
            @submit.prevent="applyFilters()"
        >
            <div>
                <label for="dashboard_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Dashboard</label>
                <select
                    id="dashboard_id"
                    :value="filters.dashboard_id ?? ''"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ dashboard_id: $event.target.value || null })"
                >
                    <option value="">All dashboards</option>
                    <option v-for="dashboard in filter_options.dashboards" :key="dashboard.id" :value="dashboard.id">
                        {{ dashboard.company_name }} · {{ dashboard.name }}
                    </option>
                </select>
            </div>

            <div>
                <label for="connection_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Connection</label>
                <select
                    id="connection_id"
                    :value="filters.connection_id ?? ''"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ connection_id: $event.target.value || null })"
                >
                    <option value="">All connections</option>
                    <option v-for="connection in filter_options.connections" :key="connection.id" :value="connection.id">
                        {{ connection.company_name }} · {{ connection.name }}
                    </option>
                </select>
            </div>

            <div v-if="isPayloadView">
                <label for="resource_type" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Resource type</label>
                <select
                    id="resource_type"
                    :value="filters.resource_type ?? ''"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ resource_type: $event.target.value || null })"
                >
                    <option value="">All resource types</option>
                    <option v-for="resourceType in filter_options.resource_types" :key="resourceType" :value="resourceType">
                        {{ resourceType }}
                    </option>
                </select>
            </div>

            <div v-else>
                <label for="metric_key" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Metric key</label>
                <select
                    id="metric_key"
                    :value="filters.metric_key ?? ''"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ metric_key: $event.target.value || null })"
                >
                    <option value="">All metric keys</option>
                    <option v-for="metricKey in filter_options.metric_keys" :key="metricKey" :value="metricKey">
                        {{ metricKey }}
                    </option>
                </select>
            </div>

            <div>
                <label for="date_from" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">From</label>
                <input
                    id="date_from"
                    :value="filters.date_from ?? ''"
                    type="date"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ date_from: $event.target.value || null })"
                >
            </div>

            <div>
                <label for="date_to" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">To</label>
                <input
                    id="date_to"
                    :value="filters.date_to ?? ''"
                    type="date"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ date_to: $event.target.value || null })"
                >
            </div>

            <div class="md:col-span-2 xl:col-span-6">
                <label for="search" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Search</label>
                <div class="flex flex-wrap gap-2">
                    <input
                        id="search"
                        :value="filters.search"
                        type="search"
                        :placeholder="isPayloadView ? 'External ID or resource type' : 'Metric key'"
                        class="min-w-[16rem] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        @keyup.enter="applyFilters({ search: $event.target.value })"
                    >
                    <select
                        :value="filters.sort"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        @change="applyFilters({ sort: $event.target.value })"
                    >
                        <option v-for="option in sort_options" :key="option.value" :value="option.value">
                            Sort: {{ option.label }}
                        </option>
                    </select>
                    <select
                        :value="filters.direction"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        @change="applyFilters({ direction: $event.target.value })"
                    >
                        <option value="desc">Descending</option>
                        <option value="asc">Ascending</option>
                    </select>
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                        Apply
                    </button>
                    <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50" @click="clearFilters">
                        Clear
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table v-if="isPayloadView" class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('fetched_at')">
                                Fetched{{ sortIndicator('fetched_at') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Connection</th>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('resource_type')">
                                Resource{{ sortIndicator('resource_type') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('external_id')">
                                External ID{{ sortIndicator('external_id') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('payload_date')">
                                Payload date{{ sortIndicator('payload_date') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Preview</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="record in records.data" :key="record.id" class="border-t border-slate-100 align-top hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-600">{{ record.fetched_at ? new Date(record.fetched_at).toLocaleString() : '—' }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ record.connection?.name ?? '—' }}</p>
                            <p class="text-xs text-slate-500">{{ record.connection?.company_name }} · {{ record.connection?.dashboard_name }}</p>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ record.resource_type }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ record.external_id || '—' }}</td>
                        <td class="px-4 py-3">{{ record.payload_date || '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ record.payload_preview }}</td>
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.gathered-analytics.payloads.show', record.id)"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-white"
                            >
                                View details
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="records.data.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">No raw payloads match these filters.</td>
                    </tr>
                </tbody>
            </table>

            <table v-else class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('snapshot_date')">
                                Date{{ sortIndicator('snapshot_date') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Dashboard</th>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('metric_key')">
                                Metric{{ sortIndicator('metric_key') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">
                            <button type="button" class="hover:text-primary" @click="toggleDirection('metric_value')">
                                Value{{ sortIndicator('metric_value') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 font-medium">Dimensions</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="record in records.data" :key="record.id" class="border-t border-slate-100 align-top hover:bg-slate-50">
                        <td class="px-4 py-3">{{ record.snapshot_date }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ record.dashboard?.name ?? '—' }}</p>
                            <p class="text-xs text-slate-500">{{ record.dashboard?.company_name }}</p>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ record.metric_key }}</td>
                        <td class="px-4 py-3">
                            {{ record.metric_value }}
                            <span v-if="record.currency" class="text-xs text-slate-500">{{ record.currency }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ JSON.stringify(record.dimensions) }}</td>
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.gathered-analytics.metrics.show', record.id)"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-white"
                            >
                                View details
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="records.data.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">No metric snapshots match these filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="records.links?.length > 3" class="mt-6 flex flex-wrap gap-2">
            <Link
                v-for="link in records.links"
                :key="link.label"
                :href="link.url || '#'"
                class="rounded-lg px-3 py-1 text-sm"
                :class="link.active ? 'bg-slate-900 text-white' : 'border border-slate-300 hover:bg-slate-50'"
                :preserve-state="true"
                v-html="link.label"
            />
        </div>
    </AppLayout>
</template>
