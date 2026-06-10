<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    dashboards: {
        type: Array,
        required: true,
    },
    defaultSandboxDashboardId: {
        type: Number,
        default: null,
    },
});

const form = useForm({
    sandbox_dashboard_id: props.defaultSandboxDashboardId ?? '',
});

function submit() {
    form.post(route('admin.ai-connectors.store'));
}
</script>

<template>
    <AppLayout title="Create global AI connector">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.ai-connectors.index')" class="hover:text-slate-700">AI Connectors</Link>
            </p>
            <h1 class="text-3xl font-semibold">Create global AI connector</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                Start a new connector template that is available to every company. Pick a dashboard to use as a sandbox for testing credentials and API calls while you build.
            </p>
        </div>

        <form
            v-if="dashboards.length > 0"
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="sandbox_dashboard_id" class="mb-1 block text-sm font-medium">Build sandbox dashboard</label>
                <select
                    id="sandbox_dashboard_id"
                    v-model="form.sandbox_dashboard_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    required
                >
                    <option value="" disabled>Select a dashboard</option>
                    <option v-for="dashboard in dashboards" :key="dashboard.id" :value="dashboard.id">
                        {{ dashboard.company_name }} · {{ dashboard.name }}
                    </option>
                </select>
                <p class="mt-1 text-xs text-slate-500">
                    Used only while building and testing. The finished connector stays global — each client dashboard connection keeps its own credentials.
                </p>
                <p v-if="form.errors.sandbox_dashboard_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.sandbox_dashboard_id }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Start building
                </button>
                <Link
                    :href="route('admin.ai-connectors.index')"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Cancel
                </Link>
            </div>
        </form>

        <div v-else class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">
            Create a client dashboard first, then return here to start a global AI connector.
            <div class="mt-4">
                <Link
                    :href="route('admin.dashboards.create')"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90"
                >
                    Create dashboard
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
