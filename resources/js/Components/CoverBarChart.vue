<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Chart,
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend);

const props = defineProps({
    items: {
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
    color: {
        type: String,
        default: '#0f172a',
    },
    horizontal: {
        type: Boolean,
        default: true,
    },
});

const canvasRef = ref(null);
let chart = null;

const labels = computed(() => props.items.map((item) => item[props.labelKey] ?? ''));
const values = computed(() => props.items.map((item) => Number(item[props.valueKey] ?? 0)));

function renderChart() {
    if (!canvasRef.value || props.items.length === 0) {
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
        type: 'bar',
        data: {
            labels: labels.value,
            datasets: [
                {
                    data: values.value,
                    backgroundColor: props.color,
                    borderRadius: 4,
                },
            ],
        },
        options: {
            indexAxis: props.horizontal ? 'y' : 'x',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return Number(context.parsed[props.horizontal ? 'x' : 'y'] ?? 0).toLocaleString('en-US');
                        },
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { display: props.horizontal },
                },
                y: {
                    beginAtZero: true,
                    grid: { display: !props.horizontal },
                },
            },
        },
    });
}

onMounted(renderChart);

watch(
    () => [props.items, props.labelKey, props.valueKey, props.color, props.horizontal],
    renderChart,
    { deep: true },
);

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy();
    }
});
</script>

<template>
    <div class="h-80 w-full">
        <canvas ref="canvasRef" />
    </div>
</template>
