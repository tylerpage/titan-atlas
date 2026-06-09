<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Chart,
    PieController,
    ArcElement,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(PieController, ArcElement, Tooltip, Legend);

const props = defineProps({
    segments: {
        type: Array,
        default: () => [],
    },
    labelKey: {
        type: String,
        default: 'label',
    },
    valueKey: {
        type: String,
        default: 'value',
    },
    colors: {
        type: Array,
        default: () => ['#0f172a', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#94a3b8'],
    },
});

const canvasRef = ref(null);
let chart = null;

const labels = computed(() => props.segments.map((segment) => segment[props.labelKey] ?? ''));
const values = computed(() => props.segments.map((segment) => Number(segment[props.valueKey] ?? 0)));

function renderChart() {
    if (!canvasRef.value || props.segments.length === 0) {
        if (chart) {
            chart.destroy();
            chart = null;
        }

        return;
    }

    if (chart) {
        chart.destroy();
    }

    chart = new Chart(canvasRef.value, {
        type: 'pie',
        data: {
            labels: labels.value,
            datasets: [
                {
                    data: values.value,
                    backgroundColor: props.segments.map((_, index) => props.colors[index % props.colors.length]),
                    borderWidth: 1,
                    borderColor: '#ffffff',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                },
                tooltip: {
                    callbacks: {
                        label(context) {
                            const total = context.dataset.data.reduce((sum, value) => sum + value, 0);
                            const value = context.parsed ?? 0;
                            const share = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';

                            return `${context.label}: ${value.toLocaleString('en-US')} (${share}%)`;
                        },
                    },
                },
            },
        },
    });
}

onMounted(renderChart);

watch(
    () => props.segments,
    renderChart,
    { deep: true },
);

watch(
    () => [props.labelKey, props.valueKey, props.colors.length],
    renderChart,
);

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy();
    }
});
</script>

<template>
    <div class="h-72 w-full">
        <canvas ref="canvasRef" />
    </div>
</template>
