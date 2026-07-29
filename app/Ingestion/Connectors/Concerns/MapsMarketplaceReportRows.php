<?php

namespace App\Ingestion\Connectors\Concerns;

use Illuminate\Support\Arr;

trait MapsMarketplaceReportRows
{
    /**
     * @param  array<string, mixed>  $row
     */
    protected function mapStandardMetrics(array $row): array
    {
        $cost = $this->firstNumeric($row, [
            'cost', 'spend', 'adSpend', 'ad_spend', 'AD_FEES', 'adFees', 'totalCost',
        ]);
        $clicks = $this->firstNumeric($row, [
            'clicks', 'numAdsClicks', 'click', 'totalClicks',
        ]);
        $impressions = $this->firstNumeric($row, [
            'impressions', 'numAdsShown', 'totalImpressions',
        ]);
        $conversions = $this->firstNumeric($row, [
            'conversions', 'purchases', 'purchases14d', 'attributedOrders', 'attributedOrders14days',
            'orders', 'SALES', 'sales', 'unitsSoldClicks14d',
        ]);
        $conversionsValue = $this->firstNumeric($row, [
            'conversions_value', 'sales', 'sales14d', 'attributedSales', 'attributedSales14days',
            'saleAmount', 'SALE_AMOUNT', 'gmv', 'totalSales',
        ]);

        return [
            'cost' => $cost,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'conversions_value' => $conversionsValue,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 4) : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function mapDate(array $row): string
    {
        $date = $row['date']
            ?? Arr::get($row, 'day')
            ?? Arr::get($row, 'reportDate')
            ?? Arr::get($row, 'startDate');

        if (! is_string($date) || $date === '') {
            return '';
        }

        if (preg_match('/^\d{8}$/', $date) === 1) {
            return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
        }

        return substr($date, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected function firstNumeric(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);

            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }
}
