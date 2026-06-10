<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const page = usePage();
const status = page.props.flash?.status;

const form = useForm({
    email: '',
});

function submit() {
    form.post(route('password.email'));
}
</script>

<template>
    <AppLayout title="Forgot password">
        <div class="mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            <h1 class="mb-2 text-2xl font-semibold">Forgot password</h1>
            <p class="mb-6 text-sm text-slate-600">
                Enter your email and we will send you a link to reset your password.
            </p>

            <p v-if="status" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ status }}
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                </div>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Send reset link
                </button>
            </form>

            <p class="mt-6 text-sm">
                <Link :href="route('login')" class="text-primary hover:underline">Back to sign in</Link>
            </p>
        </div>
    </AppLayout>
</template>
