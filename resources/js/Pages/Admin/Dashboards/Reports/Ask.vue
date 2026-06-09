<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, watch } from 'vue';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import ReportVisualization from '../../../../Components/ReportVisualization.vue';
import { useAppBranding } from '../../../../Composables/useAppBranding';
import { displayMessageContent } from '../../../../Composables/useTitanAiMessage';

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
    savedReport: {
        type: Object,
        default: null,
    },
    reportPreview: {
        type: Object,
        default: null,
    },
    coverPages: {
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

const placeForm = useForm({
    cover_page_id: props.coverPages[0]?.id ?? '',
    column_span: 1,
});

const messages = computed(() => props.session?.messages ?? []);
const isProcessing = computed(() => props.session?.status === 'processing');

let pollTimer = null;
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

function reloadSessionData() {
    const pageScrollY = window.scrollY;
    pendingPageScrollY = pageScrollY;
    const reloadData = {
        preview_start: form.preview_start,
        preview_end: form.preview_end,
    };

    if (props.session?.id) {
        reloadData.session = props.session.id;
    }

    router.reload({
        only: ['session', 'savedReport', 'reportPreview'],
        preserveScroll: true,
        preserveState: true,
        data: reloadData,
        onBefore: () => {
            restorePageScroll(pageScrollY);
        },
        onFinish: () => {
            restorePageScroll(pageScrollY);
            pendingPageScrollY = null;
        },
    });
}

function pollSession() {
    if (!props.session?.id || !isProcessing.value) {
        return;
    }

    reloadSessionData();
}

function startPolling() {
    stopPolling();

    if (isProcessing.value) {
        pollTimer = setInterval(pollSession, 2000);
    }
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

onMounted(() => {
    startPolling();

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
            reloadSessionData();
        }
    }
});

function submitMessage() {
    if (form.processing || isProcessing.value || !form.message.trim()) {
        return;
    }

    const pageScrollY = window.scrollY;
    pendingPageScrollY = pageScrollY;

    form.post(route('admin.dashboards.reports.sessions.store', props.dashboard.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['session', 'savedReport', 'reportPreview'],
        onBefore: () => {
            restorePageScroll(pageScrollY);
        },
        onSuccess: () => {
            form.message = '';
            nextTick(() => restorePageScroll(pageScrollY));
            startPolling();
        },
        onFinish: () => {
            restorePageScroll(pageScrollY);
            pendingPageScrollY = null;
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

function placeReport() {
    if (!props.savedReport) {
        return;
    }

    placeForm.post(route('admin.dashboards.reports.place', [props.dashboard.id, props.savedReport.id]));
}

const page = usePage();
const status = computed(() => page.props.flash?.status);
</script>

<template>
    <AppLayout :title="`${aiName} · ${dashboard.name}`">
        <div class="mb-6">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.dashboards.reports.index', dashboard.id)" class="hover:text-slate-700">
                    AI Reports
                </Link>
                · {{ dashboard.company_name }} · {{ dashboard.name }}
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-semibold">{{ aiName }}</h1>
            </div>
            <p class="mt-2 text-sm text-slate-600">Metrics, SQL, data quality, and dashboard design. Saves reports you can add to cover pages.</p>
        </div>

        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ status }}</p>
        <p v-if="isProcessing" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600">
            Thinking… this can take up to a minute for complex questions.
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex-1 space-y-4 overflow-y-auto p-5" style="min-height: 400px; max-height: 500px;">
                    <div v-if="messages.length === 0" class="text-sm text-slate-500">
                        Try: "What was total revenue by source for this period?" or "Show me a daily revenue trend."
                    </div>
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="rounded-lg px-4 py-3 text-sm"
                        :class="message.role === 'user' ? 'ml-8 bg-slate-100 text-slate-800' : 'mr-8 bg-primary/5 text-slate-700'"
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
                                embedded
                            />
                        </div>

                        <div
                            v-if="message.metadata?.quality_report?.checks?.length"
                            class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
                        >
                            <p class="font-medium">Data quality findings</p>
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
                            <label class="mb-1 block text-xs text-slate-500">Preview start</label>
                            <input v-model="form.preview_start" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Preview end</label>
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

            <div class="space-y-4">
                <div v-if="savedReport" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold">Saved report</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ savedReport.prompt }}</p>
                    <p class="mt-2 text-xs uppercase tracking-wide text-slate-400">{{ savedReport.visualization_type.replace('_', ' ') }}</p>
                    <pre class="mt-3 max-h-24 overflow-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ savedReport.sql }}</pre>

                    <form v-if="coverPages.length" class="mt-4 space-y-3 border-t border-slate-100 pt-4" @submit.prevent="placeReport">
                        <h3 class="text-sm font-medium">Add to cover page</h3>
                        <select v-model="placeForm.cover_page_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option v-for="page in coverPages" :key="page.id" :value="page.id">
                                {{ page.title }}{{ page.is_active ? ' (active)' : '' }}
                            </option>
                        </select>
                        <select v-model="placeForm.column_span" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option :value="1">Half width</option>
                            <option :value="2">Full width</option>
                        </select>
                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                            :disabled="placeForm.processing"
                        >
                            Add to cover page
                        </button>
                    </form>
                    <p v-else class="mt-4 text-sm text-slate-500">Create a cover page first to place this report.</p>
                </div>

                <div v-if="reportPreview && savedReport" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 text-lg font-semibold">Latest saved report</h2>
                    <ReportVisualization
                        :visualization-type="savedReport.visualization_type"
                        :payload="reportPreview"
                        :prompt="savedReport.prompt"
                    />
                </div>

                <div v-else-if="!savedReport" class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">
                    Preview will appear here after the agent saves a report.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
