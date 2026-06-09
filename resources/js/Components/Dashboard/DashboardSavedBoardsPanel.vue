<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import SavedDashboardBlockGrid from '../SavedDashboardBlockGrid.vue';
import { useAppBranding } from '../../Composables/useAppBranding';

const { aiName } = useAppBranding();

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    boards: {
        type: Array,
        default: () => [],
    },
    board: {
        type: Object,
        default: null,
    },
    previewStart: {
        type: String,
        required: true,
    },
    previewEnd: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['navigate']);

const page = usePage();
const status = computed(() => page.props.flash?.status);
const showCreate = ref(false);
const editing = ref(false);

const createForm = useForm({
    title: '',
    description: '',
});

const editForm = useForm({
    title: props.board?.title ?? '',
    description: props.board?.description ?? '',
    preview_start: props.previewStart,
    preview_end: props.previewEnd,
});

const overviewRange = ref({
    preview_start: props.previewStart,
    preview_end: props.previewEnd,
});

const deleteForm = useForm({});

const allBlocks = computed(() =>
    props.boards.flatMap((item) =>
        (item.blocks ?? []).map((block) => ({
            ...block,
            board_id: item.id,
            board_title: item.title,
        })),
    ),
);

watch(
    () => [props.board, props.previewStart, props.previewEnd],
    ([board, previewStart, previewEnd]) => {
        editForm.title = board?.title ?? '';
        editForm.description = board?.description ?? '';
        editForm.preview_start = previewStart;
        editForm.preview_end = previewEnd;
        overviewRange.value.preview_start = previewStart;
        overviewRange.value.preview_end = previewEnd;
        editing.value = false;
    },
);

function navigate(overrides = {}) {
    emit('navigate', {
        tab: 'saved',
        preview_start: props.board ? editForm.preview_start : overviewRange.value.preview_start,
        preview_end: props.board ? editForm.preview_end : overviewRange.value.preview_end,
        ...overrides,
    });
}

function createBoard() {
    createForm.post(route('client.dashboard.saved.store', props.dashboard.slug), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

function saveBoard() {
    if (!props.board) {
        return;
    }

    editForm.patch(route('client.dashboard.saved.update', [props.dashboard.slug, props.board.id]), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

function applyDateRange() {
    navigate({
        board: props.board?.id ?? null,
        preview_start: props.board ? editForm.preview_start : overviewRange.value.preview_start,
        preview_end: props.board ? editForm.preview_end : overviewRange.value.preview_end,
    });
}

function destroyBoard() {
    if (!props.board || !confirm(`Delete "${props.board.title}"?`)) {
        return;
    }

    deleteForm.delete(route('client.dashboard.saved.destroy', [props.dashboard.slug, props.board.id]));
}

function openBoard(block) {
    if (block.board_id) {
        navigate({ board: block.board_id });
    }
}
</script>

<template>
    <div>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <button
                    v-if="board"
                    type="button"
                    class="mb-2 text-sm text-slate-500 hover:text-slate-700"
                    @click="navigate({ board: null })"
                >
                    ← All saved dashboards
                </button>
                <h2 class="text-xl font-semibold">
                    {{ board ? board.title : 'Saved dashboards' }}
                </h2>
                <p v-if="board?.description && !editing" class="mt-1 text-sm text-slate-600">
                    {{ board.description }}
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="applyDateRange">
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Data from</label>
                        <input
                            v-if="board"
                            v-model="editForm.preview_start"
                            type="date"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                        <input
                            v-else
                            v-model="overviewRange.preview_start"
                            type="date"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Data to</label>
                        <input
                            v-if="board"
                            v-model="editForm.preview_end"
                            type="date"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                        <input
                            v-else
                            v-model="overviewRange.preview_end"
                            type="date"
                            class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                        Refresh
                    </button>
                </form>

                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="emit('navigate', { tab: 'ai', ai_view: 'chat' })"
                >
                    {{ aiName }}
                </button>

                <template v-if="board">
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
                </template>
                <button
                    v-else
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="showCreate = true"
                >
                    New board
                </button>
            </div>
        </div>

        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ status }}</p>

        <form
            v-if="board && editing"
            class="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
            @submit.prevent="saveBoard"
        >
            <div>
                <label class="mb-1 block text-sm text-slate-600">Title</label>
                <input v-model="editForm.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-sm text-slate-600">Description</label>
                <textarea v-model="editForm.description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <button
                type="submit"
                class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                :disabled="editForm.processing"
            >
                Save changes
            </button>
        </form>

        <template v-if="board">
            <SavedDashboardBlockGrid :blocks="board.blocks" :color="dashboard.primary_color" />

            <p v-if="board.blocks.length === 0" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                No visuals pinned yet. Use {{ aiName }} and click "Pin to saved dashboard".
            </p>
        </template>

        <template v-else>
            <SavedDashboardBlockGrid
                v-if="allBlocks.length"
                :blocks="allBlocks"
                :color="dashboard.primary_color"
                show-view-action
                @view="openBoard"
            />

            <p v-else class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                No saved dashboards yet. Pin visuals from {{ aiName }} to build your first board.
            </p>
        </template>

        <div
            v-if="showCreate"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            @click.self="showCreate = false"
        >
            <form class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" @submit.prevent="createBoard">
                <h2 class="text-lg font-semibold">New saved dashboard</h2>
                <div>
                    <label class="mb-1 block text-sm text-slate-600">Title</label>
                    <input v-model="createForm.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-sm text-slate-600">Description</label>
                    <textarea v-model="createForm.description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showCreate = false">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                        :disabled="createForm.processing"
                    >
                        Create
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
