<script setup>
import CoverAiBadge from './CoverAiBadge.vue';
import CoverLineChart from './CoverLineChart.vue';
import CoverRichText from './CoverRichText.vue';
import CoverStatCard from './CoverStatCard.vue';
import CoverTable from './CoverTable.vue';

defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    color: {
        type: String,
        default: '#0f172a',
    },
});
</script>

<template>
    <div class="grid gap-4 md:grid-cols-2">
        <template v-for="block in blocks" :key="block.id">
            <div
                :class="[
                    block.column_span === 2 ? 'md:col-span-2' : '',
                    block.ai_report ? 'rounded-xl ring-1 ring-violet-200 ring-inset' : '',
                ]"
            >
                <div
                    v-if="block.ai_report"
                    class="flex flex-wrap items-center gap-2 rounded-t-xl border-b border-violet-100 bg-violet-50/80 px-4 py-2"
                >
                    <CoverAiBadge :prompt="block.ai_report.prompt" />
                    <p class="min-w-0 flex-1 truncate text-xs text-violet-800" :title="block.ai_report.prompt">
                        {{ block.ai_report.prompt }}
                    </p>
                </div>
                <CoverStatCard
                    v-if="block.type === 'stat_card'"
                    :header="block.header"
                    :text="block.text"
                    :improvement-percent="block.improvement_percent"
                    :tooltip="block.tooltip"
                    :class="block.ai_report ? 'rounded-t-none border-t-0' : ''"
                />
                <CoverLineChart
                    v-else-if="block.type === 'line_chart'"
                    :title="block.title"
                    :insights="block.insights ?? ''"
                    :series="block.series"
                    :color="color"
                    :value-format="block.value_format ?? 'number'"
                    :series-label="block.series_label ?? block.title"
                    :class="block.ai_report ? 'rounded-t-none border-t-0' : ''"
                />
                <CoverTable
                    v-else-if="block.type === 'table'"
                    :title="block.title"
                    :columns="block.columns"
                    :rows="block.rows"
                    :filterable="block.filterable"
                    :class="block.ai_report ? 'rounded-t-none border-t-0' : ''"
                />
                <CoverRichText
                    v-else-if="block.type === 'rich_text'"
                    :title="block.title"
                    :body="block.body"
                />
            </div>
        </template>
    </div>
</template>
