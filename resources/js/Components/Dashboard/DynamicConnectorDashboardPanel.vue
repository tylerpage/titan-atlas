<script setup>
import SavedDashboardBlockGrid from '../SavedDashboardBlockGrid.vue';

defineProps({
    connectorData: {
        type: Object,
        required: true,
    },
    connectionName: {
        type: String,
        default: '',
    },
    primaryColor: {
        type: String,
        default: '#0f172a',
    },
});
</script>

<template>
    <section>
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-slate-900">
                {{ connectorData.title || connectionName }}
            </h2>
            <p v-if="connectorData.description" class="mt-1 text-sm text-slate-600">
                {{ connectorData.description }}
            </p>
        </div>

        <SavedDashboardBlockGrid
            v-if="connectorData.blocks?.length"
            :blocks="connectorData.blocks"
            :color="primaryColor"
        />

        <div
            v-else
            class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm"
        >
            <p class="text-sm text-slate-500">
                Analytics widgets are configured, but no data is available for this date range yet.
            </p>
        </div>
    </section>
</template>
