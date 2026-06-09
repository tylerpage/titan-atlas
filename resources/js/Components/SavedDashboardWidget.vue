<script setup>
import { computed } from 'vue';
import CoverAiBadge from './CoverAiBadge.vue';
import CoverLineChart from './CoverLineChart.vue';
import CoverStatCard from './CoverStatCard.vue';
import CoverTable from './CoverTable.vue';

const props = defineProps({
    block: {
        type: Object,
        required: true,
    },
    color: {
        type: String,
        default: '#0f172a',
    },
    showViewAction: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['view']);

const visualizationType = computed(() => props.block.visualization_type ?? props.block.type);

const widgetTitle = computed(() => props.block.title
    ?? props.block.header
    ?? props.block.ai_report?.prompt
    ?? 'Visual');
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <div class="flex items-start gap-2">
                <h3 class="min-w-0 flex-1 text-lg font-semibold text-slate-900">
                    {{ widgetTitle }}
                </h3>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        v-if="showViewAction"
                        type="button"
                        class="rounded-lg border border-slate-200 p-1.5 text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700"
                        title="View board"
                        aria-label="View board"
                        @click="$emit('view')"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1 1 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </button>
                    <CoverAiBadge compact />
                </div>
            </div>
            <p v-if="block.description" class="mt-1 text-sm text-slate-600">
                {{ block.description }}
            </p>
        </div>

        <div class="p-5 pt-4">
            <CoverStatCard
                v-if="visualizationType === 'stat_card'"
                borderless
                :header="block.header"
                :text="block.text"
                :improvement-percent="block.improvement_percent"
                :tooltip="block.tooltip"
            />
            <CoverLineChart
                v-else-if="visualizationType === 'line_chart'"
                borderless
                hide-title
                :title="block.title"
                :insights="block.insights ?? ''"
                :series="block.series ?? []"
                :color="color"
                :value-format="block.value_format ?? 'number'"
                :series-label="block.series_label ?? block.title"
            />
            <CoverTable
                v-else-if="visualizationType === 'table'"
                borderless
                :columns="block.columns ?? []"
                :rows="block.rows ?? []"
                :filterable="block.filterable ?? true"
            />
        </div>
    </div>
</template>
