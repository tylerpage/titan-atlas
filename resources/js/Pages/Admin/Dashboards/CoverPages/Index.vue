<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';

defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    coverPages: {
        type: Array,
        required: true,
    },
});

function activateCoverPage(coverPageId) {
    router.post(route('admin.cover-pages.activate', coverPageId));
}

function duplicateCoverPage(coverPageId) {
    router.post(route('admin.cover-pages.duplicate', coverPageId));
}

function deleteCoverPage(coverPage) {
    if (!confirm(`Delete "${coverPage.title}"?`)) {
        return;
    }

    router.delete(route('admin.cover-pages.destroy', coverPage.id));
}
</script>

<template>
    <AppLayout :title="`Cover pages · ${dashboard.name}`">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">
                    <Link :href="route('admin.dashboards.show', dashboard.id)" class="hover:text-slate-700">
                        {{ dashboard.company_name }} · {{ dashboard.name }}
                    </Link>
                </p>
                <h1 class="text-3xl font-semibold">Cover pages</h1>
                <p class="mt-2 text-sm text-slate-600">Monthly summaries shown as the first tab on the client dashboard.</p>
            </div>
            <Link
                :href="route('admin.dashboards.cover-pages.create', dashboard.id)"
                class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
            >
                New cover page
            </Link>
        </div>

        <div class="space-y-4">
            <div
                v-for="coverPage in coverPages"
                :key="coverPage.id"
                class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold">{{ coverPage.title }}</h2>
                            <span
                                v-if="coverPage.is_draft"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900"
                            >
                                Draft
                            </span>
                            <span
                                v-if="coverPage.is_active"
                                class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
                            >
                                Active
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            <template v-if="coverPage.period_start && coverPage.period_end">
                                {{ coverPage.period_start }} – {{ coverPage.period_end }}
                            </template>
                            <template v-else>No period set</template>
                            · {{ coverPage.blocks_count }} blocks
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="route('admin.cover-pages.edit', coverPage.id)"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                        >
                            Edit
                        </Link>
                        <button
                            v-if="!coverPage.is_active"
                            type="button"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                            @click="activateCoverPage(coverPage.id)"
                        >
                            Set active
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                            @click="duplicateCoverPage(coverPage.id)"
                        >
                            Duplicate
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50"
                            @click="deleteCoverPage(coverPage)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="coverPages.length === 0" class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
                No cover pages yet. Create one to show a summary tab on the client dashboard.
            </p>
        </div>
    </AppLayout>
</template>
