<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';

const props = defineProps({
    connection: {
        type: Object,
        required: true,
    },
});

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

        <div class="mb-8 grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Sync status</p>
                <p class="mt-1 text-lg font-semibold capitalize">{{ connection.sync_status }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Last synced</p>
                <p class="mt-1 text-lg font-semibold">{{ formatDateTime(connection.last_synced_at) }}</p>
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
                            <th class="px-4 py-3 font-medium">Finished</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in connection.sync_runs" :key="run.id" class="border-t border-slate-100">
                            <td class="px-4 py-3 capitalize">{{ run.type }}</td>
                            <td class="px-4 py-3 capitalize">{{ run.status }}</td>
                            <td class="px-4 py-3">{{ run.records_fetched }}</td>
                            <td class="px-4 py-3">{{ run.records_written }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatDateTime(run.finished_at) }}</td>
                        </tr>
                        <tr v-if="connection.sync_runs.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No sync runs yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
