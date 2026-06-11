<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    companies: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    payload: '',
    file: null,
    scope: 'global',
    mode: 'create',
    company_id: '',
});

const showCompanyPicker = computed(() => form.scope === 'company');

function onFileChange(event) {
    form.file = event.target.files[0] ?? null;
}

function submit() {
    form.post(route('admin.ai-connectors.import.store'), {
        forceFormData: Boolean(form.file),
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout title="Import AI connector">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.ai-connectors.index')" class="hover:text-slate-700">AI Connectors</Link>
            </p>
            <h1 class="text-3xl font-semibold">Import AI connector</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                Load a connector template exported from another environment. Credentials, connections, and synced data are not included.
            </p>
        </div>

        <form
            class="mx-auto max-w-3xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label for="payload" class="mb-1 block text-sm font-medium">Export JSON</label>
                <textarea
                    id="payload"
                    v-model="form.payload"
                    rows="12"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs"
                    placeholder="Paste the contents of a .json export file, or choose a file below."
                />
                <p v-if="form.errors.payload" class="mt-1 text-sm text-red-600">
                    {{ form.errors.payload }}
                </p>
            </div>

            <div>
                <label for="file" class="mb-1 block text-sm font-medium">Or upload file</label>
                <input
                    id="file"
                    type="file"
                    accept=".json,application/json,text/plain"
                    class="block w-full text-sm text-slate-600"
                    @change="onFileChange"
                />
                <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">
                    {{ form.errors.file }}
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="scope" class="mb-1 block text-sm font-medium">Import as</label>
                    <select
                        id="scope"
                        v-model="form.scope"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="global">Global (all companies)</option>
                        <option value="company">Company-scoped template</option>
                    </select>
                    <p v-if="form.errors.scope" class="mt-1 text-sm text-red-600">
                        {{ form.errors.scope }}
                    </p>
                </div>

                <div>
                    <label for="mode" class="mb-1 block text-sm font-medium">If slug already exists</label>
                    <select
                        id="mode"
                        v-model="form.mode"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="create">Create new (fail on conflict)</option>
                        <option value="replace">Replace existing</option>
                    </select>
                    <p v-if="form.errors.mode" class="mt-1 text-sm text-red-600">
                        {{ form.errors.mode }}
                    </p>
                </div>
            </div>

            <div v-if="showCompanyPicker">
                <label for="company_id" class="mb-1 block text-sm font-medium">Company</label>
                <select
                    id="company_id"
                    v-model="form.company_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    :required="showCompanyPicker"
                >
                    <option value="" disabled>Select a company</option>
                    <option v-for="company in companies" :key="company.id" :value="company.id">
                        {{ company.name }}
                    </option>
                </select>
                <p v-if="form.errors.company_id" class="mt-1 text-sm text-red-600">
                    {{ form.errors.company_id }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Importing…' : 'Import connector' }}
                </button>
                <Link
                    :href="route('admin.ai-connectors.index')"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
