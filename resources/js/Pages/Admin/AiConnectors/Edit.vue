<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    blueprint: {
        type: Object,
        required: true,
    },
    statuses: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    label: props.blueprint.label,
    slug: props.blueprint.slug,
    status: props.blueprint.status,
});

function submit() {
    form.post(route('admin.ai-connectors.update', props.blueprint.id));
}
</script>

<template>
    <AppLayout :title="`Edit ${blueprint.label}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.ai-connectors.show', blueprint.id)" class="hover:text-slate-700">
                    {{ blueprint.label }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">Edit AI connector</h1>
        </div>

        <form class="mx-auto max-w-xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm" @submit.prevent="submit">
            <div>
                <label for="label" class="mb-1 block text-sm font-medium">Label</label>
                <input id="label" v-model="form.label" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.label" class="mt-1 text-sm text-red-600">{{ form.errors.label }}</p>
            </div>

            <div>
                <label for="slug" class="mb-1 block text-sm font-medium">Slug</label>
                <input id="slug" v-model="form.slug" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
            </div>

            <div>
                <label for="status" class="mb-1 block text-sm font-medium">Status</label>
                <select id="status" v-model="form.status" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option v-for="statusOption in statuses" :key="statusOption.value" :value="statusOption.value">
                        {{ statusOption.label }}
                    </option>
                </select>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover" :disabled="form.processing">
                    Save changes
                </button>
                <Link :href="route('admin.ai-connectors.show', blueprint.id)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
