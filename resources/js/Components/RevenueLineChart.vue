<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart, ensureChartJsRegistered } from '../lib/chartSetup';
import { formatChartValue } from '../lib/formatters';

ensureChartJsRegistered();

const props = defineProps({
    series: {
        type: Array,
        default: () => [],
    },
    comparisonSeries: {
        type: Array,
        default: () => [],
    },
    color: {
        type: String,
        default: '#0f172a',
    },
    comparing: {
        type: Boolean,
        default: false,
    },
    valueFormat: {
        type: String,
        default: 'currency',
        validator: (value) => ['currency', 'number', 'percent', 'none'].includes(value),
    },
    seriesLabel: {
        type: String,
        default: 'Revenue',
    },
    comparisonSeriesLabel: {
        type: String,
        default: 'Previous period',
    },
});

function formatValue(value) {
    return formatChartValue(value, props.valueFormat);
}

const canvasRef = ref(null);
let chart = null;

const labels = computed(() => {
    const dates = new Set();

    props.series.forEach((point) => dates.add(point.date));

    if (props.comparing) {
        props.comparisonSeries.forEach((point) => dates.add(point.date));
    }

    return [...dates].sort();
});

function valuesForSeries(series) {
    const byDate = Object.fromEntries(series.map((point) => [point.date, point.value]));

    return labels.value.map((date) => byDate[date] ?? null);
}

const datasets = computed(() => {
    const items = [
        {
            label: props.seriesLabel,
            data: valuesForSeries(props.series),
            borderColor: props.color,
            backgroundColor: `${props.color}22`,
            fill: true,
            tension: 0.3,
            pointRadius: 2,
            pointHoverRadius: 4,
        },
    ];

    if (props.comparing && props.comparisonSeries.length > 0) {
        items.push({
            label: props.comparisonSeriesLabel,
            data: valuesForSeries(props.comparisonSeries),
            borderColor: '#94a3b8',
            backgroundColor: 'transparent',
            borderDash: [6, 4],
            fill: false,
            tension: 0.3,
            pointRadius: 0,
        });
    }

    return items;
});

function buildChartConfig() {
    return {
        type: 'line',
        data: {
            labels: labels.value,
            datasets: datasets.value,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    display: props.comparing && props.comparisonSeries.length > 0,
                },
                tooltip: {
                    callbacks: {
                        label(context) {
                            return `${context.dataset.label}: ${formatValue(context.parsed.y)}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        maxTicksLimit: 8,
                    },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback(value) {
                            return formatValue(value);
                        },
                    },
                },
            },
        },
    };
}

function syncChart() {
    if (!canvasRef.value) {
        return;
    }

    if (!chart) {
        chart = new Chart(canvasRef.value, buildChartConfig());

        return;
    }

    chart.data.labels = labels.value;
    chart.data.datasets = datasets.value;
    chart.options.plugins.legend.display = props.comparing && props.comparisonSeries.length > 0;
    chart.update('none');
}

onMounted(syncChart);

watch(
    () => [props.series, props.comparisonSeries, props.comparing, props.color, props.valueFormat, props.seriesLabel],
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
    <div class="h-72 w-full">
        <canvas ref="canvasRef" />
    </div>
</template>
