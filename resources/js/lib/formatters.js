const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const integerFormatter = new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0,
});

const decimalFormatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const currencyColumnKeys = new Set([
    'revenue',
    'total',
    'cost',
    'ad_spend',
    'avg_order_value',
    'conversions_value',
    'grand_total',
    'amount',
    'price',
    'spend',
]);

export function formatCurrency(value) {
    return currencyFormatter.format(Number(value ?? 0));
}

export function formatNumber(value) {
    return integerFormatter.format(Number(value ?? 0));
}

export function formatMetric(value) {
    const number = Number(value ?? 0);

    if (! Number.isFinite(number)) {
        return '—';
    }

    if (Number.isInteger(number) || number === Math.trunc(number)) {
        return formatNumber(number);
    }

    return formatDecimal(number);
}

export function formatDecimal(value) {
    return decimalFormatter.format(Number(value ?? 0));
}

export function formatPercent(value, fractionDigits = 2) {
    return `${Number(value ?? 0).toLocaleString('en-US', {
        maximumFractionDigits: fractionDigits,
        minimumFractionDigits: fractionDigits,
    })}%`;
}

export function formatChartValue(value, format = 'number') {
    const number = Number(value ?? 0);

    switch (format) {
        case 'currency':
            return formatCurrency(number);
        case 'percent':
            return formatPercent(number, 1);
        case 'decimal':
            return formatDecimal(number);
        case 'none':
            return formatDecimal(number);
        case 'number':
        default:
            return formatNumber(number);
    }
}

export function isCurrencyColumn(columnOrKey) {
    if (typeof columnOrKey === 'object' && columnOrKey?.format === 'currency') {
        return true;
    }

    const key = typeof columnOrKey === 'string' ? columnOrKey : columnOrKey?.key;

    return currencyColumnKeys.has(key);
}
