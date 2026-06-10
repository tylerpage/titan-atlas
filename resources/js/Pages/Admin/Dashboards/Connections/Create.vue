<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import CredentialFieldLabel from '../../../../Components/CredentialFieldLabel.vue';
import GoogleOAuthConnect from '../../../../Components/GoogleOAuthConnect.vue';
import StackAdaptConnect from '../../../../Components/StackAdaptConnect.vue';

const page = usePage();

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    connectors: {
        type: Array,
        required: true,
    },
    googleOauth: {
        type: Object,
        default: () => ({ connected: false, connector_type: null, sites: [] }),
    },
    defaultConnectorType: {
        type: String,
        default: null,
    },
});

const googleOauth = computed(() => {
    const flashed = page.props.flash?.google_oauth;

    if (flashed?.connected) {
        return flashed;
    }

    return page.props.googleOauth ?? props.googleOauth ?? { connected: false, sites: [], connector_type: null };
});
const defaultConnectorType = computed(() => {
    if (googleOauth.value?.connected && googleOauth.value?.connector_type) {
        return googleOauth.value.connector_type;
    }

    return page.props.defaultConnectorType ?? props.defaultConnectorType ?? null;
});
const oauthError = computed(() => {
    const errors = page.props.errors ?? {};

    return (
        page.props.flash?.error
        ?? errors.credentials
        ?? errors['credentials.refresh_token']
        ?? null
    );
});

function initialConnectorType() {
    return (
        defaultConnectorType.value
        ?? (googleOauth.value?.connected ? googleOauth.value.connector_type : null)
        ?? props.connectors[0]?.value
        ?? ''
    );
}

const form = useForm({
    name: '',
    connector_type: initialConnectorType(),
    credentials: {},
});

const selectedConnector = computed(() =>
    props.connectors.find((connector) => connector.value === form.connector_type),
);

const visibleCredentialFields = computed(() =>
    (selectedConnector.value?.fields ?? []).filter((field) => field.type !== 'oauth_hidden'),
);

const usesGoogleOAuth = computed(() => Boolean(selectedConnector.value?.uses_google_oauth));
const usesStackAdapt = computed(() => form.connector_type === 'stackadapt');
const stackAdaptAdvertisers = ref([]);

function applyGoogleOauthPrefill() {
    const oauth = googleOauth.value;

    if (!oauth?.connected) {
        return;
    }

    if (oauth.connector_type && form.connector_type !== oauth.connector_type) {
        form.connector_type = oauth.connector_type;
    }

    const sites = oauth.sites ?? [];

    if (!form.credentials.site_url && sites.length === 1) {
        form.credentials.site_url = sites[0].siteUrl;
    }

    const properties = oauth.properties ?? [];

    if (!form.credentials.property_id && properties.length === 1) {
        form.credentials.property_id = properties[0].propertyId;
    }

    const customers = oauth.customers ?? [];

    if (!form.credentials.customer_id && customers.length === 1) {
        form.credentials.customer_id = customers[0].customerId;
        form.credentials.login_customer_id = customers[0].managerCustomerId ?? '';
    }
}

function credentialsForRequest() {
    const credentials = { ...form.credentials };

    if (form.connector_type === 'google_ads') {
        credentials.customer_id = String(credentials.customer_id ?? '').replace(/\D/g, '');

        if (credentials.login_customer_id) {
            credentials.login_customer_id = String(credentials.login_customer_id).replace(/\D/g, '');
        } else {
            delete credentials.login_customer_id;
        }
    }

    return credentials;
}

watch(
    () => form.connector_type,
    (type) => {
        const connector = props.connectors.find((item) => item.value === type);
        const credentials = {};

        for (const field of connector?.fields ?? []) {
            credentials[field.key] = form.credentials[field.key] ?? '';
        }

        form.credentials = credentials;

        if (!form.name) {
            form.name = connector?.label ?? '';
        }

        applyGoogleOauthPrefill();
    },
    { immediate: true },
);

watch(
    defaultConnectorType,
    (type) => {
        if (type && form.connector_type !== type) {
            form.connector_type = type;
        }
    },
    { immediate: true },
);

watch(
    googleOauth,
    () => {
        applyGoogleOauthPrefill();
    },
    { immediate: true, deep: true },
);

const testing = ref(false);
const testStatus = ref(null);

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function testConnection() {
    testing.value = true;
    testStatus.value = null;

    if (usesGoogleOAuth.value && form.connector_type === 'google_ads' && !credentialsForRequest().customer_id) {
        testStatus.value = {
            type: 'error',
            message: 'Select or enter a Google Ads customer ID before testing.',
        };
        testing.value = false;

        return;
    }

    try {
        const response = await fetch(route('admin.connections.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                connector_type: form.connector_type,
                dashboard_id: props.dashboard.id,
                credentials: credentialsForRequest(),
            }),
        });

        const data = await response.json();

        if (!response.ok && data.errors) {
            const firstError = Object.values(data.errors).flat()[0];

            testStatus.value = {
                type: 'error',
                message: firstError ?? 'Connection validation failed.',
            };

            return;
        }

        const hint = data.debug?.hint ?? data.debug?.graphql_hint;

        testStatus.value = {
            type: data.valid ? 'success' : 'error',
            message: !data.valid && hint && !String(data.message ?? '').includes(hint)
                ? `${data.message ?? 'Connection failed.'} ${hint}`
                : (data.message ?? (data.valid ? 'Connection successful.' : 'Connection failed.')),
            debug: data.debug ?? null,
        };

        if (usesStackAdapt.value && Array.isArray(data.debug?.advertisers)) {
            stackAdaptAdvertisers.value = data.debug.advertisers;

            if (!form.credentials.advertiser_id && data.debug.advertisers.length === 1) {
                form.credentials.advertiser_id = data.debug.advertisers[0].advertiserId;
            }
        }
    } catch {
        testStatus.value = {
            type: 'error',
            message: 'Could not test connection.',
        };
    } finally {
        testing.value = false;
    }
}

function submit() {
    if (usesGoogleOAuth.value && form.connector_type === 'search_console' && !form.credentials.site_url) {
        form.setError('credentials.site_url', 'Select a Search Console property after connecting with Google.');

        return;
    }

    if (usesGoogleOAuth.value && form.connector_type === 'google_analytics' && !form.credentials.property_id) {
        form.setError('credentials.property_id', 'Select a GA4 property after connecting with Google.');

        return;
    }

    if (usesGoogleOAuth.value && form.connector_type === 'google_ads' && !form.credentials.customer_id) {
        form.setError('credentials.customer_id', 'Select a Google Ads account after connecting with Google.');

        return;
    }

    if (usesStackAdapt.value && !form.credentials.advertiser_id) {
        form.setError('credentials.advertiser_id', 'Select a StackAdapt advertiser after testing your GraphQL API key.');

        return;
    }

    form.post(route('admin.dashboards.connections.store', props.dashboard.id));
}
</script>

<template>
    <AppLayout title="Add connection">
        <div class="mb-8">
            <p class="text-sm text-slate-500">{{ dashboard.company_name }}</p>
            <h1 class="text-3xl font-semibold">Add connection</h1>
            <p class="mt-2 text-slate-600">For {{ dashboard.name }}</p>
        </div>

        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <p v-if="oauthError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ oauthError }}
            </p>

            <div>
                <label for="connector_type" class="mb-1 block text-sm font-medium">Connector</label>
                <select id="connector_type" v-model="form.connector_type" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option v-for="connector in connectors" :key="connector.value" :value="connector.value">
                        {{ connector.label }}
                    </option>
                </select>
                <p v-if="form.errors.connector_type" class="mt-1 text-sm text-red-600">{{ form.errors.connector_type }}</p>
            </div>

            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Connection name</label>
                <input id="name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div v-if="selectedConnector" class="space-y-4 border-t border-slate-100 pt-6">
                <h2 class="text-lg font-semibold">Credentials</h2>
                <p class="text-sm text-slate-500">
                    Stored encrypted. A backfill sync starts automatically after saving.
                </p>
                <p
                    v-if="selectedConnector.access_summary"
                    class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600"
                >
                    {{ selectedConnector.access_summary }}
                </p>

                <GoogleOAuthConnect
                    v-if="usesGoogleOAuth"
                    v-model:site-url="form.credentials.site_url"
                    v-model:property-id="form.credentials.property_id"
                    v-model:customer-id="form.credentials.customer_id"
                    v-model:login-customer-id="form.credentials.login_customer_id"
                    :connector-type="form.connector_type"
                    :dashboard-id="dashboard.id"
                    return-to="create"
                    :google-oauth="googleOauth"
                />

                <StackAdaptConnect
                    v-if="usesStackAdapt"
                    v-model:advertiser-id="form.credentials.advertiser_id"
                    :advertisers="stackAdaptAdvertisers"
                />

                <div v-for="field in visibleCredentialFields" :key="field.key">
                    <CredentialFieldLabel
                        v-if="(!usesGoogleOAuth || !['site_url', 'property_id', 'customer_id', 'login_customer_id'].includes(field.key)) && (!usesStackAdapt || field.key !== 'advertiser_id')"
                        :for-id="field.key"
                        :label="field.label"
                        :help="field.help"
                    />
                    <input
                        v-if="(!usesGoogleOAuth || !['site_url', 'property_id', 'customer_id', 'login_customer_id'].includes(field.key)) && (!usesStackAdapt || field.key !== 'advertiser_id')"
                        :id="field.key"
                        v-model="form.credentials[field.key]"
                        :type="field.type ?? 'text'"
                        :required="field.key !== 'login_customer_id' && field.key !== 'rest_api_key'"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        :placeholder="field.placeholder ?? ''"
                    />
                    <p v-if="form.errors[`credentials.${field.key}`]" class="mt-1 text-sm text-red-600">
                        {{ form.errors[`credentials.${field.key}`] }}
                    </p>
                </div>

                <p v-if="form.errors['credentials.refresh_token']" class="text-sm text-red-600">
                    {{ form.errors['credentials.refresh_token'] }}
                </p>

                <p v-if="form.errors.credentials" class="text-sm text-red-600">{{ form.errors.credentials }}</p>

                <div v-if="selectedConnector?.supports_test" class="space-y-2">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="testing || form.processing"
                        @click="testConnection"
                    >
                        {{ testing ? 'Testing…' : 'Test connection' }}
                    </button>
                    <p
                        v-if="testStatus"
                        class="text-sm"
                        :class="testStatus.type === 'success' ? 'text-green-700' : 'text-red-600'"
                    >
                        {{ testStatus.message }}
                    </p>
                    <pre
                        v-if="testStatus?.debug"
                        class="overflow-x-auto rounded-lg bg-slate-100 p-3 text-xs text-slate-700"
                    >{{ JSON.stringify(testStatus.debug, null, 2) }}</pre>
                </div>
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
                    Add connection
                </button>
            </div>
        </form>
    </AppLayout>
</template>
