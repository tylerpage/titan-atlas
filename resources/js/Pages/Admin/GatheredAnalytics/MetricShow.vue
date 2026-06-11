<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    metric: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AppLayout :title="`Metric #${metric.id}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.gathered-analytics.index', { view: 'metrics', dashboard_id: metric.dashboard?.id })" class="hover:text-slate-700">
                    Gathered analytics
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">Metric snapshot #{{ metric.id }}</h1>
            <p class="mt-2 font-mono text-sm text-slate-600">{{ metric.metric_key }} · {{ metric.snapshot_date }}</p>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Date</p>
                <p class="mt-1 font-medium">{{ metric.snapshot_date }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Value</p>
                <p class="mt-1 font-medium">
                    {{ metric.metric_value }}
                    <span v-if="metric.currency" class="text-sm text-slate-500">{{ metric.currency }}</span>
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 md:col-span-2">
                <p class="text-xs uppercase tracking-wide text-slate-500">Dashboard</p>
                <p class="mt-1 font-medium">{{ metric.dashboard?.name ?? '—' }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ metric.dashboard?.company_name }}</p>
            </div>
        </div>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">Dimensions</h2>
            <pre class="mt-4 overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{{ metric.formatted_dimensions }}</pre>
        </section>
    </AppLayout>
</template>
