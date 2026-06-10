<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    companies: {
        type: Array,
        default: () => [],
    },
    roles: {
        type: Object,
        default: () => ({}),
    },
    dashboards: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const deleteError = page.props.errors?.user;
const status = page.props.flash?.status;
const processingInvitationId = ref(null);

const inviteForm = useForm({
    company_id: props.companies[0]?.id ?? '',
    email: '',
    role: 'client',
    dashboard_ids: [],
});

const inviteDashboards = computed(() => {
    if (!inviteForm.company_id) {
        return [];
    }

    return props.dashboards.filter((dashboard) => dashboard.company_id === Number(inviteForm.company_id));
});

function formatExpiry(isoString) {
    return new Date(isoString).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function toggleInviteDashboard(dashboardId) {
    if (inviteForm.dashboard_ids.includes(dashboardId)) {
        inviteForm.dashboard_ids = inviteForm.dashboard_ids.filter((id) => id !== dashboardId);
    } else {
        inviteForm.dashboard_ids = [...inviteForm.dashboard_ids, dashboardId];
    }
}

function sendInvitation() {
    inviteForm.post(route('admin.companies.invitations.store', inviteForm.company_id), {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset('email', 'dashboard_ids');
            inviteForm.role = 'client';
        },
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
</script>

<template>
    <AppLayout title="Users">
        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Users</h1>
                <p class="mt-2 text-slate-600">Manage accounts, roles, company access, and email invitations.</p>
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

        <section class="mb-10">
            <h2 class="mb-4 text-xl font-semibold">Email invitations</h2>

            <form
                class="mb-6 space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                @submit.prevent="sendInvitation"
            >
                <p class="text-sm text-slate-600">
                    Send an email invitation for someone who does not have an account yet. Existing users are added immediately without email.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="invite_company" class="mb-1 block text-sm font-medium">Company</label>
                        <select
                            id="invite_company"
                            v-model="inviteForm.company_id"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2"
                            @change="inviteForm.dashboard_ids = []"
                        >
                            <option v-for="company in companies" :key="company.id" :value="company.id">
                                {{ company.name }}
                            </option>
                        </select>
                        <p v-if="inviteForm.errors.company_id" class="mt-1 text-sm text-red-600">{{ inviteForm.errors.company_id }}</p>
                    </div>
                    <div>
                        <label for="invite_email" class="mb-1 block text-sm font-medium">Email</label>
                        <input
                            id="invite_email"
                            v-model="inviteForm.email"
                            type="email"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2"
                            placeholder="client@example.com"
                        />
                        <p v-if="inviteForm.errors.email" class="mt-1 text-sm text-red-600">{{ inviteForm.errors.email }}</p>
                    </div>
                    <div>
                        <label for="invite_role" class="mb-1 block text-sm font-medium">Role</label>
                        <select id="invite_role" v-model="inviteForm.role" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="(label, value) in roles" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <p v-if="inviteForm.errors.role" class="mt-1 text-sm text-red-600">{{ inviteForm.errors.role }}</p>
                    </div>
                </div>

                <div v-if="inviteDashboards.length > 0">
                    <p class="mb-2 text-sm font-medium">Dashboard access</p>
                    <div class="space-y-2 rounded-lg border border-slate-200 p-4">
                        <label
                            v-for="dashboard in inviteDashboards"
                            :key="dashboard.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                :checked="inviteForm.dashboard_ids.includes(dashboard.id)"
                                @change="toggleInviteDashboard(dashboard.id)"
                            />
                            {{ dashboard.name }}
                        </label>
                    </div>
                </div>

                <button
                    type="submit"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="inviteForm.processing || companies.length === 0"
                >
                    {{ inviteForm.processing ? 'Sending…' : 'Send invitation' }}
                </button>
            </form>

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
                        <tr
                            v-for="invitation in pendingInvitations"
                            :key="invitation.id"
                            class="border-t border-slate-100"
                        >
                            <td class="px-4 py-3 text-slate-600">{{ invitation.email }}</td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="route('admin.companies.show', invitation.company_id)"
                                    class="text-primary hover:underline"
                                >
                                    {{ invitation.company_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 capitalize">{{ invitation.role }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatExpiry(invitation.expires_at) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="invitation.is_expired ? 'bg-amber-100 text-amber-800' : 'bg-slate-200 text-slate-700'"
                                >
                                    {{ invitation.is_expired ? 'Expired' : 'Pending' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="processingInvitationId === invitation.id"
                                        @click="resendInvitation(invitation.company_id, invitation.id)"
                                    >
                                        {{ processingInvitationId === invitation.id ? 'Sending…' : 'Resend invite' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-red-700 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="processingInvitationId === invitation.id"
                                        @click="revokeInvitation(invitation.company_id, invitation.id)"
                                    >
                                        Revoke
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="pendingInvitations.length === 0">
                            <td colspan="6" class="px-4 py-6 text-slate-500">
                                No pending invitations. Send one above and it will appear here with resend and revoke actions.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="mb-4 text-xl font-semibold">Active users</h2>
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
                            <td colspan="5" class="px-4 py-6 text-slate-500">No active users yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
