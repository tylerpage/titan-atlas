<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    companies: {
        type: Array,
        required: true,
    },
    templates: {
        type: Array,
        required: true,
    },
    dateRangePresets: {
        type: Object,
        required: true,
    },
    attributionWindows: {
        type: Object,
        required: true,
    },
    timezones: {
        type: Object,
        required: true,
    },
    defaults: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    company_id: props.companies[0]?.id ?? '',
    name: '',
    slug: '',
    dashboard_template_id: props.templates[0]?.id ?? '',
    timezone: props.defaults.timezone,
    currency: props.defaults.currency,
    attribution_window_days: props.defaults.attribution_window_days,
    default_date_range: props.defaults.default_date_range,
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
    form.post(route('admin.dashboards.store'));
}
</script>

<template>
    <AppLayout title="Create dashboard">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Create dashboard</h1>
            <p class="mt-2 text-slate-600">Set up a new client reporting dashboard.</p>
        </div>

        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="company_id" class="mb-1 block text-sm font-medium">Company</label>
                <select
                    id="company_id"
                    v-model="form.company_id"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option v-for="company in companies" :key="company.id" :value="company.id">
                        {{ company.name }}
                    </option>
                </select>
                <p v-if="form.errors.company_id" class="mt-1 text-sm text-red-600">{{ form.errors.company_id }}</p>
            </div>

            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Dashboard name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    placeholder="Acme Performance"
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
                    placeholder="acme-performance"
                    @input="markSlugTouched"
                />
                <p class="mt-1 text-sm text-slate-500">Unique within the company. Auto-generated from name if left blank.</p>
                <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
            </div>

            <div>
                <label for="dashboard_template_id" class="mb-1 block text-sm font-medium">Template</label>
                <select
                    id="dashboard_template_id"
                    v-model="form.dashboard_template_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option value="">No template</option>
                    <option v-for="template in templates" :key="template.id" :value="template.id">
                        {{ template.name }}
                    </option>
                </select>
                <p v-if="templates.find((t) => t.id === form.dashboard_template_id)?.description" class="mt-1 text-sm text-slate-500">
                    {{ templates.find((t) => t.id === form.dashboard_template_id)?.description }}
                </p>
                <p v-if="form.errors.dashboard_template_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.dashboard_template_id }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="timezone" class="mb-1 block text-sm font-medium">Timezone</label>
                    <select id="timezone" v-model="form.timezone" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option v-for="(label, value) in timezones" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                    <p v-if="form.errors.timezone" class="mt-1 text-sm text-red-600">{{ form.errors.timezone }}</p>
                </div>

                <div>
                    <label for="default_date_range" class="mb-1 block text-sm font-medium">Default date range</label>
                    <select
                        id="default_date_range"
                        v-model="form.default_date_range"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    >
                        <option v-for="(label, value) in dateRangePresets" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                    <p v-if="form.errors.default_date_range" class="mt-1 text-sm text-red-600">
                        {{ form.errors.default_date_range }}
                    </p>
                </div>
            </div>

            <div>
                <label for="attribution_window_days" class="mb-1 block text-sm font-medium">Attribution window</label>
                <select
                    id="attribution_window_days"
                    v-model="form.attribution_window_days"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option v-for="(label, value) in attributionWindows" :key="value" :value="Number(value)">
                        {{ label }}
                    </option>
                </select>
                <p v-if="form.errors.attribution_window_days" class="mt-1 text-sm text-red-600">
                    {{ form.errors.attribution_window_days }}
                </p>
            </div>

            <div class="flex items-center justify-between gap-4 pt-2">
                <a :href="route('admin.dashboards.index')" class="text-sm text-slate-600 hover:text-slate-900">
                    Cancel
                </a>
                <button
                    type="submit"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Create dashboard
                </button>
            </div>
        </form>
    </AppLayout>
</template>
