<script setup>
import { computed } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    roles: {
        type: Object,
        required: true,
    },
    companies: {
        type: Array,
        required: true,
    },
    dashboards: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const deleteError = computed(() => page.props.errors?.user);

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    role: props.user.role,
    company_ids: [...props.user.company_ids],
    dashboard_ids: [...props.user.dashboard_ids],
});

const deleteForm = useForm({});

const visibleDashboards = computed(() => {
    if (form.company_ids.length === 0) {
        return props.dashboards;
    }

    return props.dashboards.filter((dashboard) => form.company_ids.includes(dashboard.company_id));
});

function toggleCompany(companyId) {
    if (form.company_ids.includes(companyId)) {
        form.company_ids = form.company_ids.filter((id) => id !== companyId);
    } else {
        form.company_ids = [...form.company_ids, companyId];
    }

    form.dashboard_ids = form.dashboard_ids.filter((id) =>
        visibleDashboards.value.some((dashboard) => dashboard.id === id),
    );
}

function toggleDashboard(dashboardId) {
    if (form.dashboard_ids.includes(dashboardId)) {
        form.dashboard_ids = form.dashboard_ids.filter((id) => id !== dashboardId);
    } else {
        form.dashboard_ids = [...form.dashboard_ids, dashboardId];
    }
}

function submit() {
    form.post(route('admin.users.update', props.user.id));
}

function destroyUser() {
    if (!confirm(`Delete ${props.user.name}? This cannot be undone.`)) {
        return;
    }

    deleteForm.delete(route('admin.users.destroy', props.user.id));
}

function impersonate() {
    router.post(route('admin.impersonate.store', props.user.id));
}
</script>

<template>
    <AppLayout :title="`Edit ${user.name}`">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-semibold">Edit user</h1>
                <p class="mt-2 text-slate-600">{{ user.email }}</p>
            </div>
            <button
                v-if="user.role === 'client'"
                type="button"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                @click="impersonate"
            >
                Impersonate
            </button>
        </div>

        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="mb-1 block text-sm font-medium">Name</label>
                    <input id="name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                    <input id="email" v-model="form.email" type="email" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-1 block text-sm font-medium">New password</label>
                    <input id="password" v-model="form.password" type="password" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <p class="mt-1 text-sm text-slate-500">Leave blank to keep the current password.</p>
                    <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-medium">Confirm password</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    />
                </div>
            </div>

            <div>
                <label for="role" class="mb-1 block text-sm font-medium">Role</label>
                <select id="role" v-model="form.role" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option v-for="(label, value) in roles" :key="value" :value="value">{{ label }}</option>
                </select>
                <p v-if="form.errors.role" class="mt-1 text-sm text-red-600">{{ form.errors.role }}</p>
            </div>

            <div>
                <p class="mb-2 text-sm font-medium">Companies</p>
                <div class="space-y-2 rounded-lg border border-slate-200 p-4">
                    <label
                        v-for="company in companies"
                        :key="company.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            :checked="form.company_ids.includes(company.id)"
                            @change="toggleCompany(company.id)"
                        />
                        {{ company.name }}
                    </label>
                </div>
            </div>

            <div>
                <p class="mb-2 text-sm font-medium">Dashboard access</p>
                <div class="space-y-2 rounded-lg border border-slate-200 p-4">
                    <label
                        v-for="dashboard in visibleDashboards"
                        :key="dashboard.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            :checked="form.dashboard_ids.includes(dashboard.id)"
                            @change="toggleDashboard(dashboard.id)"
                        />
                        {{ dashboard.name }}
                        <span class="text-slate-500">({{ dashboard.company_name }})</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-between gap-4 pt-2">
                <Link :href="route('admin.users.index')" class="text-sm text-slate-600 hover:text-slate-900">Back to users</Link>
                <button
                    type="submit"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Save changes
                </button>
            </div>
        </form>

        <section class="mx-auto mt-10 max-w-2xl rounded-xl border border-red-200 bg-red-50 p-5">
            <h2 class="text-lg font-semibold text-red-900">Danger zone</h2>
            <p v-if="deleteError" class="mt-2 text-sm text-red-800">{{ deleteError }}</p>
            <p class="mt-2 text-sm text-red-800">Permanently remove this user and their dashboard assignments.</p>
            <button
                type="button"
                class="mt-4 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-100 disabled:opacity-50"
                :disabled="deleteForm.processing"
                @click="destroyUser"
            >
                Delete user
            </button>
        </section>
    </AppLayout>
</template>
