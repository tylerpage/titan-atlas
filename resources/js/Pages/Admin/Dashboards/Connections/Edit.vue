<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import CredentialFieldLabel from '../../../../Components/CredentialFieldLabel.vue';
import GoogleOAuthConnect from '../../../../Components/GoogleOAuthConnect.vue';
import StackAdaptConnect from '../../../../Components/StackAdaptConnect.vue';
import MetaConnect from '../../../../Components/MetaConnect.vue';

const props = defineProps({
    connection: {
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
});

const page = usePage();
const deleteError = computed(() => page.props.errors?.connection);

const form = useForm({
    name: props.connection.name,
    is_active: props.connection.is_active,
    credentials: Object.fromEntries(
        props.connection.credential_fields.map((field) => [
            field.key,
            props.connection.credential_hints?.[field.key] ?? '',
        ]),
    ),
});

const deleteForm = useForm({});
const clearDataForm = useForm({});

const selectedConnector = computed(() =>
    props.connectors.find((connector) => connector.value === props.connection.connector_type),
);

const visibleCredentialFields = computed(() =>
    (selectedConnector.value?.fields ?? []).filter((field) => field.type !== 'oauth_hidden'),
);

const usesGoogleOAuth = computed(() => Boolean(selectedConnector.value?.uses_google_oauth));
const usesStackAdapt = computed(() => props.connection.connector_type === 'stackadapt');
const usesMetaAds = computed(() => props.connection.connector_type === 'meta_ads');
const stackAdaptAdvertisers = ref([]);
const metaAdAccounts = ref([]);

const googleOauth = computed(() => {
    const flashed = page.props.flash?.google_oauth;

    if (flashed?.connected) {
        return flashed;
    }

    return page.props.googleOauth ?? props.googleOauth ?? { connected: false, sites: [], connector_type: null };
});

function applyGoogleOauthPrefill() {
    const oauth = googleOauth.value;

    if (!oauth?.connected) {
        return;
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

    if (props.connection.connector_type === 'google_ads') {
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

    if (usesGoogleOAuth.value && props.connection.connector_type === 'google_ads' && !credentialsForRequest().customer_id) {
        testStatus.value = {
            type: 'error',
            message: 'Select or enter a Google Ads customer ID before testing.',
        };
        testing.value = false;

        return;
    }

    try {
        const response = await fetch(route('admin.connections.test-existing', props.connection.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
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

        if (usesMetaAds.value && Array.isArray(data.debug?.ad_accounts)) {
            metaAdAccounts.value = data.debug.ad_accounts;

            if (!form.credentials.ad_account_id && data.debug.ad_accounts.length === 1) {
                form.credentials.ad_account_id = data.debug.ad_accounts[0].adAccountId;
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
    form.post(route('admin.connections.update', props.connection.id));
}

function destroyConnection() {
    if (!confirm(`Delete ${props.connection.name}? Sync history and connector metrics will be removed.`)) {
        return;
    }

    deleteForm.delete(route('admin.connections.destroy', props.connection.id));
}

function clearConnectionData() {
    if (!confirm(`Clear all synced data for ${props.connection.name}? Raw payloads, sync history, and metrics will be removed. Credentials are kept.`)) {
        return;
    }

    clearDataForm.post(route('admin.connections.clear-data', props.connection.id));
}
</script>

<template>
    <AppLayout :title="`Edit ${connection.name}`">
        <div class="mb-8">
            <p class="text-sm text-slate-500">
                <Link :href="route('admin.connections.show', connection.id)" class="hover:text-slate-700">
                    {{ connection.dashboard.company_name }} · {{ connection.dashboard.name }}
                </Link>
            </p>
            <h1 class="text-3xl font-semibold">Edit connection</h1>
            <p class="mt-2 text-slate-600">{{ connection.connector_label }}</p>
        </div>

        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
            @submit.prevent="submit"
        >
            <div>
                <label class="mb-1 block text-sm font-medium">Connector</label>
                <input
                    type="text"
                    :value="connection.connector_label"
                    disabled
                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600"
                />
                <p class="mt-1 text-sm text-slate-500">Connector type cannot be changed after creation.</p>
            </div>

            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Connection name</label>
                <input id="name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    Active (visible on client dashboard)
                </label>
            </div>

            <div v-if="selectedConnector" class="space-y-4 border-t border-slate-100 pt-6">
                <h2 class="text-lg font-semibold">Credentials</h2>
                <p class="text-sm text-slate-500">Leave credential fields blank to keep the current values.</p>
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
                    :connector-type="connection.connector_type"
                    :dashboard-id="connection.dashboard.id"
                    :connection-id="connection.id"
                    return-to="edit"
                    :google-oauth="googleOauth"
                />

                <StackAdaptConnect
                    v-if="usesStackAdapt"
                    v-model:advertiser-id="form.credentials.advertiser_id"
                    :advertisers="stackAdaptAdvertisers"
                />

                <MetaConnect
                    v-if="usesMetaAds"
                    v-model:ad-account-id="form.credentials.ad_account_id"
                    :ad-accounts="metaAdAccounts"
                />

                <div v-for="field in visibleCredentialFields" :key="field.key">
                    <CredentialFieldLabel
                        v-if="(!usesGoogleOAuth || !['site_url', 'property_id', 'customer_id', 'login_customer_id'].includes(field.key)) && (!usesStackAdapt || field.key !== 'advertiser_id') && (!usesMetaAds || field.key !== 'ad_account_id')"
                        :for-id="field.key"
                        :label="field.label"
                        :help="field.help"
                    />
                    <input
                        v-if="(!usesGoogleOAuth || !['site_url', 'property_id', 'customer_id', 'login_customer_id'].includes(field.key)) && (!usesStackAdapt || field.key !== 'advertiser_id') && (!usesMetaAds || field.key !== 'ad_account_id')"
                        :id="field.key"
                        v-model="form.credentials[field.key]"
                        :type="field.type ?? 'text'"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                        :placeholder="field.type === 'password' ? 'Leave blank to keep current' : (field.placeholder ?? '')"
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
                <Link :href="route('admin.connections.show', connection.id)" class="text-sm text-slate-600 hover:text-slate-900">
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

        <section class="mx-auto mt-10 max-w-2xl rounded-xl border border-red-200 bg-red-50 p-5">
            <h2 class="text-lg font-semibold text-red-900">Danger zone</h2>
            <p v-if="deleteError" class="mt-2 text-sm text-red-800">{{ deleteError }}</p>
            <p class="mt-2 text-sm text-red-800">
                Clear synced data to wipe raw payloads, sync history, and metrics while keeping credentials. Use backfill to reload.
            </p>
            <button
                type="button"
                class="mt-4 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-100 disabled:opacity-50"
                :disabled="clearDataForm.processing"
                @click="clearConnectionData"
            >
                Clear connector data
            </button>
            <p class="mt-6 text-sm text-red-800">
                Delete this connection and remove its synced metrics from the dashboard.
            </p>
            <button
                type="button"
                class="mt-4 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-100 disabled:opacity-50"
                :disabled="deleteForm.processing"
                @click="destroyConnection"
            >
                Delete connection
            </button>
        </section>
    </AppLayout>
</template>
