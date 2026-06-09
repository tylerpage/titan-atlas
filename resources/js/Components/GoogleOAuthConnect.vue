<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const props = defineProps({
    connectorType: {
        type: String,
        required: true,
    },
    dashboardId: {
        type: Number,
        required: true,
    },
    connectionId: {
        type: Number,
        default: null,
    },
    returnTo: {
        type: String,
        default: 'create',
    },
    googleOauth: {
        type: Object,
        default: () => ({ connected: false, sites: [], properties: [], customers: [] }),
    },
});

const siteUrl = defineModel('siteUrl', { type: String, default: '' });
const propertyId = defineModel('propertyId', { type: String, default: '' });
const customerId = defineModel('customerId', { type: String, default: '' });
const loginCustomerId = defineModel('loginCustomerId', { type: String, default: '' });

const isSearchConsole = computed(() => props.connectorType === 'search_console');
const isGoogleAnalytics = computed(() => props.connectorType === 'google_analytics');
const isGoogleAds = computed(() => props.connectorType === 'google_ads');

const oauthUrl = computed(() => {
    const params = {
        connector_type: props.connectorType,
        dashboard_id: props.dashboardId,
        return_to: props.returnTo,
    };

    if (props.connectionId) {
        params.connection_id = props.connectionId;
    }

    const query = new URLSearchParams(params);

    return `${route('admin.google.oauth.redirect')}?${query.toString()}`;
});

const oauthState = computed(() => {
    const flashed = page.props.flash?.google_oauth;

    if (flashed?.connected && flashed?.connector_type === props.connectorType) {
        return flashed;
    }

    if (page.props.googleOauth?.connected && page.props.googleOauth?.connector_type === props.connectorType) {
        return page.props.googleOauth;
    }

    return props.googleOauth ?? { connected: false, sites: [], properties: [], customers: [], google_email: null, google_name: null };
});

const siteOptions = computed(() => oauthState.value?.sites ?? []);
const propertyOptions = computed(() => oauthState.value?.properties ?? []);
const customerOptions = computed(() => oauthState.value?.customers ?? []);
const isConnected = computed(() => Boolean(oauthState.value?.connected));
const queryablePermissionLevels = ['siteOwner', 'siteFullUser', 'siteRestrictedUser'];

function isQueryableSite(site) {
    return queryablePermissionLevels.includes(site.permissionLevel);
}

const customerSelection = ref('');

function customerOptionKey(customer) {
    return `${customer.customerId}:${customer.managerCustomerId ?? ''}`;
}

function customerPickerLabel(customer) {
    return customer.pickerLabel ?? customer.displayName ?? customer.customerId;
}

function applyCustomerSelection(value) {
    if (!value) {
        return;
    }

    const [id, managerId] = value.split(':');

    customerId.value = id;
    loginCustomerId.value = managerId || '';
}

watch(customerSelection, (value) => {
    if (!isGoogleAds.value) {
        return;
    }

    if (!value) {
        return;
    }

    applyCustomerSelection(value);
});

watch(
    [customerId, loginCustomerId, customerOptions],
    () => {
        if (!isGoogleAds.value || !customerId.value) {
            customerSelection.value = '';

            return;
        }

        const exactKey = `${customerId.value}:${loginCustomerId.value ?? ''}`;
        const hasExactMatch = customerOptions.value.some((customer) => customerOptionKey(customer) === exactKey);

        if (hasExactMatch) {
            customerSelection.value = exactKey;

            return;
        }

        const fallbackMatch = customerOptions.value.find((customer) => customer.customerId === customerId.value);

        if (fallbackMatch) {
            customerSelection.value = customerOptionKey(fallbackMatch);
            loginCustomerId.value = fallbackMatch.managerCustomerId ?? '';
        }
    },
    { immediate: true, deep: true },
);

const connectedAccountLabel = computed(() => {
    const oauth = oauthState.value;

    if (!oauth?.connected) {
        return null;
    }

    if (oauth.google_name && oauth.google_email) {
        return `${oauth.google_name} (${oauth.google_email})`;
    }

    return oauth.google_email ?? oauth.google_name ?? null;
});

const connectLabel = computed(() => {
    if (isSearchConsole.value) {
        return isConnected.value
            ? 'Connected. Choose a Search Console property below.'
            : 'Sign in with Google to authorize read-only Search Console access.';
    }

    if (isGoogleAds.value) {
        return isConnected.value
            ? 'Connected. Choose a Google Ads account below.'
            : 'Sign in with Google to authorize read-only Google Ads access.';
    }

    return isConnected.value
        ? 'Connected. Choose a GA4 property below.'
        : 'Sign in with Google to authorize read-only Google Analytics access.';
});
</script>

<template>
    <div class="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-slate-900">Google account</p>
                <p class="text-sm text-slate-600">{{ connectLabel }}</p>
                <p v-if="connectedAccountLabel" class="mt-1 text-sm font-medium text-slate-800">
                    Connected as {{ connectedAccountLabel }}
                </p>
            </div>
            <a
                :href="oauthUrl"
                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
                {{ isConnected ? 'Reconnect Google' : 'Connect with Google' }}
            </a>
        </div>

        <div v-if="isConnected && isSearchConsole && siteOptions.length" class="space-y-2">
            <label for="site_url" class="mb-1 block text-sm font-medium">Search Console property</label>
            <p class="text-sm text-slate-600">
                Choose the verified property Atlas should sync. Save the connection after selecting one.
            </p>
            <select
                id="site_url"
                v-model="siteUrl"
                required
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
            >
                <option value="" disabled>Select a property</option>
                <option
                    v-for="site in siteOptions"
                    :key="site.siteUrl"
                    :value="site.siteUrl"
                    :disabled="!isQueryableSite(site)"
                >
                    {{ site.siteUrl }} ({{ site.permissionLevel }}){{ isQueryableSite(site) ? '' : ' — read-only, cannot sync' }}
                </option>
            </select>
            <p v-if="!siteUrl" class="text-sm text-amber-700">
                A property is required before you can save or test this connection.
            </p>
        </div>

        <div v-else-if="isConnected && isGoogleAnalytics && propertyOptions.length" class="space-y-2">
            <label for="property_id" class="mb-1 block text-sm font-medium">GA4 property</label>
            <p class="text-sm text-slate-600">
                Choose the GA4 property Atlas should sync. Save the connection after selecting one.
            </p>
            <select
                id="property_id"
                v-model="propertyId"
                required
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
            >
                <option value="" disabled>Select a property</option>
                <option
                    v-for="property in propertyOptions"
                    :key="property.propertyId"
                    :value="property.propertyId"
                >
                    {{ property.displayName }} ({{ property.accountName }}) — {{ property.propertyId }}
                </option>
            </select>
            <p v-if="!propertyId" class="text-sm text-amber-700">
                A property is required before you can save or test this connection.
            </p>
        </div>

        <div v-else-if="isConnected && isGoogleAds" class="space-y-4">
            <div class="space-y-2">
                <label for="customer_id" class="mb-1 block text-sm font-medium">Google Ads account</label>
                <p class="text-sm text-slate-600">
                    Choose the Ads account Atlas should sync. Labels match the Google Ads workspace picker (manager &gt; client).
                </p>
                <p v-if="!customerOptions.length" class="text-sm text-amber-700">
                    No accounts were loaded. Use Reconnect Google above to refresh your workspace list.
                </p>
                <select
                    v-if="customerOptions.length"
                    id="customer_id"
                    v-model="customerSelection"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                >
                    <option value="">Select an account</option>
                    <option
                        v-for="customer in customerOptions"
                        :key="customerOptionKey(customer)"
                        :value="customerOptionKey(customer)"
                    >
                        {{ customerPickerLabel(customer) }}
                    </option>
                </select>
                <input
                    v-if="!customerOptions.length"
                    id="customer_id_manual"
                    v-model="customerId"
                    type="text"
                    inputmode="numeric"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    placeholder="Enter customer ID (e.g. 1234567890)"
                />
                <p v-else-if="customerSelection" class="text-xs text-slate-500">
                    Customer ID {{ customerId }}{{ loginCustomerId ? ` · Manager ID ${loginCustomerId}` : '' }}
                </p>
                <p v-if="!customerId" class="text-sm text-amber-700">
                    An account is required before you can save or test this connection.
                </p>
            </div>

            <div class="space-y-2">
                <label for="login_customer_id" class="mb-1 block text-sm font-medium">Manager account ID (optional)</label>
                <p class="text-sm text-slate-600">
                    Auto-filled for MCC client accounts. Enter manually only if Google requires manager context.
                </p>
                <input
                    id="login_customer_id"
                    v-model="loginCustomerId"
                    type="text"
                    inputmode="numeric"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    placeholder="9876543210"
                />
            </div>
        </div>

        <p v-else-if="isConnected && isSearchConsole" class="text-sm text-red-600">
            Google signed in, but no Search Console properties were returned for this account.
        </p>

        <p v-else-if="isConnected && isGoogleAnalytics" class="text-sm text-red-600">
            Google signed in, but no GA4 properties were returned for this account.
        </p>

        <div v-else-if="!isConnected && isGoogleAds" class="space-y-2">
            <p class="text-sm text-slate-600">
                Sign in with Google above to load Ads accounts for this dashboard.
            </p>
        </div>
    </div>
</template>
