<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { useAppBranding } from '../../Composables/useAppBranding';

const { appName } = useAppBranding();

const props = defineProps({
    invitation: {
        type: Object,
        default: null,
    },
    token: {
        type: String,
        required: true,
    },
    expired: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('invitations.store', props.token));
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 px-6 py-16">
        <div class="mx-auto max-w-md">
            <div class="mb-8 text-center">
                <img src="/logo.svg" :alt="appName" class="mx-auto h-10 w-auto" />
            </div>

            <div v-if="expired" class="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
                <h1 class="text-2xl font-semibold">Invitation expired</h1>
                <p class="mt-3 text-sm text-slate-600">
                    This invitation link is no longer valid. Ask your administrator to send a new invitation.
                </p>
                <Link :href="route('login')" class="mt-6 inline-block text-sm text-primary hover:underline">
                    Go to login
                </Link>
            </div>

            <div v-else class="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
                <h1 class="text-2xl font-semibold">Accept invitation</h1>
                <p class="mt-3 text-sm text-slate-600">
                    You've been invited to join <strong>{{ invitation.company_name }}</strong> as a
                    {{ invitation.role }}.
                </p>

                <form class="mt-8 space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Email</label>
                        <input
                            type="email"
                            :value="invitation.email"
                            disabled
                            class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600"
                        />
                    </div>

                    <div>
                        <label for="name" class="mb-1 block text-sm font-medium">Your name</label>
                        <input id="name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                        <input id="password" v-model="form.password" type="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-1 block text-sm font-medium">Confirm password</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        />
                    </div>

                    <p v-if="form.errors.invitation" class="text-sm text-red-600">{{ form.errors.invitation }}</p>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        Create account
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
