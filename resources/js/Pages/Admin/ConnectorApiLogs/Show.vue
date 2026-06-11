<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    log: {
        type: Object,
        required: true,
    },
});

const copiedSection = ref(null);

function statusClass() {
    return props.log.succeeded ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800';
}

async function copyText(section, text) {
    await navigator.clipboard.writeText(text);
    copiedSection.value = section;
    window.setTimeout(() => {
        if (copiedSection.value === section) {
            copiedSection.value = null;
        }
    }, 1500);
}
</script>

<template>
    <AppLayout :title="`Connector API Log #${log.id}`">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">
                    <Link :href="route('admin.connector-api-logs.index')" class="hover:text-slate-700">Connector API Logs</Link>
                </p>
                <h1 class="text-3xl font-semibold">Request details</h1>
                <p class="mt-2 font-mono text-sm text-slate-600">{{ log.method }} {{ log.url }}</p>
            </div>
            <span class="rounded-full px-3 py-1 text-sm font-medium" :class="statusClass()">
                {{ log.status_code ?? '—' }}
            </span>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">When</p>
                <p class="mt-1 font-medium">{{ new Date(log.created_at).toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Duration</p>
                <p class="mt-1 font-medium">{{ log.duration_ms }} ms</p>
                <p v-if="log.error_message" class="mt-1 text-sm text-red-700">{{ log.error_message }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Context</p>
                <p class="mt-1 font-medium">{{ log.context_label }}</p>
                <p v-if="log.stream_key" class="mt-1 text-sm text-slate-600">Stream: {{ log.stream_key }}</p>
                <p v-if="log.resource_type" class="mt-1 text-sm text-slate-600">Resource: {{ log.resource_type }}</p>
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

        <section class="mb-6 rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Request</h2>
                <span v-if="log.request_body_format" class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">
                    Body format: {{ log.request_body_format }}
                </span>
            </div>

            <div class="mt-4 space-y-5">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">URL</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="copyText('url', log.url)"
                        >
                            {{ copiedSection === 'url' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ log.url }}</pre>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Headers</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="copyText('headers', log.formatted_request_headers)"
                        >
                            {{ copiedSection === 'headers' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ log.formatted_request_headers }}</pre>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Query parameters</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="copyText('query', log.formatted_request_query)"
                        >
                            {{ copiedSection === 'query' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ log.formatted_request_query }}</pre>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Body</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="copyText('body', log.formatted_request_body)"
                        >
                            {{ copiedSection === 'body' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ log.formatted_request_body }}</pre>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Response</h2>

            <div class="mt-4 space-y-5">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Headers</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="copyText('response-headers', log.formatted_response_headers)"
                        >
                            {{ copiedSection === 'response-headers' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ log.formatted_response_headers }}</pre>
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-slate-700">Raw body</p>
                        <button
                            v-if="log.formatted_response"
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="copyText('response', log.formatted_response)"
                        >
                            {{ copiedSection === 'response' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <pre
                        v-if="log.formatted_response"
                        class="max-h-[32rem] overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"
                    >{{ log.formatted_response }}</pre>
                    <p v-else class="text-sm text-slate-500">No response body captured.</p>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
