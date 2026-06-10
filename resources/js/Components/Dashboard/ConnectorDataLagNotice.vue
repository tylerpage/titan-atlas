<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
});

function formatThroughDate(isoDate) {
    return new Date(`${isoDate}T12:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

const lines = computed(() => props.items.map((item) => {
    const dayLabel = item.days === 1 ? 'day' : 'days';

    return `${item.label} data is usually complete through ${formatThroughDate(item.complete_through)} (${item.days} ${dayLabel} behind today).`;
}));
</script>

<template>
    <div class="mb-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
        <p v-for="(line, index) in lines" :key="index" :class="{ 'mt-1': index > 0 }">
            {{ line }}
        </p>
        <p class="mt-1 text-slate-500">Google finalizes reporting with a short delay, so very recent dates may be incomplete.</p>
    </div>
</template>
