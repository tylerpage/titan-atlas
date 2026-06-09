<script setup>
import { computed } from 'vue';

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
        default: () => ({ connected: false, sites: [] }),
    },
});

const siteUrl = defineModel('siteUrl', { type: String, default: '' });

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

const siteOptions = computed(() => props.googleOauth?.sites ?? []);
const isConnected = computed(() => Boolean(props.googleOauth?.connected));
</script>

<template>
    <div class="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-slate-900">Google account</p>
                <p class="text-sm text-slate-600">
                    {{
                        isConnected
                            ? 'Connected. Choose a Search Console property below.'
                            : 'Sign in with Google to authorize read-only Search Console access.'
                    }}
                </p>
            </div>
            <a
                :href="oauthUrl"
                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
                {{ isConnected ? 'Reconnect Google' : 'Connect with Google' }}
            </a>
        </div>

        <div v-if="isConnected && siteOptions.length">
            <label for="site_url" class="mb-1 block text-sm font-medium">Search Console property</label>
            <select
                id="site_url"
                v-model="siteUrl"
                required
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
            >
                <option value="" disabled>Select a property</option>
                <option v-for="site in siteOptions" :key="site.siteUrl" :value="site.siteUrl">
                    {{ site.siteUrl }} ({{ site.permissionLevel }})
                </option>
            </select>
        </div>
    </div>
</template>
