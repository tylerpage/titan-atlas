<script setup>
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    roles: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const deleteError = computed(() => page.props.errors?.company);

const deleteForm = useForm({});

const inviteForm = useForm({
    email: '',
    role: 'client',
    dashboard_ids: [],
});

function toggleInviteDashboard(dashboardId) {
    if (inviteForm.dashboard_ids.includes(dashboardId)) {
        inviteForm.dashboard_ids = inviteForm.dashboard_ids.filter((id) => id !== dashboardId);
    } else {
        inviteForm.dashboard_ids = [...inviteForm.dashboard_ids, dashboardId];
    }
}

function sendInvitation() {
    inviteForm.post(route('admin.companies.invitations.store', props.company.id), {
        onSuccess: () => {
            inviteForm.reset('email', 'dashboard_ids');
            inviteForm.role = 'client';
        },
    });
}

function resendInvitation(invitationId) {
    router.post(route('admin.companies.invitations.resend', [props.company.id, invitationId]));
}

function revokeInvitation(invitationId) {
    if (!confirm('Revoke this invitation?')) {
        return;
    }

    router.delete(route('admin.companies.invitations.destroy', [props.company.id, invitationId]));
}

function destroyCompany() {
    if (!confirm(`Delete ${props.company.name}? This cannot be undone.`)) {
        return;
    }

    deleteForm.delete(route('admin.companies.destroy', props.company.id));
}

function formatExpiry(isoString) {
    return new Date(isoString).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
</script>

<template>
    <AppLayout :title="company.name">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">{{ company.name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ company.slug }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    :href="route('admin.companies.edit', company.id)"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Edit
                </Link>
                <Link
                    :href="route('admin.dashboards.create')"
                    class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover"
                >
                    Create dashboard
                </Link>
            </div>
        </div>

        <p v-if="deleteError" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ deleteError }}
        </p>

        <section class="mb-10">
            <h2 class="mb-4 text-xl font-semibold">Dashboards</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <Link
                    v-for="dashboard in company.dashboards"
                    :key="dashboard.id"
                    :href="route('admin.dashboards.show', dashboard.id)"
                    class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300"
                >
                    <h3 class="font-semibold">{{ dashboard.name }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ dashboard.slug }}</p>
                </Link>
                <p v-if="company.dashboards.length === 0" class="text-slate-500">No dashboards yet.</p>
            </div>
        </section>

        <section class="mb-10">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                <h2 class="text-xl font-semibold">Users</h2>
                <Link :href="route('admin.users.index')" class="text-sm text-primary hover:underline">All users</Link>
            </div>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in company.users" :key="user.id" class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ user.name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ user.email }}</td>
                            <td class="px-4 py-3 capitalize">{{ user.role }}</td>
                            <td class="px-4 py-3">
                                <Link :href="route('admin.users.edit', user.id)" class="text-primary hover:underline">
                                    Edit
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="company.users.length === 0">
                            <td colspan="4" class="px-4 py-6 text-slate-500">No users assigned to this company.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mb-10">
            <h2 class="mb-4 text-xl font-semibold">Invite user</h2>
            <form
                class="space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                @submit.prevent="sendInvitation"
            >
                <p class="text-sm text-slate-600">
                    Send an email invitation to join this company. Existing users are added immediately without email.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
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

                <div v-if="company.dashboards.length > 0">
                    <p class="mb-2 text-sm font-medium">Dashboard access</p>
                    <div class="space-y-2 rounded-lg border border-slate-200 p-4">
                        <label
                            v-for="dashboard in company.dashboards"
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
                    :disabled="inviteForm.processing"
                >
                    Send invitation
                </button>
            </form>
        </section>

        <section v-if="company.invitations.length > 0" class="mb-10">
            <h2 class="mb-4 text-xl font-semibold">Pending invitations</h2>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3 font-medium">Expires</th>
                            <th class="px-4 py-3 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invitation in company.invitations" :key="invitation.id" class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ invitation.email }}</td>
                            <td class="px-4 py-3 capitalize">{{ invitation.role }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ formatExpiry(invitation.expires_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex gap-3">
                                    <button type="button" class="text-primary hover:underline" @click="resendInvitation(invitation.id)">
                                        Resend
                                    </button>
                                    <button type="button" class="text-red-700 hover:underline" @click="revokeInvitation(invitation.id)">
                                        Revoke
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl border border-red-200 bg-red-50 p-5">
            <h2 class="text-lg font-semibold text-red-900">Danger zone</h2>
            <p class="mt-2 text-sm text-red-800">
                Delete this company only after removing all dashboards. User memberships will be cleared.
            </p>
            <button
                type="button"
                class="mt-4 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-100 disabled:opacity-50"
                :disabled="deleteForm.processing"
                @click="destroyCompany"
            >
                Delete company
            </button>
        </section>
    </AppLayout>
</template>
