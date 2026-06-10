<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import ReportVisualization from '../../../Components/ReportVisualization.vue';
import { useAppBranding } from '../../../Composables/useAppBranding';
import { displayMessageContent } from '../../../Composables/useTitanAiMessage';
import { useTitanAiSessionWatch } from '../../../Composables/useTitanAiSessionWatch';

const { aiName } = useAppBranding();

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    session: {
        type: Object,
        default: null,
    },
    savedDashboards: {
        type: Array,
        default: () => [],
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

const form = useForm({
    message: '',
    session_id: props.session?.id ?? null,
    preview_start: props.defaultPreviewStart,
    preview_end: props.defaultPreviewEnd,
});

const pinForm = useForm({
    saved_dashboard_id: props.savedDashboards[0]?.id ?? '',
    board_title: '',
    board_description: '',
    title: '',
    description: '',
    column_span: 1,
});

const page = usePage();
const status = computed(() => page.props.flash?.status);
const messages = computed(() => props.session?.messages ?? []);
const isProcessing = computed(() => props.session?.status === 'processing');
const showPinModal = ref(false);
const pinReportId = ref(null);

function reloadSessionData() {
    router.reload({
        only: ['session'],
        preserveScroll: true,
        preserveState: true,
        data: {
            preview_start: form.preview_start,
            preview_end: form.preview_end,
        },
    });
}

const { startWatching, stopWatching } = useTitanAiSessionWatch({
    isProcessing: () => isProcessing.value,
    getChannelName: () => (props.session?.id ? `ai.report-session.${props.session.id}` : null),
    getStatusUrl: () => {
        if (!props.session?.id) {
            return null;
        }

        return route('client.dashboard.ai.sessions.status', [props.dashboard.slug, props.session.id]);
    },
    onComplete: () => reloadSessionData(),
});

onMounted(startWatching);
watch(isProcessing, (processing) => {
    if (processing) {
        startWatching();
    } else {
        stopWatching();
    }
});

function submitMessage() {
    form.post(route('client.dashboard.ai.sessions.store', props.dashboard.slug), {
        preserveScroll: true,
        onSuccess: () => {
            form.message = '';
            startWatching();
        },
    });
}

function openPinModal(reportId) {
    pinReportId.value = reportId;
    pinForm.title = '';
    pinForm.description = '';
    pinForm.board_title = '';
    pinForm.board_description = '';
    showPinModal.value = true;
}

function submitPin() {
    if (!pinReportId.value) {
        return;
    }

    pinForm.post(route('client.dashboard.ai.reports.pin', [props.dashboard.slug, pinReportId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            showPinModal.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :title="`${aiName} · ${dashboard.name}`">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">
                    <Link :href="route('client.dashboard.show', dashboard.slug)" class="hover:text-slate-700">
                        {{ dashboard.company_name }} · {{ dashboard.name }}
                    </Link>
                </p>
                <h1 class="text-3xl font-semibold">{{ aiName }}</h1>
                <p class="mt-2 text-sm text-slate-600">
                    Ask business questions about your data. Answers include charts, KPI explanations, and data quality insights.
                </p>
            </div>
            <div class="flex gap-2">
                <Link
                    :href="route('client.dashboard.ai.sessions', dashboard.slug)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Chat history
                </Link>
                <Link
                    :href="route('client.dashboard.saved.index', dashboard.slug)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Saved dashboards
                </Link>
            </div>
        </div>

        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ status }}</p>
        <p v-if="isProcessing" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600">
            Thinking… this can take up to a minute for complex questions.
        </p>

        <div class="mx-auto max-w-4xl">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="space-y-4 overflow-y-auto p-5" style="min-height: 420px; max-height: 620px;">
                    <div v-if="messages.length === 0" class="text-sm text-slate-500">
                        Try: "What was total revenue by source this month?" or "Show sessions by source / medium as a table."
                    </div>
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="rounded-lg px-4 py-3 text-sm"
                        :class="message.role === 'user' ? 'ml-12 bg-slate-100 text-slate-800' : 'mr-4 bg-white text-slate-700'"
                    >
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">
                            {{ message.role === 'user' ? 'You' : aiName }}
                        </p>
                        <p v-if="displayMessageContent(message)" class="whitespace-pre-wrap">{{ displayMessageContent(message) }}</p>
                        <div v-if="message.report_preview" class="mt-3">
                            <ReportVisualization
                                :visualization-type="message.report_preview.visualization_type"
                                :payload="message.report_preview.payload"
                                :prompt="message.report_preview.prompt"
                                :color="dashboard.primary_color"
                                embedded
                            />
                            <button
                                type="button"
                                class="mt-3 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                @click="openPinModal(message.report_preview.report_id)"
                            >
                                Pin to saved dashboard
                            </button>
                            <p class="mt-2 text-xs text-slate-500">
                                Uses date range above. Pinned visuals update when you change dates.
                            </p>
                        </div>

                        <div
                            v-if="message.metadata?.quality_report?.checks?.length"
                            class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                        >
                            <p class="font-medium">Data quality</p>
                            <ul class="mt-1 list-disc pl-4">
                                <li v-for="(check, idx) in message.metadata.quality_report.checks" :key="idx">
                                    {{ check.message }}
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="message.metadata?.documentation?.markdown"
                            class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700"
                        >
                            <p class="mb-1 font-medium">Documentation</p>
                            <pre class="whitespace-pre-wrap font-sans">{{ message.metadata.documentation.markdown }}</pre>
                        </div>

                    </div>
                </div>

                <form class="border-t border-slate-100 p-4" @submit.prevent="submitMessage">
                    <div class="mb-3 grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Data from</label>
                            <input v-model="form.preview_start" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Data to</label>
                            <input v-model="form.preview_end" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <input
                            v-model="form.message"
                            type="text"
                            placeholder="Ask about your analytics..."
                            class="flex-1 rounded-lg border border-slate-300 px-4 py-2 text-sm"
                            :disabled="form.processing"
                        />
                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                            :disabled="form.processing || isProcessing || !form.message.trim()"
                        >
                            {{ form.processing || isProcessing ? 'Thinking...' : 'Send' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div
            v-if="showPinModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            @click.self="showPinModal = false"
        >
            <form class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl" @submit.prevent="submitPin">
                <h2 class="text-lg font-semibold">Pin to saved dashboard</h2>

                <div v-if="savedDashboards.length">
                    <label class="mb-1 block text-sm text-slate-600">Existing board</label>
                    <select v-model="pinForm.saved_dashboard_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Create new board</option>
                        <option v-for="board in savedDashboards" :key="board.id" :value="board.id">
                            {{ board.title }}
                        </option>
                    </select>
                </div>

                <div v-if="!pinForm.saved_dashboard_id">
                    <label class="mb-1 block text-sm text-slate-600">New board title</label>
                    <input v-model="pinForm.board_title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <label class="mb-1 mt-3 block text-sm text-slate-600">Description</label>
                    <textarea v-model="pinForm.board_description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>

                <label class="mb-1 block text-sm text-slate-600">Visual title (optional)</label>
                <input v-model="pinForm.title" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />

                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="showPinModal = false">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                        :disabled="pinForm.processing"
                    >
                        Pin visual
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
