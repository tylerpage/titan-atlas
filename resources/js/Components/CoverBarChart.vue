<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart, ensureChartJsRegistered } from '../lib/chartSetup';
import { formatChartValue } from '../lib/formatters';

ensureChartJsRegistered();

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

function buildChartConfig() {
    return {
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
                            return formatChartValue(
                                context.parsed[props.horizontal ? 'x' : 'y'] ?? 0,
                                'number',
                            );
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
    };
}

function syncChart() {
    if (!canvasRef.value || props.items.length === 0) {
        if (chart) {
            chart.destroy();
            chart = null;
        }

        return;
    }

    if (!chart) {
        chart = new Chart(canvasRef.value, buildChartConfig());

        return;
    }

    chart.data.labels = labels.value;
    chart.data.datasets[0].data = values.value;
    chart.data.datasets[0].backgroundColor = props.color;
    chart.options.indexAxis = props.horizontal ? 'y' : 'x';
    chart.options.scales.x.grid.display = props.horizontal;
    chart.options.scales.y.grid.display = !props.horizontal;
    chart.update('none');
}

onMounted(syncChart);

watch(
    () => [props.items, props.labelKey, props.valueKey, props.color, props.horizontal],
    syncChart,
    { deep: true },
);

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy();
        chart = null;
    }
});
</script>

<template>
    <div class="h-80 w-full">
        <canvas ref="canvasRef" />
    </div>
</template>
