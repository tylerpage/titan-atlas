<?php

namespace App\Services\Analytics;

use App\Models\Connection;
use Carbon\Carbon;

class AmazonAdsDashboardService extends RetailMediaDashboardService
{
    protected function retailMediaKind(): string
    {
        return 'amazon_ads';
    }

    protected function retailMediaConfigKey(): string
    {
        return 'amazon_ads';
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraBreakdowns(Connection $connection, Carbon $start, Carbon $end): array
    {
        return [
            'ad_types' => $this->dimensionBreakdown($connection, $start, $end, 'ad_type_daily'),
            'keywords' => $this->dimensionBreakdown($connection, $start, $end, 'keyword_daily'),
            'ad_products' => $this->dimensionBreakdown($connection, $start, $end, 'ad_product_daily'),
            'objectives' => $this->dimensionBreakdown($connection, $start, $end, 'ad_type_daily'),
            'placements' => [],
            'devices' => [],
        ];
    }
}
