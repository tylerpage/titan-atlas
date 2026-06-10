<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    blueprint: {
        type: Object,
        required: true,
    },
});

function testBlueprint() {
    router.post(route('admin.connector-blueprints.test', props.blueprint.id));
}

function activateBlueprint() {
    router.post(route('admin.connector-blueprints.activate', props.blueprint.id));
}

function exportDevTasks() {
    navigator.clipboard.writeText(JSON.stringify(props.blueprint.dev_tasks ?? [], null, 2));
}
</script>

<template>
    <AppLayout :title="`${blueprint.label} · Blueprint`">
        <div class="mb-6">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.dashboards.show', dashboard.id)" class="hover:text-slate-700">
                    {{ dashboard.company_name }} · {{ dashboard.name }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">{{ blueprint.label }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ blueprint.slug }} · {{ blueprint.status }}</p>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                @click="testBlueprint"
            >
                Test connection
            </button>
            <button
                v-if="blueprint.connection"
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

                <div v-if="blueprint.connection" class="rounded-xl border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-lg font-semibold">Connection</h2>
                    <Link :href="route('admin.connections.show', blueprint.connection.id)" class="text-primary hover:underline">
                        {{ blueprint.connection.name }}
                    </Link>
                    <p class="mt-2 text-sm text-slate-600">Sync status: {{ blueprint.connection.sync_status }}</p>
                    <p v-if="blueprint.connection.sync_error" class="mt-1 text-sm text-red-600">
                        {{ blueprint.connection.sync_error }}
                    </p>
                </div>

                <div v-if="blueprint.dev_tasks?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                    <h2 class="mb-3 text-lg font-semibold text-amber-900">Developer tasks</h2>
                    <ul class="space-y-3 text-sm text-amber-900">
                        <li v-for="(task, index) in blueprint.dev_tasks" :key="index">
                            <p class="font-medium">{{ task.task }}</p>
                            <p>{{ task.reason }}</p>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
