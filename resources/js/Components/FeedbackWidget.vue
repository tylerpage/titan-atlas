<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const open = ref(false);
const reason = ref('');
const message = ref('');
const files = ref([]);
const submitting = ref(false);
const status = ref(null);

const reasons = computed(() => page.props.feedback?.reasons ?? []);
const dashboardId = computed(() => page.props.dashboard?.id ?? null);

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function toggle() {
    open.value = !open.value;

    if (!open.value) {
        status.value = null;
    }
}

function onFileChange(event) {
    files.value = Array.from(event.target.files ?? []);
}

function resetForm() {
    reason.value = '';
    message.value = '';
    files.value = [];
    status.value = null;
}

async function submit() {
    submitting.value = true;
    status.value = null;

    const formData = new FormData();
    formData.append('reason', reason.value);
    formData.append('message', message.value);
    formData.append('page_url', window.location.pathname + window.location.search);

    if (dashboardId.value) {
        formData.append('client_dashboard_id', String(dashboardId.value));
    }

    for (const file of files.value) {
        formData.append('attachments[]', file);
    }

    try {
        const response = await fetch(route('feedback.store'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: formData,
        });

        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        if (!response.ok) {
            const firstError = data.errors
                ? Object.values(data.errors).flat()[0]
                : data.message;

            status.value = {
                type: 'error',
                message: firstError
                    ?? (response.status === 419
                        ? 'Session expired. Refresh the page and try again.'
                        : 'Could not send feedback.'),
            };

            return;
        }

        status.value = {
            type: 'success',
            message: data.message ?? 'Thanks — your feedback was sent.',
        };

        resetForm();
        open.value = false;
    } catch {
        status.value = {
            type: 'error',
            message: 'Could not reach the server. Check your connection and try again.',
        };
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3">
        <div
            v-if="open"
            class="w-[min(100vw-2.5rem,22rem)] rounded-xl border border-slate-200 bg-white p-4 shadow-xl"
        >
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Send feedback</h2>
                    <p class="mt-1 text-xs text-slate-500">Admins review submissions. Include screenshots if helpful.</p>
                </div>
                <button
                    type="button"
                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    aria-label="Close feedback"
                    @click="toggle"
                >
                    ✕
                </button>
            </div>

            <form class="space-y-3" @submit.prevent="submit">
                <div>
                    <label for="feedback-reason" class="mb-1 block text-sm font-medium">Reason</label>
                    <select
                        id="feedback-reason"
                        v-model="reason"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="" disabled>Select a reason</option>
                        <option v-for="option in reasons" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label for="feedback-message" class="mb-1 block text-sm font-medium">Details</label>
                    <textarea
                        id="feedback-message"
                        v-model="message"
                        required
                        rows="4"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="What happened? What would help?"
                    />
                </div>

                <div>
                    <label for="feedback-files" class="mb-1 block text-sm font-medium">Attachments</label>
                    <input
                        id="feedback-files"
                        type="file"
                        multiple
                        class="w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                        @change="onFileChange"
                    />
                    <p v-if="files.length" class="mt-1 text-xs text-slate-500">
                        {{ files.length }} file{{ files.length === 1 ? '' : 's' }} selected
                    </p>
                </div>

                <p
                    v-if="status"
                    class="text-sm"
                    :class="status.type === 'success' ? 'text-green-700' : 'text-red-600'"
                >
                    {{ status.message }}
                </p>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="submitting"
                >
                    {{ submitting ? 'Sending…' : 'Submit feedback' }}
                </button>
            </form>
        </div>

        <button
            type="button"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-white shadow-lg hover:bg-primary-hover"
            :aria-expanded="open"
            aria-label="Open feedback"
            @click="toggle"
        >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                />
            </svg>
        </button>
    </div>
</template>
