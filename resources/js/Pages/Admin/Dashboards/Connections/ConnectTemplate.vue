<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import CredentialFieldLabel from '../../../../Components/CredentialFieldLabel.vue';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    blueprint: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.blueprint.label,
    credentials: {
        base_url: props.blueprint.sync_config?.base_url ?? '',
        ...Object.fromEntries(
            (props.blueprint.credential_fields ?? []).map((field) => [field.key, '']),
        ),
    },
});

const testing = ref(false);
const testStatus = ref(null);

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function testConnection() {
    testing.value = true;
    testStatus.value = null;

    try {
        const response = await fetch(
            route('admin.dashboards.connections.from-template.test', [props.dashboard.id, props.blueprint.id]),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ credentials: form.credentials }),
            },
        );

        const payload = await response.json();
        testStatus.value = {
            type: payload.valid ? 'success' : 'error',
            message: payload.message ?? (payload.valid ? 'Connection test passed.' : 'Connection test failed.'),
        };
    } catch {
        testStatus.value = { type: 'error', message: 'Could not test the connection.' };
    } finally {
        testing.value = false;
    }
}

function submit() {
    form.post(route('admin.dashboards.connections.from-template.store', [props.dashboard.id, props.blueprint.id]));
}
</script>

<template>
    <AppLayout :title="`Connect ${blueprint.label}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.dashboards.show', dashboard.id)" class="hover:text-slate-700">
                    {{ dashboard.company_name }} · {{ dashboard.name }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">Connect {{ blueprint.label }}</h1>
            <p class="mt-2 text-sm text-slate-600">
                This AI connector template can be reused on other dashboards. Credentials are stored only for this dashboard connection.
            </p>
        </div>

        <form class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm" @submit.prevent="submit">
            <div class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">
                <p><span class="font-medium">Template:</span> {{ blueprint.slug }}</p>
                <p><span class="font-medium">Status:</span> {{ blueprint.status }}</p>
                <p><span class="font-medium">Streams:</span> {{ blueprint.streams_count }}</p>
                <p v-if="blueprint.sync_config?.base_url"><span class="font-medium">Template default base URL:</span> {{ blueprint.sync_config.base_url }}</p>
            </div>

            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Connection name</label>
                <input id="name" v-model="form.name" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
            </div>

            <div>
                <CredentialFieldLabel
                    for-id="credential-base-url"
                    label="API base URL"
                    help="Your shop or API root URL for this dashboard. Overrides the template default when set."
                />
                <input
                    id="credential-base-url"
                    v-model="form.credentials.base_url"
                    type="url"
                    required
                    placeholder="https://your-shop.example.com"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors['credentials.base_url']" class="mt-1 text-sm text-red-600">
                    {{ form.errors['credentials.base_url'] }}
                </p>
            </div>

            <div v-for="field in blueprint.credential_fields" :key="field.key">
                <CredentialFieldLabel
                    :for-id="`credential-${field.key}`"
                    :label="field.label || field.key"
                    :help="field.help"
                />
                <input
                    :id="`credential-${field.key}`"
                    v-model="form.credentials[field.key]"
                    :type="field.type === 'password' ? 'password' : 'text'"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                />
                <p v-if="form.errors[`credentials.${field.key}`]" class="mt-1 text-sm text-red-600">
                    {{ form.errors[`credentials.${field.key}`] }}
                </p>
            </div>

            <div v-if="testStatus" class="rounded-lg px-4 py-3 text-sm" :class="testStatus.type === 'success' ? 'border border-emerald-200 bg-emerald-50 text-emerald-800' : 'border border-red-200 bg-red-50 text-red-700'">
                {{ testStatus.message }}
            </div>

            <div class="flex flex-wrap gap-3">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    :disabled="testing || form.processing"
                    @click="testConnection"
                >
                    {{ testing ? 'Testing…' : 'Test connection' }}
                </button>
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover" :disabled="form.processing">
                    Save and backfill
                </button>
                <Link :href="route('admin.dashboards.connections.create', dashboard.id)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                    Cancel
                </Link>
            </div>
        </form>
    </AppLayout>
</template>
