<script setup>
import { computed } from 'vue';

const props = defineProps({
    adAccounts: {
        type: Array,
        default: () => [],
    },
});

const adAccountId = defineModel('adAccountId', {
    type: String,
    default: '',
});

const hasAdAccounts = computed(() => (props.adAccounts ?? []).length > 0);
</script>

<template>
    <div v-if="hasAdAccounts" class="space-y-2">
        <label for="meta_ad_account_id" class="mb-1 block text-sm font-medium">Ad account</label>
        <select
            id="meta_ad_account_id"
            v-model="adAccountId"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2"
        >
            <option value="">Select an ad account</option>
            <option
                v-for="account in adAccounts"
                :key="account.adAccountId"
                :value="account.adAccountId"
            >
                {{ account.name }} ({{ account.currency }})
            </option>
        </select>
        <p class="text-sm text-slate-500">
            One Meta ad account per connection. Run Test connection after entering your access token to load this list.
        </p>
    </div>
</template>
