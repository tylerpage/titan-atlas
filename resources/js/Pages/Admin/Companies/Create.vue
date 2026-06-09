<script setup>
import { watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const form = useForm({
    name: '',
    slug: '',
});

let slugTouched = false;

watch(
    () => form.name,
    (name) => {
        if (!slugTouched) {
            form.slug = name
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    },
);

function markSlugTouched() {
    slugTouched = true;
}

function submit() {
    form.post(route('admin.companies.store'));
}
</script>

<template>
    <AppLayout title="Add company">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Add company</h1>
            <p class="mt-2 text-slate-600">Create a new client organization.</p>
        </div>

        <form
            class="mx-auto max-w-lg space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Company name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    placeholder="Keller-Heartt"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="slug" class="mb-1 block text-sm font-medium">Slug</label>
                <input
                    id="slug"
                    v-model="form.slug"
                    type="text"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    placeholder="keller-heartt"
                    @input="markSlugTouched"
                />
                <p class="mt-1 text-sm text-slate-500">Auto-generated from name if left blank.</p>
                <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
            </div>

            <div class="flex items-center justify-between gap-4 pt-2">
                <Link :href="route('admin.companies.index')" class="text-sm text-slate-600 hover:text-slate-900">
                    Cancel
                </Link>
                <button
                    type="submit"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Create company
                </button>
            </div>
        </form>
    </AppLayout>
</template>
