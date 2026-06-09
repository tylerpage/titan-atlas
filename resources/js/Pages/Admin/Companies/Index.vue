<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    companies: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <AppLayout title="Companies">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Companies</h1>
                <p class="mt-2 text-slate-600">Manage client organizations and their dashboards.</p>
            </div>
            <Link
                :href="route('admin.companies.create')"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover"
            >
                Add company
            </Link>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Slug</th>
                        <th class="px-4 py-3 font-medium">Dashboards</th>
                        <th class="px-4 py-3 font-medium">Users</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="company in companies" :key="company.id" class="border-t border-slate-100">
                        <td class="px-4 py-3 font-medium">{{ company.name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ company.slug }}</td>
                        <td class="px-4 py-3">{{ company.client_dashboards_count }}</td>
                        <td class="px-4 py-3">{{ company.users_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link
                                :href="route('admin.companies.show', company.id)"
                                class="text-primary hover:underline"
                            >
                                View
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="companies.length === 0">
                        <td colspan="5" class="px-4 py-6 text-slate-500">No companies yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
