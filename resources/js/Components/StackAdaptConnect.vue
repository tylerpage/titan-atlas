<script setup>
import { computed } from 'vue';

const props = defineProps({
    advertisers: {
        type: Array,
        default: () => [],
    },
});

const advertiserId = defineModel('advertiserId', {
    type: String,
    default: '',
});

const hasAdvertisers = computed(() => (props.advertisers ?? []).length > 0);
</script>

<template>
    <div v-if="hasAdvertisers" class="space-y-2">
        <label for="stackadapt_advertiser_id" class="mb-1 block text-sm font-medium">Advertiser</label>
        <select
            id="stackadapt_advertiser_id"
            v-model="advertiserId"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2"
        >
            <option value="">Select an advertiser</option>
            <option
                v-for="advertiser in advertisers"
                :key="advertiser.advertiserId"
                :value="advertiser.advertiserId"
            >
                {{ advertiser.pickerLabel || advertiser.displayName }}
            </option>
        </select>
        <p class="text-sm text-slate-500">
            One StackAdapt advertiser per connection. Run Test connection after entering your GraphQL API key to load this list.
        </p>
    </div>
</template>
