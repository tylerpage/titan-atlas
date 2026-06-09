<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useAppBranding } from '../../Composables/useAppBranding';

const { appName } = useAppBranding();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AppLayout title="Sign in">
        <div class="mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
            <h1 class="mb-6 text-2xl font-semibold">Sign in to {{ appName }}</h1>

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

                <div>
                    <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.remember" type="checkbox" />
                    Remember me
                </label>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Sign in
                </button>
            </form>

            <p class="mt-6 text-sm text-slate-500">
                Demo: admin@titan.test / client@acme.test — password:
                <code>password</code>
            </p>
        </div>
    </AppLayout>
</template>
