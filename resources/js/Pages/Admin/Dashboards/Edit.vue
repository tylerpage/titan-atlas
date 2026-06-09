<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    dashboard: {
        type: Object,
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
});

const form = useForm({
    name: props.dashboard.name,
    slug: props.dashboard.slug,
    timezone: props.dashboard.timezone,
    default_date_range: props.dashboard.default_date_range,
    attribution_window_days: props.dashboard.attribution_window_days,
    primary_color: props.dashboard.primary_color,
    secondary_color: props.dashboard.secondary_color,
    custom_domain: props.dashboard.custom_domain ?? '',
    logo: null,
    remove_logo: false,
});

const logoPreview = ref(props.dashboard.logo_url);

watch(
    () => form.logo,
    (file) => {
        if (file instanceof File) {
            logoPreview.value = URL.createObjectURL(file);
            form.remove_logo = false;
        }
    },
);

function onLogoChange(event) {
    form.logo = event.target.files[0] ?? null;
}

function removeLogo() {
    form.logo = null;
    form.remove_logo = true;
    logoPreview.value = null;
}

function submit() {
    form.post(route('admin.dashboards.update', props.dashboard.id), {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout title="Edit dashboard">
        <div class="mb-8">
            <p class="text-sm text-slate-500">{{ dashboard.company_name }}</p>
            <h1 class="text-3xl font-semibold">Edit dashboard</h1>
        </div>

        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Dashboard name</label>
                <input id="name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="slug" class="mb-1 block text-sm font-medium">Slug</label>
                <input id="slug" v-model="form.slug" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="timezone" class="mb-1 block text-sm font-medium">Timezone</label>
                    <select id="timezone" v-model="form.timezone" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option v-for="(label, value) in timezones" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label for="default_date_range" class="mb-1 block text-sm font-medium">Default date range</label>
                    <select id="default_date_range" v-model="form.default_date_range" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option v-for="(label, value) in dateRangePresets" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="attribution_window_days" class="mb-1 block text-sm font-medium">Attribution window</label>
                <select id="attribution_window_days" v-model="form.attribution_window_days" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option v-for="(label, value) in attributionWindows" :key="value" :value="Number(value)">{{ label }}</option>
                </select>
            </div>

            <div class="border-t border-slate-100 pt-6">
                <h2 class="mb-4 text-lg font-semibold">Branding</h2>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Logo</label>
                    <div v-if="logoPreview" class="mb-3 flex items-center gap-4">
                        <img :src="logoPreview" alt="Dashboard logo" class="h-16 w-auto rounded border border-slate-200 bg-white p-1" />
                        <button type="button" class="text-sm text-red-600 hover:underline" @click="removeLogo">Remove logo</button>
                    </div>
                    <input type="file" accept="image/*" class="block w-full text-sm" @change="onLogoChange" />
                    <p v-if="form.errors.logo" class="mt-1 text-sm text-red-600">{{ form.errors.logo }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="primary_color" class="mb-1 block text-sm font-medium">Primary color</label>
                        <input id="primary_color" v-model="form.primary_color" type="color" class="h-10 w-full rounded border border-slate-300" />
                    </div>
                    <div>
                        <label for="secondary_color" class="mb-1 block text-sm font-medium">Secondary color</label>
                        <input id="secondary_color" v-model="form.secondary_color" type="color" class="h-10 w-full rounded border border-slate-300" />
                    </div>
                </div>

                <div class="mt-4">
                    <label for="custom_domain" class="mb-1 block text-sm font-medium">Custom domain</label>
                    <input
                        id="custom_domain"
                        v-model="form.custom_domain"
                        type="text"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        placeholder="reports.client.com"
                    />
                    <p class="mt-1 text-sm text-slate-500">Provision via Laravel Forge. Clients log in normally.</p>
                    <p v-if="form.errors.custom_domain" class="mt-1 text-sm text-red-600">{{ form.errors.custom_domain }}</p>
                </div>

                <p class="mt-4 text-sm text-slate-500">Powered by Irish Titan is always shown on client dashboards.</p>
            </div>

            <div class="flex items-center justify-between gap-4 pt-2">
                <Link :href="route('admin.dashboards.show', dashboard.id)" class="text-sm text-slate-600 hover:text-slate-900">
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
