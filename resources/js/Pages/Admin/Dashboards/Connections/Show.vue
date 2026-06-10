<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../../../Layouts/AppLayout.vue';

const props = defineProps({
    connection: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status);

function syncConnection() {
    router.post(route('admin.connections.sync', props.connection.id));
}

function backfillConnection() {
    router.post(route('admin.connections.backfill', props.connection.id));
}

function clearConnectionData() {
    if (!confirm(`Clear all synced data for ${props.connection.name}? Raw payloads, sync history, and metrics will be removed. Credentials are kept.`)) {
        return;
    }

    router.post(route('admin.connections.clear-data', props.connection.id));
}

const rebuildForm = useForm({});

function rebuildDashboard() {
    if (!confirm('Rebuild connector dashboard widgets with corrected SQL for synced payload fields?')) {
        return;
    }

    rebuildForm.post(route('admin.connections.rebuild-dashboard', props.connection.id));
}

function formatDateTime(isoString) {
    if (!isoString) {
        return '—';
    }

    return new Date(isoString).toLocaleString();
}
</script>

<template>
    <AppLayout :title="connection.name">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">
                    <Link :href="route('admin.dashboards.show', connection.dashboard.id)" class="hover:text-slate-700">
                        {{ connection.dashboard.company_name }} · {{ connection.dashboard.name }}
                    </Link>
                </p>
                <h1 class="text-3xl font-semibold">{{ connection.name }}</h1>
                <p class="mt-1 text-sm text-slate-600">
                    {{ connection.connector_label }}
                    <span
                        class="ml-2 rounded-full px-2 py-0.5 text-xs"
                        :class="connection.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                    >
                        {{ connection.is_active ? 'Active' : 'Inactive' }}
                    </span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('admin.connections.edit', connection.id)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Edit
                </Link>
                <button
                    type="button"
                    class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
                    @click="syncConnection"
                >
                    Sync
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="backfillConnection"
                >
                    Backfill
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50"
                    @click="clearConnectionData"
                >
                    Clear data
                </button>
            </div>
        </div>

        <div class="mb-8 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Sync status</p>
                <p class="mt-1 text-lg font-semibold capitalize">{{ connection.sync_status }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Last synced</p>
                <p class="mt-1 text-lg font-semibold">{{ formatDateTime(connection.last_synced_at) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Data coverage</p>
                <p class="mt-1 text-lg font-semibold">
                    <template v-if="connection.data_through_date">
                        Through {{ connection.data_through_date }}
                    </template>
                    <template v-else>—</template>
                </p>
                <p v-if="connection.data_from_date" class="mt-1 text-sm text-slate-500">
                    From {{ connection.data_from_date }}
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Backfill</p>
                <p class="mt-1 text-lg font-semibold">
                    {{ connection.backfill_completed_at ? 'Complete' : connection.backfill_started_at ? 'In progress' : 'Not started' }}
                </p>
            </div>
        </div>

        <p v-if="connection.sync_error" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ connection.sync_error }}
        </p>

        <p v-if="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ status }}
        </p>

        <section
            v-if="connection.connector_dashboard"
            class="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Connector dashboard</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ connection.connector_dashboard.title }}
                        · {{ connection.connector_dashboard.widget_count }} widget(s)
                    </p>
                    <p class="mt-2 text-sm text-slate-500">
                        Saved dashboards live on the client dashboard Saved tab, not on this connection page.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        :href="connection.connector_dashboard.saved_dashboard_url"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                        target="_blank"
                        rel="noopener"
                    >
                        Open saved dashboard
                    </a>
                    <button
                        type="button"
                        class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                        :disabled="rebuildForm.processing"
                        @click="rebuildDashboard"
                    >
                        {{ rebuildForm.processing ? 'Rebuilding…' : 'Rebuild widgets' }}
                    </button>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold">Recent sync runs</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Fetched</th>
                            <th class="px-4 py-3 font-medium">Written</th>
                            <th class="px-4 py-3 font-medium">Through date</th>
                            <th class="px-4 py-3 font-medium">Finished</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in connection.sync_runs" :key="run.id" class="border-t border-slate-100">
                            <td class="px-4 py-3 capitalize">{{ run.type }}</td>
                            <td class="px-4 py-3 capitalize">{{ run.status }}</td>
                            <td class="px-4 py-3">{{ run.records_fetched }}</td>
                            <td class="px-4 py-3">{{ run.records_written }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ run.progress_through_date ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatDateTime(run.finished_at) }}</td>
                        </tr>
                        <tr v-if="connection.sync_runs.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No sync runs yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
