<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    title: '',
    period_start: '',
    period_end: '',
    is_active: true,
});

function submit() {
    form.post(route('admin.dashboards.cover-pages.store', props.dashboard.id));
}
</script>

<template>
    <AppLayout title="New cover page">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.dashboards.cover-pages.index', dashboard.id)" class="hover:text-slate-700">
                    {{ dashboard.company_name }} · {{ dashboard.name }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">New cover page</h1>
        </div>

        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="title" class="mb-1 block text-sm font-medium">Title</label>
                <input id="title" v-model="form.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="June 2025 Summary" />
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="period_start" class="mb-1 block text-sm font-medium">Period start</label>
                    <input id="period_start" v-model="form.period_start" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label for="period_end" class="mb-1 block text-sm font-medium">Period end</label>
                    <input id="period_end" v-model="form.period_end" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                Set as active cover page
            </label>

            <div class="flex justify-end gap-3">
                <Link :href="route('admin.dashboards.cover-pages.index', dashboard.id)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                    Cancel
                </Link>
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover" :disabled="form.processing">
                    Create cover page
                </button>
            </div>
        </form>
    </AppLayout>
</template>
