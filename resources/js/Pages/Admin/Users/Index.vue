<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

defineProps({
    users: {
        type: Array,
        required: true,
    },
    pendingInvitations: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const deleteError = page.props.errors?.user;
const status = page.props.flash?.status;

function formatExpiry(isoString) {
    return new Date(isoString).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function resendInvitation(companyId, invitationId) {
    router.post(route('admin.companies.invitations.resend', [companyId, invitationId]));
}

function impersonate(userId) {
    router.post(route('admin.impersonate.store', userId));
}
</script>

<template>
    <AppLayout title="Users">
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Users</h1>
                <p class="mt-2 text-slate-600">Manage accounts, roles, and company access.</p>
            </div>
            <Link
                :href="route('admin.users.create')"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover"
            >
                Add user
            </Link>
        </div>

        <p v-if="status" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ status }}
        </p>

        <p v-if="deleteError" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ deleteError }}
        </p>

        <section v-if="pendingInvitations.length > 0" class="mb-10">
            <h2 class="mb-4 text-xl font-semibold">Pending invitations</h2>
            <p class="mb-4 text-sm text-slate-600">
                Users who were invited by email but have not accepted yet. Resend to deliver a fresh invitation link.
            </p>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Company</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3 font-medium">Expires</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invitation in pendingInvitations" :key="invitation.id" class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ invitation.email }}</td>
                            <td class="px-4 py-3">
                                <Link :href="route('admin.companies.show', invitation.company_id)" class="text-primary hover:underline">
                                    {{ invitation.company_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 capitalize">{{ invitation.role }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatExpiry(invitation.expires_at) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="invitation.is_expired ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                                >
                                    {{ invitation.is_expired ? 'Expired' : 'Pending' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    class="text-primary hover:underline"
                                    @click="resendInvitation(invitation.company_id, invitation.id)"
                                >
                                    Resend email
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Companies</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id" class="border-t border-slate-100">
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ user.email }}</td>
                        <td class="px-4 py-3 capitalize">{{ user.role }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            <span v-if="user.companies.length === 0">—</span>
                            <span v-else>{{ user.companies.map((c) => c.name).join(', ') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-3">
                                <Link :href="route('admin.users.edit', user.id)" class="text-primary hover:underline">
                                    Edit
                                </Link>
                                <button
                                    v-if="user.role === 'client'"
                                    type="button"
                                    class="text-primary hover:underline"
                                    @click="impersonate(user.id)"
                                >
                                    Impersonate
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="users.length === 0">
                        <td colspan="5" class="px-4 py-6 text-slate-500">No users yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
