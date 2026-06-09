<script setup>
import CoverAiBadge from './CoverAiBadge.vue';
import CoverLineChart from './CoverLineChart.vue';
import CoverStatCard from './CoverStatCard.vue';
import CoverTable from './CoverTable.vue';

const props = defineProps({
    visualizationType: {
        type: String,
        required: true,
    },
    payload: {
        type: Object,
        required: true,
    },
    prompt: {
        type: String,
        default: '',
    },
    color: {
        type: String,
        default: '#0f172a',
    },
    embedded: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
        :class="embedded ? 'mt-3' : ''"
    >
        <div
            v-if="prompt"
            class="flex flex-wrap items-center gap-2 border-b border-violet-100 bg-violet-50/80 px-4 py-2"
        >
            <CoverAiBadge :prompt="prompt" />
            <p class="min-w-0 flex-1 truncate text-xs text-violet-800" :title="prompt">
                {{ prompt }}
            </p>
        </div>

        <div class="p-1">
            <CoverStatCard
                v-if="visualizationType === 'stat_card'"
                :header="payload.header"
                :text="payload.text"
                :improvement-percent="payload.improvement_percent"
                :tooltip="payload.tooltip"
                :class="prompt ? 'rounded-t-none border-t-0 shadow-none' : ''"
            />
            <CoverLineChart
                v-else-if="visualizationType === 'line_chart'"
                :title="payload.title"
                :insights="payload.insights ?? ''"
                :series="payload.series ?? []"
                :color="color"
                :value-format="payload.value_format ?? 'number'"
                :series-label="payload.series_label ?? payload.title"
                :class="prompt ? 'rounded-t-none border-t-0 shadow-none' : ''"
            />
            <CoverTable
                v-else-if="visualizationType === 'table'"
                :title="payload.title"
                :columns="payload.columns ?? []"
                :rows="payload.rows ?? []"
                :filterable="payload.filterable ?? true"
                :class="prompt ? 'rounded-t-none border-t-0 shadow-none' : ''"
            />
        </div>
    </div>
</template>
