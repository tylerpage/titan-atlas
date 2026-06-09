<script setup>
defineProps({
    label: {
        type: String,
        required: true,
    },
    forId: {
        type: String,
        required: true,
    },
    help: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <div class="mb-1 flex items-center gap-1.5">
        <label :for="forId" class="text-sm font-medium">{{ label }}</label>
        <span v-if="help" class="group relative inline-flex">
            <button
                type="button"
                class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 text-[10px] font-semibold leading-none text-slate-500 hover:border-slate-400 hover:bg-slate-50 hover:text-slate-700"
                :aria-label="`${label} help`"
            >
                ?
            </button>
            <span
                role="tooltip"
                class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden w-72 max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-lg border border-slate-200 bg-white p-3 text-left text-xs font-normal leading-relaxed text-slate-600 shadow-lg group-hover:block group-focus-within:block"
            >
                {{ help }}
            </span>
        </span>
    </div>
</template>
