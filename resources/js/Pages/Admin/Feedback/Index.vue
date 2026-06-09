<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    submissions: {
        type: Array,
        required: true,
    },
    pending_count: {
        type: Number,
        default: 0,
    },
});
</script>

<template>
    <AppLayout title="Feedback">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Feedback</h1>
            <p class="mt-2 text-slate-600">
                User submissions from the in-app feedback widget.
                <span v-if="pending_count" class="font-medium text-amber-700">
                    {{ pending_count }} pending review.
                </span>
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">User</th>
                        <th class="px-4 py-3 font-medium">Reason</th>
                        <th class="px-4 py-3 font-medium">Preview</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="submission in submissions" :key="submission.id" class="border-t border-slate-100">
                        <td class="px-4 py-3 text-slate-600">
                            {{ new Date(submission.created_at).toLocaleString() }}
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ submission.user.name }}</p>
                            <p class="text-slate-500">{{ submission.user.email }}</p>
                        </td>
                        <td class="px-4 py-3">{{ submission.reason_label }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ submission.message_preview }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-1 text-xs font-medium"
                                :class="submission.status === 'pending'
                                    ? 'bg-amber-100 text-amber-800'
                                    : 'bg-slate-100 text-slate-600'"
                            >
                                {{ submission.status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                :href="route('admin.feedback.show', submission.id)"
                                class="text-primary hover:underline"
                            >
                                View
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="submissions.length === 0">
                        <td colspan="6" class="px-4 py-6 text-slate-500">No feedback yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
