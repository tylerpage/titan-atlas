<script setup>
import { computed } from 'vue';

const props = defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
    label: {
        type: String,
        default: 'Ad account',
    },
    helpText: {
        type: String,
        default: 'Run Test connection after entering your access token to load this list.',
    },
    inputId: {
        type: String,
        default: 'paid_media_account_id',
    },
});

const accountId = defineModel('accountId', {
    type: String,
    default: '',
});

const hasAccounts = computed(() => (props.accounts ?? []).length > 0);
</script>

<template>
    <div v-if="hasAccounts" class="space-y-2">
        <label :for="inputId" class="mb-1 block text-sm font-medium">{{ label }}</label>
        <select
            :id="inputId"
            v-model="accountId"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2"
        >
            <option value="">Select an account</option>
            <option
                v-for="account in accounts"
                :key="account.accountId"
                :value="account.accountId"
            >
                {{ account.name }} ({{ account.currency }})
            </option>
        </select>
        <p class="text-sm text-slate-500">{{ helpText }}</p>
    </div>
</template>
