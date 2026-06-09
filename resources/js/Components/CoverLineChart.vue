<script setup>
import RevenueLineChart from './RevenueLineChart.vue';

defineProps({
    title: {
        type: String,
        default: 'Chart',
    },
    insights: {
        type: String,
        default: '',
    },
    series: {
        type: Array,
        default: () => [],
    },
    color: {
        type: String,
        default: '#0f172a',
    },
    valueFormat: {
        type: String,
        default: 'number',
    },
    seriesLabel: {
        type: String,
        default: '',
    },
    borderless: {
        type: Boolean,
        default: false,
    },
    hideTitle: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div :class="borderless ? '' : 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm'">
        <h2 v-if="!hideTitle" class="mb-4 text-lg font-semibold">{{ title || 'Chart' }}</h2>
        <RevenueLineChart
            :series="series"
            :color="color"
            :value-format="valueFormat"
            :series-label="seriesLabel || title || 'Value'"
        />
        <div
            v-if="insights"
            class="cover-chart-insights mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600"
            v-html="insights"
        />
    </div>
</template>

<style scoped>
.cover-chart-insights :deep(p) {
    margin-bottom: 0.5rem;
}

.cover-chart-insights :deep(p:last-child) {
    margin-bottom: 0;
}

.cover-chart-insights :deep(ul),
.cover-chart-insights :deep(ol) {
    margin: 0.5rem 0;
    padding-left: 1.25rem;
}

.cover-chart-insights :deep(strong) {
    font-weight: 600;
    color: rgb(51 65 85);
}
</style>
