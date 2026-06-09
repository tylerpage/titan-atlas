<script setup>
import { Link, router } from '@inertiajs/vue3';
import { toRef } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DashboardSyncingBadge from '../../../Components/DashboardSyncingBadge.vue';
import { useDashboardSyncPoll } from '../../../Composables/useDashboardSyncPoll';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
});

useDashboardSyncPoll(toRef(() => props.dashboard.is_syncing));

function syncConnection(connectionId) {
    router.post(route('admin.connections.sync', connectionId));
}

function backfillConnection(connectionId) {
    router.post(route('admin.connections.backfill', connectionId));
}

function clearConnectionData(connection) {
    if (!confirm(`Clear all synced data for ${connection.name}? Raw payloads, sync history, and metrics will be removed. Credentials are kept.`)) {
        return;
    }

    router.post(route('admin.connections.clear-data', connection.id));
}

function formatRelativeTime(isoString) {
    if (!isoString) {
        return null;
    }

    const date = new Date(isoString);
    const diffMs = date.getTime() - Date.now();
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const minutes = Math.round(diffMs / 60000);

    if (Math.abs(minutes) < 60) {
        return rtf.format(minutes, 'minute');
    }

    const hours = Math.round(minutes / 60);

    if (Math.abs(hours) < 24) {
        return rtf.format(hours, 'hour');
    }

    const days = Math.round(hours / 24);

    return rtf.format(days, 'day');
}
</script>

<template>
    <AppLayout :title="dashboard.name">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">{{ dashboard.company.name }}</p>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-semibold">{{ dashboard.name }}</h1>
                    <DashboardSyncingBadge :show="dashboard.is_syncing" />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('admin.dashboards.cover-pages.index', dashboard.id)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Cover pages
                </Link>
                <Link
                    :href="route('admin.dashboards.reports.index', dashboard.id)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    AI Reports
                </Link>
                <Link
                    :href="route('admin.dashboards.edit', dashboard.id)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Edit dashboard
                </Link>
                <Link
                    :href="route('client.dashboard.show', dashboard.slug)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    View as client
                </Link>
            </div>
        </div>

        <section class="mb-10">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                <h2 class="text-xl font-semibold">Connections</h2>
                <Link
                    :href="route('admin.dashboards.connections.create', dashboard.id)"
                    class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
                >
                    Add connection
                </Link>
            </div>
            <div class="space-y-4">
                <div
                    v-for="connection in dashboard.connections"
                    :key="connection.id"
                    class="rounded-xl border border-slate-200 bg-white p-5"
                >
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="route('admin.connections.show', connection.id)"
                                    class="font-semibold hover:text-primary"
                                >
                                    {{ connection.name }}
                                </Link>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="connection.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                                >
                                    {{ connection.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <p class="text-sm text-slate-600">
                                {{ connection.connector_label }}
                                · Status: {{ connection.sync_status }}
                            </p>
                            <p v-if="connection.last_synced_at" class="text-sm text-slate-500">
                                Last synced {{ formatRelativeTime(connection.last_synced_at) }}
                            </p>
                            <p v-if="connection.sync_error" class="mt-1 text-sm text-red-600">
                                {{ connection.sync_error }}
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
                                @click="syncConnection(connection.id)"
                            >
                                Sync
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                                @click="backfillConnection(connection.id)"
                            >
                                Backfill
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50"
                                @click="clearConnectionData(connection)"
                            >
                                Clear data
                            </button>
                        </div>
                    </div>

                    <div v-if="connection.sync_runs?.length" class="mt-4 border-t border-slate-100 pt-4">
                        <p class="mb-2 text-sm font-medium text-slate-700">Recent sync runs</p>
                        <ul class="space-y-1 text-sm text-slate-600">
                            <li v-for="run in connection.sync_runs" :key="run.id">
                                {{ run.type }} · {{ run.status }}
                                · fetched {{ run.records_fetched }}, written {{ run.records_written }}
                            </li>
                        </ul>
                    </div>
                </div>
                <p v-if="!dashboard.connections?.length" class="text-slate-500">No connections configured.</p>
            </div>
        </section>

        <section>
            <h2 class="mb-4 text-xl font-semibold">Widgets</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="widget in dashboard.widget_placements"
                    :key="widget.id"
                    class="rounded-xl border border-slate-200 bg-white p-4"
                >
                    <p class="font-medium">{{ widget.title ?? widget.widget_type_label }}</p>
                    <p class="text-sm text-slate-500">{{ widget.widget_type }}</p>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
