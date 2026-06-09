<script setup>
defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <span
        v-if="show"
        class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800"
        role="status"
        aria-live="polite"
    >
        <span class="relative flex h-2 w-2" aria-hidden="true">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-500 opacity-75" />
            <span class="relative inline-flex h-2 w-2 rounded-full bg-amber-600" />
        </span>
        Syncing data
    </span>
</template>
