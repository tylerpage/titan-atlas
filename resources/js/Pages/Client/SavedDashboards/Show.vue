<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import SavedDashboardBlockGrid from '../../../Components/SavedDashboardBlockGrid.vue';
import { useAppBranding } from '../../../Composables/useAppBranding';

const { aiName } = useAppBranding();

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    board: {
        type: Object,
        required: true,
    },
    defaultPreviewStart: {
        type: String,
        required: true,
    },
    defaultPreviewEnd: {
        type: String,
        required: true,
    },
});

const page = usePage();
const status = computed(() => page.props.flash?.status);
const editing = ref(false);

const form = useForm({
    title: props.board.title,
    description: props.board.description ?? '',
    preview_start: props.defaultPreviewStart,
    preview_end: props.defaultPreviewEnd,
});

function saveBoard() {
    form.patch(route('client.dashboard.saved.update', [props.dashboard.slug, props.board.id]), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

function applyDateRange() {
    router.get(route('client.dashboard.saved.show', [props.dashboard.slug, props.board.id]), {
        preview_start: form.preview_start,
        preview_end: form.preview_end,
    }, {
        preserveScroll: true,
    });
}

const deleteForm = useForm({});

function destroyBoard() {
    if (!confirm(`Delete "${props.board.title}"?`)) {
        return;
    }

    deleteForm.delete(route('client.dashboard.saved.destroy', [props.dashboard.slug, props.board.id]));
}
</script>

<template>
    <AppLayout :title="`${board.title} · ${dashboard.name}`">
        <div class="mb-6">
            <p class="text-sm text-slate-500">
                <Link :href="route('client.dashboard.saved.index', dashboard.slug)" class="hover:text-slate-700">
                    Saved dashboards
                </Link>
                · {{ dashboard.company_name }}
            </p>
            <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold">{{ board.title }}</h1>
                    <p v-if="board.description && !editing" class="mt-2 text-slate-600">{{ board.description }}</p>
                </div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                        @click="editing = !editing"
                    >
                        {{ editing ? 'Cancel' : 'Edit' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50"
                        :disabled="deleteForm.processing"
                        @click="destroyBoard"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>

        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ status }}</p>

        <form v-if="editing" class="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="saveBoard">
            <div>
                <label class="mb-1 block text-sm text-slate-600">Title</label>
                <input v-model="form.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-sm text-slate-600">Description</label>
                <textarea v-model="form.description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <button
                type="submit"
                class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                :disabled="form.processing"
            >
                Save changes
            </button>
        </form>

        <form class="mb-6 flex flex-wrap items-end gap-3" @submit.prevent="applyDateRange">
            <div>
                <label class="mb-1 block text-sm text-slate-600">Data from</label>
                <input v-model="form.preview_start" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-sm text-slate-600">Data to</label>
                <input v-model="form.preview_end" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                Refresh data
            </button>
        </form>

        <SavedDashboardBlockGrid :blocks="board.blocks" :color="dashboard.primary_color" />

        <p v-if="board.blocks.length === 0" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
            No visuals pinned yet. Use {{ aiName }} and click "Pin to saved dashboard".
        </p>
    </AppLayout>
</template>
