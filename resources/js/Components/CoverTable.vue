<script setup>
import { computed, ref } from 'vue';
import { formatCurrency, formatMetric, formatPercent, isCurrencyColumn } from '../lib/formatters';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
    columns: {
        type: Array,
        default: () => [],
    },
    rows: {
        type: Array,
        default: () => [],
    },
    filterable: {
        type: Boolean,
        default: false,
    },
    borderless: {
        type: Boolean,
        default: false,
    },
});

const filters = ref({});

const filteredRows = computed(() => {
    if (!props.filterable) {
        return props.rows;
    }

    return props.rows.filter((row) => props.columns.every((column) => {
        const filter = (filters.value[column.key] ?? '').trim().toLowerCase();

        if (!filter) {
            return true;
        }

        const value = row[column.key];

        return String(value ?? '').toLowerCase().includes(filter);
    }));
});

function cellValue(row, column) {
    const value = row[column.key];

    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'number') {
        if (isCurrencyColumn(column)) {
            return formatCurrency(value);
        }

        if (column.format === 'percent') {
            return formatPercent(value);
        }

        return formatMetric(value);
    }

    return value;
}
</script>

<template>
    <div :class="borderless ? '' : 'rounded-xl border border-slate-200 bg-white shadow-sm'">
        <div v-if="title" class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-lg font-semibold">{{ title }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-3 font-medium"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                    <tr v-if="filterable">
                        <th
                            v-for="column in columns"
                            :key="`filter-${column.key}`"
                            class="px-4 py-2"
                        >
                            <input
                                v-model="filters[column.key]"
                                type="text"
                                :placeholder="`Filter ${column.label}`"
                                class="w-full rounded border border-slate-200 px-2 py-1 text-xs font-normal"
                            />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in filteredRows"
                        :key="index"
                        class="border-t border-slate-100"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-3 text-slate-600"
                        >
                            {{ cellValue(row, column) }}
                        </td>
                    </tr>
                    <tr v-if="filteredRows.length === 0">
                        <td :colspan="columns.length || 1" class="px-4 py-8 text-center text-slate-500">
                            No data yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
