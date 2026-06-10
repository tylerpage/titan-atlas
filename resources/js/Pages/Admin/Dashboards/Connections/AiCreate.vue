<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import CredentialFieldLabel from '../../../../Components/CredentialFieldLabel.vue';
import { displayMessageContent } from '../../../../Composables/useTitanAiMessage';
import { copyAiConnectorDetails } from '../../../../Composables/useAiConnectorClipboard';
import { useTitanAiSessionWatch } from '../../../../Composables/useTitanAiSessionWatch';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    session: {
        type: Object,
        default: null,
    },
    isResuming: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();

const form = useForm({
    message: '',
    session_id: props.session?.id ?? null,
    credentials: {},
});

const messages = computed(() => props.session?.messages ?? []);
const blueprint = computed(() => props.session?.blueprint ?? null);
const isProcessing = computed(() => props.session?.status === 'processing');
const isFailed = computed(() => props.session?.status === 'failed');

const credentialFields = computed(() => blueprint.value?.credential_schema ?? []);
const showCredentialForm = computed(() => credentialFields.value.length > 0);

const statusBadgeClass = computed(() => {
    const status = blueprint.value?.status ?? 'draft';

    return {
        draft: 'bg-slate-100 text-slate-700',
        ready: 'bg-blue-100 text-blue-800',
        active: 'bg-emerald-100 text-emerald-800',
        needs_dev: 'bg-amber-100 text-amber-900',
        failed: 'bg-red-100 text-red-800',
    }[status] ?? 'bg-slate-100 text-slate-700';
});

let pendingPageScrollY = null;
let removeFinishListener = null;
const messagesContainer = ref(null);
const copiedDetails = ref(false);

function restorePageScroll(scrollY) {
    if (scrollY == null) {
        return;
    }

    const restore = () => window.scrollTo({ top: scrollY, left: 0, behavior: 'instant' });
    restore();
    requestAnimationFrame(() => requestAnimationFrame(restore));
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

function reloadSessionData(scrollToBottom = true) {
    const pageScrollY = window.scrollY;
    pendingPageScrollY = pageScrollY;
    const reloadData = {};

    if (props.session?.id) {
        reloadData.session = props.session.id;
    }

    router.reload({
        only: ['session'],
        preserveScroll: true,
        preserveState: true,
        data: reloadData,
        onFinish: () => {
            restorePageScroll(pageScrollY);
            pendingPageScrollY = null;

            if (scrollToBottom) {
                scrollChatToBottom('instant');
            }
        },
    });
}

const { startWatching, stopWatching } = useTitanAiSessionWatch({
    isProcessing: () => isProcessing.value,
    getChannelName: () => (props.session?.id ? `ai.connector-builder-session.${props.session.id}` : null),
    getStatusUrl: () => {
        if (!props.session?.id) {
            return null;
        }

        return route('admin.dashboards.connector-builder.sessions.status', [props.dashboard.id, props.session.id]);
    },
    onComplete: () => reloadSessionData(),
});

onMounted(() => {
    startWatching();
    scrollChatToBottom('instant');

    removeFinishListener = router.on('finish', () => {
        if (pendingPageScrollY !== null) {
            restorePageScroll(pendingPageScrollY);
            pendingPageScrollY = null;
        }
    });
});

onBeforeUnmount(() => {
    stopWatching();

    if (removeFinishListener) {
        removeFinishListener();
        removeFinishListener = null;
    }
});

watch(isProcessing, (processing, wasProcessing) => {
    if (processing) {
        startWatching();
        scrollChatToBottom('instant');
    } else {
        stopWatching();

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

    form.post(route('admin.dashboards.connector-builder.sessions.store', props.dashboard.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['session'],
        onSuccess: () => {
            form.message = '';
            form.credentials = {};
            nextTick(() => {
                restorePageScroll(pageScrollY);
                scrollChatToBottom('instant');
            });
            startWatching();
        },
    });
}

function submitCredentials() {
    if (form.processing || isProcessing.value) {
        return;
    }

    const credentialMessage = 'Here are the credentials: ' + credentialFields.value
        .map((field) => `${field.label} provided`)
        .join(', ');

    form.message = credentialMessage;
    submitMessage();
}

function handleMessageKeydown(event) {
    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    event.preventDefault();
    submitMessage();
}

function exportDevTasks(format) {
    if (!blueprint.value?.dev_tasks?.length) {
        return;
    }

    const content = format === 'json'
        ? JSON.stringify(blueprint.value.dev_tasks, null, 2)
        : blueprint.value.dev_tasks.map((task) => `- [${task.priority}] ${task.task}\n  Reason: ${task.reason}\n  Blocked on: ${task.blocked_on || 'n/a'}`).join('\n\n');

    navigator.clipboard.writeText(content);
}

async function copyConnectorDetails() {
    const copied = await copyAiConnectorDetails(blueprint.value);

    if (!copied) {
        return;
    }

    copiedDetails.value = true;
    setTimeout(() => {
        copiedDetails.value = false;
    }, 2000);
}

const status = computed(() => page.props.flash?.status);
const error = computed(() => page.props.flash?.error);
</script>

<template>
    <AppLayout :title="`AI Connector · ${dashboard.name}`">
        <div class="mb-6">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.dashboards.show', dashboard.id)" class="hover:text-slate-700">
                    {{ dashboard.company_name }} · {{ dashboard.name }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">{{ isResuming ? 'Continue AI Connector' : 'New AI Connector' }}</h1>
            <p class="mt-2 text-sm text-slate-600">
                <span v-if="isResuming">
                    Keep iterating on this connector. Describe what you want to change and the agent will update the existing blueprint.
                </span>
                <span v-else>
                    Describe the integration you want. The agent will research the API, configure read-only sync streams, and set up dashboard analytics.
                </span>
            </p>
            <p class="mt-2 text-sm text-amber-800">
                Connectors are strictly read-only. They can fetch data from external APIs but cannot create, update, or delete records — even if you request it in your prompt. POST is only used for authentication or read-style API endpoints.
            </p>
        </div>

        <p v-if="status" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{{ status }}</p>
        <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>
        <p v-if="isProcessing" class="mb-4 rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600">
            Building your connector… this can take a couple of minutes. Keep this tab open while the queue worker processes your request.
        </p>
        <p v-if="isFailed" class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">
            The connector builder failed on the last request. Send another message to retry, and make sure the queue worker is running (`composer dev:share`).
        </p>

        <div class="grid gap-6 lg:grid-cols-5">
            <div class="flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-3">
                <div ref="messagesContainer" class="flex-1 space-y-4 overflow-y-auto p-5" style="min-height: 400px; max-height: 560px;">
                    <div v-if="messages.length === 0" class="text-sm text-slate-500">
                        <span v-if="isResuming">
                            Tell the agent what to change. For example: "Add an orders stream" or "Switch auth to OAuth2 client credentials."
                        </span>
                        <span v-else>
                            Try: "Connect HubSpot and show deal pipeline, new contacts, and email performance."
                        </span>
                    </div>
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="rounded-lg px-4 py-3 text-sm"
                        :class="message.role === 'user' ? 'ml-8 bg-slate-100 text-slate-800' : 'mr-8 bg-primary/5 text-slate-700'"
                    >
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">
                            {{ message.role === 'user' ? 'You' : 'Connector Builder' }}
                        </p>
                        <p v-if="displayMessageContent(message)" class="whitespace-pre-wrap">{{ displayMessageContent(message) }}</p>
                    </div>
                </div>

                <form class="border-t border-slate-100 p-4" @submit.prevent="submitMessage">
                    <div class="flex items-end gap-2">
                        <textarea
                            v-model="form.message"
                            rows="2"
                            :placeholder="isResuming ? 'Describe what you want to change...' : 'Describe the connection you want to build...'"
                            class="flex-1 resize-y rounded-lg border border-slate-300 px-4 py-2 text-sm"
                            :disabled="form.processing"
                            @keydown="handleMessageKeydown"
                        />
                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                            :disabled="form.processing || isProcessing || !form.message.trim()"
                        >
                            {{ form.processing || isProcessing ? 'Working...' : 'Send' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="space-y-4 lg:col-span-2">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold">Blueprint</h2>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="blueprint"
                                type="button"
                                class="text-xs text-slate-600 underline hover:text-slate-900"
                                @click="copyConnectorDetails"
                            >
                                {{ copiedDetails ? 'Copied!' : 'Copy for AI' }}
                            </button>
                            <span v-if="blueprint" class="rounded-full px-2 py-0.5 text-xs capitalize" :class="statusBadgeClass">
                                {{ blueprint.status.replace('_', ' ') }}
                            </span>
                        </div>
                    </div>

                    <div v-if="!blueprint" class="text-sm text-slate-500">
                        Blueprint details will appear here as the agent configures your connector.
                    </div>

                    <div v-else class="space-y-4 text-sm">
                        <div>
                            <p class="font-medium">{{ blueprint.label }}</p>
                            <p class="text-slate-500">{{ blueprint.slug }}</p>
                        </div>

                        <div v-if="blueprint.sync_config?.base_url">
                            <p class="font-medium text-slate-700">Base URL</p>
                            <p class="text-slate-600">{{ blueprint.sync_config.base_url }}</p>
                        </div>

                        <div v-if="blueprint.streams?.length">
                            <p class="font-medium text-slate-700">Streams</p>
                            <ul class="mt-1 space-y-1 text-slate-600">
                                <li v-for="stream in blueprint.streams" :key="stream.stream_key">
                                    {{ stream.stream_key }} → {{ stream.resource_type }}
                                </li>
                            </ul>
                        </div>

                        <div v-if="blueprint.connection || blueprint.connections?.length">
                            <p class="font-medium text-slate-700">Connection</p>
                            <Link
                                v-if="blueprint.connection"
                                :href="route('admin.connections.show', blueprint.connection.id)"
                                class="text-primary hover:underline"
                            >
                                {{ blueprint.connection.name }}
                            </Link>
                            <ul v-else class="mt-1 space-y-1">
                                <li v-for="connection in blueprint.connections" :key="connection.id">
                                    <Link
                                        :href="route('admin.connections.show', connection.id)"
                                        class="text-primary hover:underline"
                                    >
                                        {{ connection.name }}
                                    </Link>
                                </li>
                            </ul>
                            <p v-if="blueprint.connection" class="text-slate-500">Sync: {{ blueprint.connection.sync_status }}</p>
                        </div>

                        <Link
                            :href="route('admin.connector-blueprints.show', blueprint.id)"
                            class="inline-block text-sm text-primary hover:underline"
                        >
                            View full blueprint
                        </Link>
                    </div>
                </div>

                <div v-if="showCredentialForm" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 text-lg font-semibold">Credentials</h2>
                    <form class="space-y-4" @submit.prevent="submitCredentials">
                        <div v-for="field in credentialFields" :key="field.key">
                            <CredentialFieldLabel :field="field" />
                            <input
                                v-model="form.credentials[field.key]"
                                :type="field.type === 'password' ? 'password' : 'text'"
                                :placeholder="field.placeholder"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            />
                        </div>
                        <button
                            type="submit"
                            class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover disabled:opacity-50"
                            :disabled="form.processing || isProcessing"
                        >
                            Submit credentials
                        </button>
                    </form>
                </div>

                <div v-if="blueprint?.dev_tasks?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-amber-900">Developer handoff</h2>
                        <div class="flex gap-2">
                            <button type="button" class="text-xs text-amber-800 underline" @click="exportDevTasks('markdown')">
                                Copy markdown
                            </button>
                            <button type="button" class="text-xs text-amber-800 underline" @click="exportDevTasks('json')">
                                Copy JSON
                            </button>
                        </div>
                    </div>
                    <ul class="space-y-3 text-sm text-amber-900">
                        <li v-for="(task, index) in blueprint.dev_tasks" :key="index" class="rounded-lg bg-white/60 p-3">
                            <p class="font-medium">{{ task.task }}</p>
                            <p class="mt-1 text-amber-800">{{ task.reason }}</p>
                            <p v-if="task.acceptance_criteria" class="mt-1 text-xs text-amber-700">
                                Done when: {{ task.acceptance_criteria }}
                            </p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
