<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useAppBranding } from '../../../Composables/useAppBranding';

const { aiName } = useAppBranding();

defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    sessions: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout :title="`Chat history · ${dashboard.name}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('client.dashboard.show', dashboard.slug)" class="hover:text-slate-700">
                    {{ dashboard.company_name }} · {{ dashboard.name }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">Chat history</h1>
            <p class="mt-2 text-sm text-slate-600">Resume a previous {{ aiName }} conversation.</p>
        </div>

        <div class="mb-4">
            <Link
                :href="route('client.dashboard.ai.chat', dashboard.slug)"
                class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
            >
                New chat
            </Link>
        </div>

        <div class="space-y-3">
            <Link
                v-for="session in sessions"
                :key="session.id"
                :href="route('client.dashboard.ai.chat', [dashboard.slug, session.id])"
                class="block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300"
            >
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-slate-900">{{ session.title }}</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Updated {{ session.updated_at ? new Date(session.updated_at).toLocaleString() : '—' }}
                        </p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">
                        {{ session.status }}
                    </span>
                </div>
            </Link>

            <p v-if="sessions.length === 0" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                No saved chats yet. Start a new conversation to ask questions about your data.
            </p>
        </div>
    </AppLayout>
</template>
