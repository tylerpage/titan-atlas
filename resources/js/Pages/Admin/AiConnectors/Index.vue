<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    companies: {
        type: Array,
        required: true,
    },
    blueprints: {
        type: Array,
        required: true,
    },
    company: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status);

const filteredBlueprints = computed(() => {
    if (!props.company) {
        return props.blueprints;
    }

    return props.blueprints;
});

function destroyBlueprint(blueprint) {
    if (!confirm(`Delete "${blueprint.label}"? This cannot be undone.`)) {
        return;
    }

    router.delete(route('admin.ai-connectors.destroy', blueprint.id));
}

function shareBlueprint(blueprintId) {
    router.post(route('admin.ai-connectors.share', blueprintId));
}
</script>

<template>
    <AppLayout title="AI Connectors">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">AI Connectors</h1>
                <p class="mt-2 text-sm text-slate-600">
                    Reusable connector templates shared across dashboards. Each dashboard connection keeps its own credentials.
                </p>
            </div>
        </div>

        <p v-if="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ status }}
        </p>

        <div class="mb-6 flex flex-wrap gap-2">
            <Link
                :href="route('admin.ai-connectors.index')"
                class="rounded-lg px-3 py-2 text-sm"
                :class="!company ? 'bg-slate-900 text-white' : 'border border-slate-300 hover:bg-slate-50'"
            >
                All companies
            </Link>
            <Link
                v-for="item in companies"
                :key="item.id"
                :href="route('admin.companies.ai-connectors.index', item.id)"
                class="rounded-lg px-3 py-2 text-sm"
                :class="company?.id === item.id ? 'bg-slate-900 text-white' : 'border border-slate-300 hover:bg-slate-50'"
            >
                {{ item.name }}
                <span v-if="item.connector_blueprints_count" class="opacity-70">({{ item.connector_blueprints_count }})</span>
            </Link>
        </div>

        <div v-if="filteredBlueprints.length === 0" class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
            No AI connectors yet. Build one from a dashboard using <strong>New AI Connector</strong>, then share it here.
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="blueprint in filteredBlueprints"
                :key="blueprint.id"
                class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold">{{ blueprint.label }}</h2>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ blueprint.status }}</span>
                            <span
                                v-if="blueprint.is_shared"
                                class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800"
                            >
                                Shared
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ blueprint.company?.name }} · {{ blueprint.slug }}
                            · {{ blueprint.streams_count }} streams
                            · {{ blueprint.connections_count }} connection{{ blueprint.connections_count === 1 ? '' : 's' }}
                        </p>
                        <p v-if="blueprint.dashboard && !blueprint.is_shared" class="mt-1 text-xs text-slate-500">
                            Created on {{ blueprint.dashboard.name }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="route('admin.ai-connectors.show', blueprint.id)"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                        >
                            View
                        </Link>
                        <Link
                            :href="route('admin.ai-connectors.edit', blueprint.id)"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                        >
                            Edit
                        </Link>
                        <button
                            v-if="!blueprint.is_shared"
                            type="button"
                            class="rounded-lg border border-sky-300 px-3 py-2 text-sm text-sky-800 hover:bg-sky-50"
                            @click="shareBlueprint(blueprint.id)"
                        >
                            Share company-wide
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-700 hover:bg-red-50"
                            @click="destroyBlueprint(blueprint)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
