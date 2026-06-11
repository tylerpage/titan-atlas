<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    logs: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    filter_options: {
        type: Object,
        required: true,
    },
    retention_hours: {
        type: Number,
        default: 48,
    },
});

const filterForm = computed(() => ({
    connection_id: props.filters.connection_id ?? '',
    connector_blueprint_id: props.filters.connector_blueprint_id ?? '',
    context: props.filters.context ?? '',
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
}));

function applyFilters(overrides = {}) {
    router.get(route('admin.connector-api-logs.index'), {
        ...filterForm.value,
        ...overrides,
    }, {
        preserveState: true,
        replace: true,
    });
}

function clearFilters() {
    router.get(route('admin.connector-api-logs.index'), {}, {
        preserveState: true,
        replace: true,
    });
}

function statusClass(log) {
    if (log.succeeded) {
        return 'bg-emerald-100 text-emerald-800';
    }

    return 'bg-red-100 text-red-800';
}
</script>

<template>
    <AppLayout title="Connector API Logs">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Connector API Logs</h1>
            <p class="mt-2 text-sm text-slate-600">
                Raw API traffic for dynamic/AI connectors during sync, credential tests, and builder probes.
                Entries auto-delete after {{ retention_hours }} hours.
            </p>
        </div>

        <form
            class="mb-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2 xl:grid-cols-6"
            @submit.prevent="applyFilters()"
        >
            <div>
                <label for="connection_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Connection</label>
                <select
                    id="connection_id"
                    :value="filterForm.connection_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ connection_id: $event.target.value })"
                >
                    <option value="">All connections</option>
                    <option v-for="connection in filter_options.connections" :key="connection.id" :value="connection.id">
                        {{ connection.name }}
                    </option>
                </select>
            </div>

            <div>
                <label for="connector_blueprint_id" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Blueprint</label>
                <select
                    id="connector_blueprint_id"
                    :value="filterForm.connector_blueprint_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ connector_blueprint_id: $event.target.value })"
                >
                    <option value="">All blueprints</option>
                    <option v-for="blueprint in filter_options.blueprints" :key="blueprint.id" :value="blueprint.id">
                        {{ blueprint.label }}
                    </option>
                </select>
            </div>

            <div>
                <label for="context" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Context</label>
                <select
                    id="context"
                    :value="filterForm.context"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ context: $event.target.value })"
                >
                    <option value="">All contexts</option>
                    <option v-for="context in filter_options.contexts" :key="context.value" :value="context.value">
                        {{ context.label }}
                    </option>
                </select>
            </div>

            <div>
                <label for="status" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Result</label>
                <select
                    id="status"
                    :value="filterForm.status"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    @change="applyFilters({ status: $event.target.value })"
                >
                    <option value="">All results</option>
                    <option value="success">Success</option>
                    <option value="failed">Failed</option>
                </select>
            </div>

            <div class="md:col-span-2 xl:col-span-2">
                <label for="search" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Search URL / stream</label>
                <div class="flex gap-2">
                    <input
                        id="search"
                        :value="filterForm.search"
                        type="search"
                        placeholder="Filter by URL, stream, or resource type"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        @keyup.enter="applyFilters({ search: $event.target.value })"
                    >
                    <button
                        type="submit"
                        class="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90"
                    >
                        Search
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                        @click="clearFilters"
                    >
                        Clear
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Connection</th>
                        <th class="px-4 py-3 font-medium">Request</th>
                        <th class="px-4 py-3 font-medium">Context</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Preview</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="log in logs.data"
                        :key="log.id"
                        class="border-t border-slate-100 align-top hover:bg-slate-50"
                    >
                        <td class="px-4 py-3 text-slate-600">
                            {{ new Date(log.created_at).toLocaleString() }}
                            <p class="text-xs text-slate-400">{{ log.duration_ms }} ms</p>
                        </td>
                        <td class="px-4 py-3">
                            <p v-if="log.connection" class="font-medium">{{ log.connection.name }}</p>
                            <p v-else class="text-slate-500">No connection</p>
                            <p v-if="log.blueprint" class="text-xs text-slate-500">{{ log.blueprint.label }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.connector-api-logs.show', log.id)"
                                class="font-mono text-xs text-primary hover:underline"
                            >
                                {{ log.method }} {{ log.url }}
                            </Link>
                            <p v-if="log.stream_key" class="mt-1 text-xs text-slate-500">
                                Stream: {{ log.stream_key }}
                            </p>
                        </td>
                        <td class="px-4 py-3">{{ log.context_label }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(log)">
                                {{ log.status_code ?? '—' }}
                            </span>
                            <p v-if="log.error_message" class="mt-1 text-xs text-red-700">{{ log.error_message }}</p>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ log.response_preview || '—' }}</td>
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.connector-api-logs.show', log.id)"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-white"
                            >
                                View details
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="logs.data.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                            No connector API logs yet. Run a sync or credential test on a dynamic connector.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="logs.links?.length > 3" class="mt-6 flex flex-wrap gap-2">
            <Link
                v-for="link in logs.links"
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
