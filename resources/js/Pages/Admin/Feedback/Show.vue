<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    submission: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    admin_notes: props.submission.admin_notes ?? '',
    mark_reviewed: false,
    mark_completed: false,
});

function submit(action = 'save') {
    form.mark_reviewed = action === 'reviewed';
    form.mark_completed = action === 'completed';
    form.post(route('admin.feedback.update', props.submission.id));
}
</script>

<template>
    <AppLayout :title="`Feedback #${submission.id}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.feedback.index')" class="hover:text-slate-700">Feedback</Link>
            </p>
            <h1 class="text-3xl font-semibold">Feedback #{{ submission.id }}</h1>
            <p class="mt-2 text-slate-600">{{ submission.reason_label }} · {{ submission.status_label }}</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
            <section class="space-y-6">
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Message</h2>
                    <p class="mt-3 whitespace-pre-wrap text-sm text-slate-700">{{ submission.message }}</p>
                </div>

                <div
                    v-if="submission.attachments.length"
                    class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                >
                    <h2 class="text-lg font-semibold">Attachments</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        <li v-for="attachment in submission.attachments" :key="attachment.id">
                            <a
                                :href="attachment.download_url"
                                class="text-primary hover:underline"
                            >
                                {{ attachment.original_filename }}
                            </a>
                            <span class="text-slate-500">
                                ({{ Math.round(attachment.size_bytes / 1024) }} KB)
                            </span>
                        </li>
                    </ul>
                </div>

                <form
                    class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                    @submit.prevent="submit('save')"
                >
                    <h2 class="text-lg font-semibold">Admin notes</h2>
                    <textarea
                        v-model="form.admin_notes"
                        rows="4"
                        class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Internal notes about this feedback"
                    />
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            type="submit"
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            Save notes
                        </button>
                        <button
                            v-if="submission.status === 'pending'"
                            type="button"
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="form.processing"
                            @click="submit('reviewed')"
                        >
                            Mark reviewed (no email)
                        </button>
                        <button
                            v-if="submission.status !== 'completed'"
                            type="button"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                            :disabled="form.processing"
                            @click="submit('completed')"
                        >
                            Mark completed & notify user
                        </button>
                    </div>
                    <p v-if="submission.status !== 'completed'" class="mt-3 text-xs text-slate-500">
                        <span class="font-medium text-slate-600">Mark reviewed</span> updates status internally only.
                        <span class="font-medium text-slate-600">Mark completed</span> emails the submitter with their
                        feedback message and confirms the work is done.
                    </p>
                </form>
            </section>

            <aside class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm text-sm">
                <div>
                    <p class="font-medium text-slate-900">Submitted by</p>
                    <p class="mt-1">{{ submission.user.name }}</p>
                    <p class="text-slate-500">{{ submission.user.email }}</p>
                    <p class="capitalize text-slate-500">{{ submission.user.role }}</p>
                </div>

                <div>
                    <p class="font-medium text-slate-900">Submitted</p>
                    <p class="mt-1 text-slate-600">{{ new Date(submission.created_at).toLocaleString() }}</p>
                </div>

                <div v-if="submission.dashboard">
                    <p class="font-medium text-slate-900">Dashboard</p>
                    <p class="mt-1">{{ submission.dashboard.company_name }} · {{ submission.dashboard.name }}</p>
                </div>

                <div v-if="submission.page_url">
                    <p class="font-medium text-slate-900">Page</p>
                    <p class="mt-1 break-all text-slate-600">{{ submission.page_url }}</p>
                </div>

                <div v-if="submission.reviewed_at">
                    <p class="font-medium text-slate-900">Reviewed</p>
                    <p class="mt-1 text-slate-600">{{ new Date(submission.reviewed_at).toLocaleString() }}</p>
                    <p v-if="submission.reviewed_by" class="text-slate-500">by {{ submission.reviewed_by.name }}</p>
                </div>

                <div v-if="submission.completed_at">
                    <p class="font-medium text-slate-900">Completed</p>
                    <p class="mt-1 text-slate-600">{{ new Date(submission.completed_at).toLocaleString() }}</p>
                    <p v-if="submission.completed_by" class="text-slate-500">by {{ submission.completed_by.name }}</p>
                    <p class="mt-1 text-emerald-700">User notified by email</p>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
