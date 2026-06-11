<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    log: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AppLayout :title="`Connector API Log #${log.id}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.connector-api-logs.index')" class="hover:text-slate-700">Connector API Logs</Link>
            </p>
            <h1 class="text-3xl font-semibold">Log #{{ log.id }}</h1>
            <p class="mt-2 text-sm text-slate-600">
                {{ log.method }} {{ log.url }}
            </p>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">When</p>
                <p class="mt-1 font-medium">{{ new Date(log.created_at).toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Status</p>
                <p class="mt-1 font-medium">{{ log.status_code ?? '—' }} · {{ log.duration_ms }} ms</p>
                <p v-if="log.error_message" class="mt-1 text-sm text-red-700">{{ log.error_message }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Context</p>
                <p class="mt-1 font-medium">{{ log.context_label }}</p>
                <p v-if="log.stream_key" class="mt-1 text-sm text-slate-600">Stream: {{ log.stream_key }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Connection</p>
                <p v-if="log.connection" class="mt-1 font-medium">
                    <Link :href="route('admin.connections.show', log.connection.id)" class="text-primary hover:underline">
                        {{ log.connection.name }}
                    </Link>
                </p>
                <p v-else class="mt-1 text-slate-500">No connection linked</p>
                <p v-if="log.blueprint" class="mt-1 text-sm text-slate-600">{{ log.blueprint.label }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Request</h2>
                <div class="mt-4 space-y-4 text-sm">
                    <div>
                        <p class="font-medium text-slate-700">Query</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ JSON.stringify(log.request_query, null, 2) }}</pre>
                    </div>
                    <div>
                        <p class="font-medium text-slate-700">Body</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ JSON.stringify(log.request_body, null, 2) }}</pre>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-semibold">Raw response</h2>
                <pre
                    v-if="log.formatted_response"
                    class="mt-4 max-h-[32rem] overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"
                >{{ log.formatted_response }}</pre>
                <p v-else class="mt-4 text-sm text-slate-500">No response body captured.</p>
            </section>
        </div>
    </AppLayout>
</template>
