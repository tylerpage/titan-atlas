<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
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
const processingInvitationId = ref(null);

function formatExpiry(isoString) {
    return new Date(isoString).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function resendInvitation(companyId, invitationId) {
    processingInvitationId.value = invitationId;

    router.post(
        route('admin.companies.invitations.resend', [companyId, invitationId]),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processingInvitationId.value = null;
            },
        },
    );
}

function revokeInvitation(companyId, invitationId) {
    if (!confirm('Revoke this invitation?')) {
        return;
    }

    processingInvitationId.value = invitationId;

    router.delete(
        route('admin.companies.invitations.destroy', [companyId, invitationId]),
        {
            preserveScroll: true,
            onFinish: () => {
                processingInvitationId.value = null;
            },
        },
    );
}

function impersonate(userId) {
    router.post(route('admin.impersonate.store', userId));
}

const tableRows = computed(() => {
    const invitationRows = props.pendingInvitations.map((invitation) => ({
        kind: 'invitation',
        key: `invitation-${invitation.id}`,
        invitation,
    }));

    const userRows = props.users.map((user) => ({
        kind: 'user',
        key: `user-${user.id}`,
        user,
    }));

    return [...invitationRows, ...userRows];
});
</script>

<template>
    <AppLayout title="Users">
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Users</h1>
                <p class="mt-2 text-slate-600">Manage accounts, roles, company access, and pending email invitations.</p>
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

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Companies</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in tableRows"
                        :key="row.key"
                        class="border-t border-slate-100"
                        :class="row.kind === 'invitation' ? 'bg-slate-50/60' : ''"
                    >
                        <template v-if="row.kind === 'user'">
                            <td class="px-4 py-3 font-medium">{{ row.user.name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ row.user.email }}</td>
                            <td class="px-4 py-3 capitalize">{{ row.user.role }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                <span v-if="row.user.companies.length === 0">—</span>
                                <span v-else>{{ row.user.companies.map((c) => c.name).join(', ') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                                    Active
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-3">
                                    <Link :href="route('admin.users.edit', row.user.id)" class="text-primary hover:underline">
                                        Edit
                                    </Link>
                                    <button
                                        v-if="row.user.role === 'client'"
                                        type="button"
                                        class="text-primary hover:underline"
                                        @click="impersonate(row.user.id)"
                                    >
                                        Impersonate
                                    </button>
                                </div>
                            </td>
                        </template>

                        <template v-else>
                            <td class="px-4 py-3 text-slate-500">—</td>
                            <td class="px-4 py-3 text-slate-600">{{ row.invitation.email }}</td>
                            <td class="px-4 py-3 capitalize">{{ row.invitation.role }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                <Link
                                    :href="route('admin.companies.show', row.invitation.company_id)"
                                    class="text-primary hover:underline"
                                >
                                    {{ row.invitation.company_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="row.invitation.is_expired ? 'bg-amber-100 text-amber-800' : 'bg-slate-200 text-slate-700'"
                                >
                                    {{ row.invitation.is_expired ? 'Invite expired' : 'Invite pending' }}
                                </span>
                                <p class="mt-1 text-xs text-slate-500">
                                    Expires {{ formatExpiry(row.invitation.expires_at) }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="processingInvitationId === row.invitation.id"
                                        @click="resendInvitation(row.invitation.company_id, row.invitation.id)"
                                    >
                                        {{ processingInvitationId === row.invitation.id ? 'Sending…' : 'Resend invite' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-red-700 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="processingInvitationId === row.invitation.id"
                                        @click="revokeInvitation(row.invitation.company_id, row.invitation.id)"
                                    >
                                        Revoke
                                    </button>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="tableRows.length === 0">
                        <td colspan="6" class="px-4 py-6 text-slate-500">
                            No users or pending invitations yet. Invite someone from a company page or add a user directly.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
