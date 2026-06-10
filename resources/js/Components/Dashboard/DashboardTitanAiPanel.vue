<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ReportVisualization from '../ReportVisualization.vue';
import { useAppBranding } from '../../Composables/useAppBranding';
import { displayMessageContent } from '../../Composables/useTitanAiMessage';
import { useTitanAiPolling } from '../../Composables/useTitanAiPolling';

const { aiName } = useAppBranding();

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    aiView: {
        type: String,
        default: 'chat',
    },
    session: {
        type: Object,
        default: null,
    },
    sessions: {
        type: Array,
        default: () => [],
    },
    savedDashboards: {
        type: Array,
        default: () => [],
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

const form = useForm({
    message: '',
    session_id: props.session?.id ?? null,
    preview_start: props.previewStart,
    preview_end: props.previewEnd,
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
const messagesContainer = ref(null);
const showHistory = computed(() => props.aiView === 'history');

let pendingPageScrollY = null;
let removeFinishListener = null;

function restorePageScroll(scrollY) {
    if (scrollY == null) {
        return;
    }

    const restore = () => {
        window.scrollTo({ top: scrollY, left: 0, behavior: 'instant' });
    };

    restore();
    requestAnimationFrame(() => {
        requestAnimationFrame(restore);
    });
    [0, 50, 100, 200].forEach((delay) => {
        setTimeout(restore, delay);
    });
}

function scrollChatToBottom(behavior = 'smooth') {
    nextTick(() => {
        const container = messagesContainer.value;

        if (!container) {
            return;
        }

        container.scrollTo({
            top: container.scrollHeight,
            behavior,
        });
    });
}

watch(
    () => [props.session?.id, props.session?.status, props.previewStart, props.previewEnd],
    ([sessionId, sessionStatus, previewStart, previewEnd]) => {
        form.session_id = sessionId ?? null;
        form.preview_start = previewStart;
        form.preview_end = previewEnd;

        if (sessionId && sessionStatus === 'processing') {
            startPolling();
        }
    },
);

function navigate(overrides = {}) {
    emit('navigate', {
        tab: 'ai',
        preview_start: form.preview_start,
        preview_end: form.preview_end,
        ...overrides,
    });
}

function reloadSessionData(scrollToBottom = true) {
    const pageScrollY = window.scrollY;
    pendingPageScrollY = pageScrollY;
    const reloadData = {
        tab: 'ai',
        ai_view: props.aiView,
        preview_start: form.preview_start,
        preview_end: form.preview_end,
    };

    if (props.session?.id) {
        reloadData.session = props.session.id;
    }

    router.reload({
        only: ['aiSession', 'previewStart', 'previewEnd'],
        preserveScroll: true,
        preserveState: true,
        data: reloadData,
        onBefore: () => {
            restorePageScroll(pageScrollY);
        },
        onFinish: () => {
            restorePageScroll(pageScrollY);
            pendingPageScrollY = null;

            if (scrollToBottom) {
                scrollChatToBottom('instant');
            }
        },
    });
}

const { startPolling, stopPolling } = useTitanAiPolling({
    isProcessing: () => isProcessing.value,
    getStatusUrl: () => {
        if (!props.session?.id) {
            return null;
        }

        return route('client.dashboard.ai.sessions.status', [props.dashboard.slug, props.session.id]);
    },
    onComplete: () => reloadSessionData(),
});

onMounted(() => {
    startPolling();
    scrollChatToBottom('instant');

    removeFinishListener = router.on('finish', () => {
        if (pendingPageScrollY === null) {
            return;
        }

        restorePageScroll(pendingPageScrollY);
        pendingPageScrollY = null;
    });
});
onBeforeUnmount(() => {
    stopPolling();

    if (removeFinishListener) {
        removeFinishListener();
        removeFinishListener = null;
    }
});
watch(isProcessing, (processing, wasProcessing) => {
    if (processing) {
        startPolling();
    } else {
        stopPolling();

        if (wasProcessing) {
            reloadSessionData(false);
            scrollChatToBottom();
        }
    }
});

watch(
    () => messages.value.length,
    () => scrollChatToBottom('instant'),
);

function submitMessage() {
    if (form.processing || isProcessing.value || !form.message.trim()) {
        return;
    }

    const pageScrollY = window.scrollY;
    pendingPageScrollY = pageScrollY;

    form.post(route('client.dashboard.ai.sessions.store', props.dashboard.slug), {
        preserveScroll: true,
        preserveState: true,
        only: ['aiSession', 'previewStart', 'previewEnd'],
        onBefore: () => {
            restorePageScroll(pageScrollY);
        },
        onSuccess: () => {
            form.message = '';
            nextTick(() => {
                restorePageScroll(pageScrollY);
                scrollChatToBottom('instant');
            });
        },
        onFinish: () => {
            restorePageScroll(pageScrollY);
            pendingPageScrollY = null;
            startPolling();
        },
    });
}

function handleMessageKeydown(event) {
    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    event.preventDefault();
    submitMessage();
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
    <div>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold">{{ aiName }}</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Ask business questions about your data. Answers include charts, KPI explanations, and data quality insights.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    v-if="!showHistory"
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="navigate({ ai_view: 'history', session: null })"
                >
                    Chat history
                </button>
                <button
                    v-else
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="navigate({ ai_view: 'chat', session: null })"
                >
                    Back to chat
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="emit('navigate', { tab: 'saved' })"
                >
                    Saved dashboards
                </button>
            </div>
        </div>

        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ status }}</p>

        <template v-if="showHistory">
            <div class="mb-4">
                <button
                    type="button"
                    class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
                    @click="navigate({ ai_view: 'chat', session: null })"
                >
                    New chat
                </button>
            </div>

            <div class="space-y-3">
                <button
                    v-for="item in sessions"
                    :key="item.id"
                    type="button"
                    class="block w-full rounded-xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-slate-300"
                    @click="navigate({ ai_view: 'chat', session: item.id })"
                >
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="font-semibold text-slate-900">{{ item.title }}</h3>
                            <p class="mt-1 text-sm text-slate-500">
                                Updated {{ item.updated_at ? new Date(item.updated_at).toLocaleString() : '—' }}
                            </p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-600">
                            {{ item.status }}
                        </span>
                    </div>
                </button>

                <p v-if="sessions.length === 0" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                    No saved chats yet. Start a new conversation to ask questions about your data.
                </p>
            </div>
        </template>

        <template v-else>
            <p v-if="isProcessing" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600">
                Thinking… this can take up to a minute for complex questions.
            </p>

            <div class="mx-auto max-w-4xl">
                <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div
                        ref="messagesContainer"
                        class="space-y-4 overflow-y-auto p-5"
                        style="min-height: 420px; max-height: 620px;"
                    >
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
                                    Uses date range below. Pinned visuals update when you change dates.
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
                                @keydown="handleMessageKeydown"
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
        </template>

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
    </div>
</template>
