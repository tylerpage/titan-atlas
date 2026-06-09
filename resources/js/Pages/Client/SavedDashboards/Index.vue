<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useAppBranding } from '../../../Composables/useAppBranding';

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
});

const showCreate = ref(false);

const form = useForm({
    title: '',
    description: '',
});

function createBoard() {
    form.post(route('client.dashboard.saved.store', props.dashboard.slug), {
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AppLayout :title="`Saved dashboards · ${dashboard.name}`">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">
                    <Link :href="route('client.dashboard.show', dashboard.slug)" class="hover:text-slate-700">
                        {{ dashboard.company_name }} · {{ dashboard.name }}
                    </Link>
                </p>
                <h1 class="text-3xl font-semibold">Saved dashboards</h1>
                <p class="mt-2 text-sm text-slate-600">
                    Shared report boards pinned from AI chat. Visible to everyone on this dashboard.
                </p>
            </div>
            <div class="flex gap-2">
                <Link
                    :href="route('client.dashboard.ai.chat', dashboard.slug)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    {{ aiName }}
                </Link>
                <button
                    type="button"
                    class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
                    @click="showCreate = true"
                >
                    New board
                </button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <Link
                v-for="board in boards"
                :key="board.id"
                :href="route('client.dashboard.saved.show', [dashboard.slug, board.id])"
                class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300"
            >
                <h2 class="text-lg font-semibold">{{ board.title }}</h2>
                <p v-if="board.description" class="mt-2 text-sm text-slate-600">{{ board.description }}</p>
                <p class="mt-3 text-xs text-slate-500">
                    {{ board.blocks_count }} visual{{ board.blocks_count === 1 ? '' : 's' }}
                </p>
            </Link>
        </div>

        <p v-if="boards.length === 0" class="mt-6 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
            No saved dashboards yet. Pin visuals from {{ aiName }} to build your first board.
        </p>

        <div
            v-if="showCreate"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            @click.self="showCreate = false"
        >
            <form class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" @submit.prevent="createBoard">
                <h2 class="text-lg font-semibold">New saved dashboard</h2>
                <div>
                    <label class="mb-1 block text-sm text-slate-600">Title</label>
                    <input v-model="form.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-sm text-slate-600">Description</label>
                    <textarea v-model="form.description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showCreate = false">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        Create
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
