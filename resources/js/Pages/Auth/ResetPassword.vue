<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    token: {
        type: String,
        required: true,
    },
    email: {
        type: String,
        default: '',
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AppLayout title="Reset password">
        <div class="mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            <h1 class="mb-2 text-2xl font-semibold">Reset password</h1>
            <p class="mb-6 text-sm text-slate-600">Choose a new password for your account.</p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium">New password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autofocus
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    />
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

                <button
                    type="submit"
                    class="w-full rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Reset password
                </button>
            </form>

            <p class="mt-6 text-sm">
                <Link :href="route('login')" class="text-primary hover:underline">Back to sign in</Link>
            </p>
        </div>
    </AppLayout>
</template>
