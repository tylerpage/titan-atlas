<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import { copyAiConnectorDetails } from '../../../../Composables/useAiConnectorClipboard';

const props = defineProps({
    dashboard: {
        type: Object,
        default: null,
    },
    company: {
        type: Object,
        required: true,
    },
    blueprint: {
        type: Object,
        required: true,
    },
});

const copiedDetails = ref(false);

async function copyConnectorDetails() {
    const copied = await copyAiConnectorDetails(props.blueprint);

    if (!copied) {
        return;
    }

    copiedDetails.value = true;
    setTimeout(() => {
        copiedDetails.value = false;
    }, 2000);
}

function testBlueprint() {
    router.post(route('admin.connector-blueprints.test', props.blueprint.id));
}

function activateBlueprint() {
    router.post(route('admin.connector-blueprints.activate', props.blueprint.id));
}

function shareBlueprint() {
    router.post(route('admin.ai-connectors.share', props.blueprint.id));
}

function shareBlueprintGlobally() {
    router.post(route('admin.ai-connectors.share-global', props.blueprint.id));
}

function exportDevTasks() {
    navigator.clipboard.writeText(JSON.stringify(props.blueprint.dev_tasks ?? [], null, 2));
}
</script>

<template>
    <AppLayout :title="`${blueprint.label} · AI Connector`">
        <div class="mb-6">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.ai-connectors.index')" class="hover:text-slate-700">AI Connectors</Link>
                <span v-if="company"> · {{ company.name }}</span>
                <span v-if="dashboard">
                    ·
                    <Link :href="route('admin.dashboards.show', dashboard.id)" class="hover:text-slate-700">
                        {{ dashboard.name }}
                    </Link>
                </span>
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-semibold">{{ blueprint.label }}</h1>
                <span v-if="blueprint.is_global" class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800">
                    Global
                </span>
                <span v-else-if="blueprint.is_shared" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">
                    Shared
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ blueprint.slug }} · {{ blueprint.status }}</p>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <Link
                v-if="blueprint.chat_url"
                :href="blueprint.chat_url"
                class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
            >
                Continue in AI chat
            </Link>
            <Link
                :href="route('admin.ai-connectors.edit', blueprint.id)"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
            >
                Edit
            </Link>
            <a
                :href="route('admin.ai-connectors.export', blueprint.id)"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
            >
                Export JSON
            </a>
            <button
                type="button"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                @click="copyConnectorDetails"
            >
                {{ copiedDetails ? 'Copied!' : 'Copy details for AI' }}
            </button>
            <button
                type="button"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                @click="testBlueprint"
            >
                Test connection
            </button>
            <button
                v-if="!blueprint.is_shared && !blueprint.is_global"
                type="button"
                class="rounded-lg border border-sky-300 px-4 py-2 text-sm text-sky-800 hover:bg-sky-50"
                @click="shareBlueprint"
            >
                Share company-wide
            </button>
            <button
                v-if="!blueprint.is_global"
                type="button"
                class="rounded-lg border border-violet-300 px-4 py-2 text-sm text-violet-800 hover:bg-violet-50"
                @click="shareBlueprintGlobally"
            >
                Share globally
            </button>
            <button
                v-if="blueprint.connections?.length"
                type="button"
                class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
                @click="activateBlueprint"
            >
                Mark active
            </button>
            <button
                v-if="blueprint.dev_tasks?.length"
                type="button"
                class="rounded-lg border border-amber-300 px-4 py-2 text-sm text-amber-800 hover:bg-amber-50"
                @click="exportDevTasks"
            >
                Copy dev tasks
            </button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-lg font-semibold">Configuration</h2>
                <pre class="overflow-auto rounded-lg bg-slate-50 p-4 text-xs text-slate-700">{{ blueprint.sync_config }}</pre>
                <h3 class="mb-2 mt-4 font-medium">Auth</h3>
                <pre class="overflow-auto rounded-lg bg-slate-50 p-4 text-xs text-slate-700">{{ blueprint.auth_config }}</pre>
                <h3 class="mb-2 mt-4 font-medium">Transform</h3>
                <pre class="overflow-auto rounded-lg bg-slate-50 p-4 text-xs text-slate-700">{{ blueprint.transform_config }}</pre>
            </section>

            <section class="space-y-6">
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-lg font-semibold">Streams</h2>
                    <ul class="space-y-3 text-sm">
                        <li v-for="stream in blueprint.streams" :key="stream.id" class="rounded-lg bg-slate-50 p-3">
                            <p class="font-medium">{{ stream.stream_key }}</p>
                            <p class="text-slate-600">{{ stream.http_method }} {{ stream.path_template }}</p>
                            <p class="text-slate-500">resource_type: {{ stream.resource_type }}</p>
                        </li>
                    </ul>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-lg font-semibold">Dashboard connections</h2>
                    <p v-if="!blueprint.connections?.length" class="text-sm text-slate-600">
                        No dashboard connections yet. Add this template from any dashboard in {{ company.name }}.
                    </p>
                    <ul v-else class="space-y-3 text-sm">
                        <li v-for="connection in blueprint.connections" :key="connection.id" class="rounded-lg bg-slate-50 p-3">
                            <Link :href="route('admin.connections.show', connection.id)" class="font-medium text-primary hover:underline">
                                {{ connection.name }}
                            </Link>
                            <p class="text-slate-500">{{ connection.dashboard.name }} · {{ connection.sync_status }}</p>
                            <p v-if="connection.sync_error" class="mt-1 text-red-600">{{ connection.sync_error }}</p>
                        </li>
                    </ul>
                </div>

                <div v-if="blueprint.dev_tasks?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                    <h2 class="mb-3 text-lg font-semibold text-amber-900">Developer tasks</h2>
                    <pre class="overflow-auto text-xs text-amber-950">{{ blueprint.dev_tasks }}</pre>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
