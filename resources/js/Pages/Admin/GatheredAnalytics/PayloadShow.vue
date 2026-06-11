<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    payload: {
        type: Object,
        required: true,
    },
});

const copied = ref(false);

async function copyPayload() {
    await navigator.clipboard.writeText(props.payload.formatted_payload);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 1500);
}
</script>

<template>
    <AppLayout :title="`Payload #${payload.id}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.gathered-analytics.index', { connection_id: payload.connection?.id })" class="hover:text-slate-700">
                    Gathered analytics
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">Raw payload #{{ payload.id }}</h1>
            <p class="mt-2 font-mono text-sm text-slate-600">{{ payload.resource_type }} · {{ payload.external_id || 'no external id' }}</p>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Fetched</p>
                <p class="mt-1 font-medium">{{ payload.fetched_at ? new Date(payload.fetched_at).toLocaleString() : '—' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Payload date</p>
                <p class="mt-1 font-medium">{{ payload.payload_date || '—' }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Connection</p>
                <p v-if="payload.connection" class="mt-1 font-medium">
                    <Link :href="route('admin.connections.show', payload.connection.id)" class="text-primary hover:underline">
                        {{ payload.connection.name }}
                    </Link>
                </p>
                <p v-if="payload.connection" class="mt-1 text-sm text-slate-600">
                    {{ payload.connection.company_name }} · {{ payload.connection.dashboard_name }}
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Sync run</p>
                <p v-if="payload.sync_run" class="mt-1 font-medium">
                    #{{ payload.sync_run.id }} · {{ payload.sync_run.type }} · {{ payload.sync_run.status }}
                </p>
                <p v-else class="mt-1 text-slate-500">—</p>
            </div>
        </div>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Payload JSON</h2>
                <button type="button" class="text-sm text-primary hover:underline" @click="copyPayload">
                    {{ copied ? 'Copied' : 'Copy JSON' }}
                </button>
            </div>
            <pre class="max-h-[40rem] overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ payload.formatted_payload }}</pre>
        </section>
    </AppLayout>
</template>
