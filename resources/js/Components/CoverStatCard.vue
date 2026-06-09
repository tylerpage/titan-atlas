<script setup>
defineProps({
    header: {
        type: String,
        required: true,
    },
    text: {
        type: String,
        required: true,
    },
    improvementPercent: {
        type: Number,
        default: null,
    },
    tooltip: {
        type: String,
        default: null,
    },
    borderless: {
        type: Boolean,
        default: false,
    },
});

function formatChange(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const prefix = value > 0 ? '+' : '';

    return `${prefix}${Number(value).toFixed(1)}%`;
}

function changeClass(value) {
    if (value === null || value === undefined || value === 0) {
        return 'text-slate-500';
    }

    return value > 0 ? 'text-emerald-600' : 'text-red-600';
}
</script>

<template>
    <div :class="borderless ? '' : 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm'">
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm text-slate-500">{{ header }}</p>
            <span
                v-if="tooltip"
                :title="tooltip"
                class="inline-flex h-5 w-5 shrink-0 cursor-help items-center justify-center rounded-full border border-slate-300 text-xs text-slate-500"
            >
                ?
            </span>
        </div>
        <div class="mt-1 flex flex-wrap items-end gap-3">
            <p class="text-3xl font-semibold">{{ text }}</p>
            <p
                v-if="improvementPercent !== null && improvementPercent !== undefined"
                class="pb-1 text-lg font-medium"
                :class="changeClass(improvementPercent)"
            >
                {{ formatChange(improvementPercent) }}
            </p>
        </div>
    </div>
</template>
