<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    companies: {
        type: Array,
        required: true,
    },
    dashboards: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <AppLayout title="Admin Dashboards">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Dashboard management</h1>
                <p class="mt-2 text-slate-600">Companies, client dashboards, and data connections.</p>
            </div>
            <Link
                :href="route('admin.dashboards.create')"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover"
            >
                Create dashboard
            </Link>
        </div>

        <section class="mb-10">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                <h2 class="text-xl font-semibold">Companies</h2>
                <Link
                    :href="route('admin.companies.index')"
                    class="text-sm text-primary hover:underline"
                >
                    Manage companies
                </Link>
            </div>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Dashboards</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="company in companies" :key="company.id" class="border-t border-slate-100">
                            <td class="px-4 py-3">
                                <Link :href="route('admin.companies.show', company.id)" class="hover:underline">
                                    {{ company.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">{{ company.client_dashboards_count }}</td>
                        </tr>
                        <tr v-if="companies.length === 0">
                            <td colspan="2" class="px-4 py-6 text-slate-500">No companies yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="mb-4 text-xl font-semibold">Client dashboards</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <Link
                    v-for="dashboard in dashboards"
                    :key="dashboard.id"
                    :href="route('client.dashboard.show', dashboard.slug)"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300"
                >
                    <h3 class="font-semibold">{{ dashboard.name }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ dashboard.company.name }}</p>
                </Link>
                <p v-if="dashboards.length === 0" class="text-slate-500">
                    No dashboards yet. Run the seeder.
                </p>
            </div>
        </section>
    </AppLayout>
</template>
