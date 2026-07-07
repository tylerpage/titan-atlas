<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { nextTick, reactive, ref, watch } from 'vue';
import AppLayout from '../../../../Layouts/AppLayout.vue';
import CoverAiBadge from '../../../../Components/CoverAiBadge.vue';
import RichTextEditor from '../../../../Components/RichTextEditor.vue';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    coverPage: {
        type: Object,
        required: true,
    },
    connections: {
        type: Array,
        default: () => [],
    },
    blockTypes: {
        type: Array,
        required: true,
    },
    metricKeys: {
        type: Array,
        default: () => [],
    },
    savedReports: {
        type: Array,
        default: () => [],
    },
});

const placeReportForm = useForm({
    cover_page_id: props.coverPage.id,
    column_span: 1,
});

const pageForm = useForm({
    title: props.coverPage.title,
    period_start: props.coverPage.period_start ?? '',
    period_end: props.coverPage.period_end ?? '',
    is_active: props.coverPage.is_active,
    is_draft: props.coverPage.is_draft ?? false,
});

const page = usePage();
const blockFormsById = reactive({});
const highlightedBlockId = ref(null);

function createBlockForm(block) {
    return useForm({
        column_span: block.column_span,
        configuration: { ...block.configuration },
    });
}

function blockForm(block) {
    return blockFormsById[block.id];
}

function syncBlockForms(blocks) {
    const ids = new Set(blocks.map((block) => block.id));

    for (const id of Object.keys(blockFormsById)) {
        if (!ids.has(Number(id))) {
            delete blockFormsById[id];
        }
    }

    for (const block of blocks) {
        if (!blockFormsById[block.id]) {
            blockFormsById[block.id] = createBlockForm(block);
            continue;
        }

        const form = blockFormsById[block.id];

        if (!form.isDirty) {
            form.defaults({
                column_span: block.column_span,
                configuration: { ...block.configuration },
            });
            form.reset();
        }
    }
}

watch(() => props.coverPage.blocks, syncBlockForms, { deep: true, immediate: true });

function focusBlock(blockId) {
    if (!blockId) {
        return;
    }

    highlightedBlockId.value = blockId;

    nextTick(() => {
        const element = document.getElementById(`block-${blockId}`);

        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const focusable = element.querySelector('input:not([type="file"]), textarea, select, [contenteditable="true"]');
            focusable?.focus({ preventScroll: true });
        }

        window.setTimeout(() => {
            if (highlightedBlockId.value === blockId) {
                highlightedBlockId.value = null;
            }
        }, 2500);
    });
}

watch(
    () => page.props.flash?.focused_block_id,
    (blockId) => focusBlock(blockId),
    { immediate: true },
);

function submitPage() {
    pageForm.post(route('admin.cover-pages.update', props.coverPage.id));
}

function addBlock(blockType) {
    router.post(route('admin.cover-pages.blocks.store', props.coverPage.id), {
        block_type: blockType,
    }, {
        preserveScroll: false,
    });
}

function saveBlock(block) {
    const form = blockForm(block);

    if (typeof form.configuration.series === 'string') {
        try {
            form.configuration.series = JSON.parse(form.configuration.series);
        } catch {
            return;
        }
    }

    form.post(route('admin.cover-page-blocks.update', block.id), {
        preserveScroll: true,
    });
}

function deleteBlock(block) {
    if (!confirm('Remove this block?')) {
        return;
    }

    router.delete(route('admin.cover-page-blocks.destroy', block.id), {
        preserveScroll: true,
    });
}

function moveBlock(block, direction) {
    const routeName = direction === 'up' ? 'admin.cover-page-blocks.move-up' : 'admin.cover-page-blocks.move-down';
    router.post(route(routeName, block.id), {}, {
        preserveScroll: true,
    });
}

function importCsv(block, event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    const formData = new FormData();
    formData.append('csv', file);

    router.post(route('admin.cover-page-blocks.import-csv', block.id), formData);
    event.target.value = '';
}

function activatePage() {
    router.post(route('admin.cover-pages.activate', props.coverPage.id));
}

function isAiBlock(block) {
    return block.configuration?.data_source === 'report' || Boolean(block.ai_report);
}

const selectedReportId = ref('');

function placeSavedReport() {
    if (!selectedReportId.value) {
        return;
    }

    placeReportForm.post(route('admin.dashboards.reports.place', [props.dashboard.id, selectedReportId.value]), {
        preserveScroll: false,
    });
}
</script>

<template>
    <AppLayout :title="`Edit ${coverPage.title}`">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">
                    <Link :href="route('admin.dashboards.cover-pages.index', dashboard.id)" class="hover:text-slate-700">
                        {{ dashboard.company_name }} · {{ dashboard.name }}
                    </Link>
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-3xl font-semibold">{{ coverPage.title }}</h1>
                    <span
                        v-if="coverPage.is_draft"
                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900"
                    >
                        Draft
                    </span>
                    <span
                        v-if="coverPage.is_active"
                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
                    >
                        Active
                    </span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-if="!coverPage.is_active"
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    @click="activatePage"
                >
                    Set active
                </button>
                <Link
                    v-if="!coverPage.is_draft"
                    :href="route('client.dashboard.show', { dashboard: dashboard.slug, tab: 'cover', cover_page: coverPage.id })"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                >
                    Preview
                </Link>
            </div>
        </div>

        <form class="mb-10 space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="submitPage">
            <h2 class="text-lg font-semibold">Cover page settings</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">Title</label>
                    <input v-model="pageForm.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Period start</label>
                    <input v-model="pageForm.period_start" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Period end</label>
                    <input v-model="pageForm.period_end" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="pageForm.is_active" type="checkbox" class="rounded border-slate-300" />
                Active cover page
            </label>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="pageForm.is_draft" type="checkbox" class="mt-1 rounded border-slate-300" />
                <span>
                    <span class="font-medium text-slate-900">Draft</span>
                    <span class="mt-1 block text-slate-600">Draft summaries are hidden from the client dashboard until published.</span>
                </span>
            </label>
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-white hover:bg-primary-hover" :disabled="pageForm.processing">
                Save settings
            </button>
        </form>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
            <h2 class="text-lg font-semibold">Blocks</h2>
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="savedReports.length">
                    <select v-model="selectedReportId" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Add from AI report...</option>
                        <option v-for="report in savedReports" :key="report.id" :value="report.id">
                            {{ report.prompt }} ({{ report.visualization_type.replace('_', ' ') }})
                        </option>
                    </select>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50"
                        :disabled="!selectedReportId || placeReportForm.processing"
                        @click="placeSavedReport"
                    >
                        Add report
                    </button>
                </template>
                <Link
                    v-else
                    :href="route('admin.dashboards.reports.ask', dashboard.id)"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                >
                    Create AI report
                </Link>
                <button
                    v-for="type in blockTypes"
                    :key="type.value"
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                    @click="addBlock(type.value)"
                >
                    Add {{ type.label }}
                </button>
            </div>
        </div>

        <TransitionGroup name="block-list" tag="div" class="space-y-6">
            <div
                v-for="block in coverPage.blocks"
                :id="`block-${block.id}`"
                :key="block.id"
                class="rounded-xl border bg-white p-6 shadow-sm transition-shadow"
                :class="[
                    isAiBlock(block) ? 'border-violet-200 ring-1 ring-violet-100' : 'border-slate-200',
                    highlightedBlockId === block.id ? 'ring-2 ring-primary ring-offset-2' : '',
                ]"
            >
                <div
                    v-if="isAiBlock(block)"
                    class="mb-4 rounded-lg border border-violet-100 bg-violet-50 px-4 py-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <CoverAiBadge :prompt="block.ai_report?.prompt" />
                        <Link
                            :href="route('admin.dashboards.reports.index', dashboard.id)"
                            class="text-xs text-violet-700 hover:underline"
                        >
                            View AI reports
                        </Link>
                    </div>
                    <p v-if="block.ai_report?.prompt" class="mt-2 text-sm text-violet-900">
                        {{ block.ai_report.prompt }}
                    </p>
                    <p class="mt-1 text-xs text-violet-600">
                        Report #{{ block.configuration.report_id }} · data refreshes from saved SQL using this cover page's date range
                    </p>
                </div>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-medium">{{ block.block_type_label }}</h3>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs" @click="moveBlock(block, 'up')">Up</button>
                        <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs" @click="moveBlock(block, 'down')">Down</button>
                        <button type="button" class="rounded border border-red-200 px-2 py-1 text-xs text-red-700" @click="deleteBlock(block)">Remove</button>
                    </div>
                </div>

                <div class="mb-4 grid gap-4" :class="block.block_type === 'rich_text' ? 'md:grid-cols-1' : 'md:grid-cols-2'">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Column span</label>
                        <select v-model="blockForm(block).column_span" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option :value="1">1 column</option>
                            <option :value="2">2 columns (full width)</option>
                        </select>
                    </div>
                    <div v-if="block.block_type !== 'rich_text'">
                        <label class="mb-1 block text-sm font-medium">Data source</label>
                        <select
                            v-model="blockForm(block).configuration.data_source"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            :disabled="isAiBlock(block)"
                        >
                            <option value="manual">Manual</option>
                            <option value="metric">Synced metric</option>
                            <option value="report">AI report</option>
                        </select>
                    </div>
                </div>

                <template v-if="block.block_type === 'stat_card'">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Header</label>
                            <input v-model="blockForm(block).configuration.header" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Text</label>
                            <input v-model="blockForm(block).configuration.text" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Improvement %</label>
                            <input v-model.number="blockForm(block).configuration.improvement_percent" type="number" step="0.1" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Tooltip</label>
                            <input v-model="blockForm(block).configuration.tooltip" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                    </div>
                </template>

                <template v-else-if="block.block_type === 'rich_text'">
                    <div class="mb-4">
                        <label class="mb-1 block text-sm font-medium">Title (optional)</label>
                        <input v-model="blockForm(block).configuration.title" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="mb-4">
                        <label class="mb-1 block text-sm font-medium">Content</label>
                        <RichTextEditor v-model="blockForm(block).configuration.body" />
                    </div>
                </template>

                <template v-else-if="block.block_type === 'line_chart'">
                    <div class="mb-4">
                        <label class="mb-1 block text-sm font-medium">Title <span class="text-red-500">*</span></label>
                        <input
                            v-model="blockForm(block).configuration.title"
                            type="text"
                            required
                            placeholder="Chart title"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                    <div class="mb-4">
                        <label class="mb-1 block text-sm font-medium">Insights</label>
                        <p class="mb-2 text-sm text-slate-500">Commentary shown below the chart on the client summary.</p>
                        <RichTextEditor v-model="blockForm(block).configuration.insights" />
                    </div>
                </template>

                <template v-else-if="block.block_type === 'table'">
                    <div class="mb-4">
                        <label class="mb-1 block text-sm font-medium">Title</label>
                        <input v-model="blockForm(block).configuration.title" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                </template>

                <div v-if="block.block_type !== 'rich_text' && blockForm(block).configuration.data_source === 'metric'" class="mb-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Metric</label>
                        <select v-model="blockForm(block).configuration.metric_key" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option v-for="metric in metricKeys" :key="metric.value" :value="metric.value">{{ metric.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Connection</label>
                        <select v-model="blockForm(block).configuration.connection_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option :value="null">Dashboard metrics</option>
                            <option v-for="connection in connections" :key="connection.id" :value="connection.id">
                                {{ connection.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <template v-if="block.block_type === 'line_chart' && blockForm(block).configuration.data_source === 'manual'">
                    <p class="mb-2 text-sm text-slate-500">Manual series JSON or import CSV with date,value columns.</p>
                    <textarea
                        v-model="blockForm(block).configuration.series"
                        rows="4"
                        class="mb-3 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs"
                        placeholder='[{"date":"2025-06-01","value":1200}]'
                        @focus="() => { const form = blockForm(block); if (Array.isArray(form.configuration.series)) form.configuration.series = JSON.stringify(form.configuration.series, null, 2); }"
                    />
                    <input type="file" accept=".csv,text/csv" class="text-sm" @change="importCsv(block, $event)" />
                </template>

                <template v-if="block.block_type === 'table' && blockForm(block).configuration.data_source === 'manual'">
                    <p class="mb-2 text-sm text-slate-500">Import CSV to populate columns and rows.</p>
                    <input type="file" accept=".csv,text/csv" class="text-sm" @change="importCsv(block, $event)" />
                </template>

                <button
                    type="button"
                    class="mt-4 rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50"
                    :disabled="blockForm(block).processing"
                    @click="saveBlock(block)"
                >
                    Save block
                </button>
            </div>
        </TransitionGroup>

        <p v-if="coverPage.blocks.length === 0" class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
            Add stat cards, charts, tables, or rich text to build this summary.
        </p>
    </AppLayout>
</template>

<style scoped>
.block-list-enter-active {
    transition: all 0.35s ease;
}

.block-list-enter-from {
    opacity: 0;
    transform: translateY(-16px);
}

.block-list-move {
    transition: transform 0.35s ease;
}
</style>
