<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.company.name,
    slug: props.company.slug,
});

function submit() {
    form.post(route('admin.companies.update', props.company.id));
}
</script>

<template>
    <AppLayout title="Edit company">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Edit company</h1>
            <p class="mt-2 text-slate-600">{{ company.name }}</p>
        </div>

        <form
            class="mx-auto max-w-lg space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Company name</label>
                <input id="name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="slug" class="mb-1 block text-sm font-medium">Slug</label>
                <input id="slug" v-model="form.slug" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
            </div>

            <div class="flex items-center justify-between gap-4 pt-2">
                <Link :href="route('admin.companies.show', company.id)" class="text-sm text-slate-600 hover:text-slate-900">
                    Cancel
                </Link>
                <button
                    type="submit"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Save changes
                </button>
            </div>
        </form>
    </AppLayout>
</template>
