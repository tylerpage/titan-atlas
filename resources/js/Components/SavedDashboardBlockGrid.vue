<script setup>
import SavedDashboardWidget from './SavedDashboardWidget.vue';

defineProps({
    blocks: {
        type: Array,
        default: () => [],
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

const emit = defineEmits(['view']);
</script>

<template>
    <div class="grid gap-4 md:grid-cols-2">
        <div
            v-for="block in blocks"
            :key="block.id"
            :class="block.column_span === 2 ? 'md:col-span-2' : ''"
        >
            <SavedDashboardWidget
                :block="block"
                :color="color"
                :show-view-action="showViewAction"
                @view="emit('view', block)"
            />
        </div>
    </div>
</template>
