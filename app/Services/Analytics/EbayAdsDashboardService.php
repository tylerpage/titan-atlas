<?php

namespace App\Services\Analytics;

use App\Models\Connection;
use Carbon\Carbon;

class EbayAdsDashboardService extends RetailMediaDashboardService
{
    protected function retailMediaKind(): string
    {
        return 'ebay_ads';
    }

    protected function retailMediaConfigKey(): string
    {
        return 'ebay_ads';
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraBreakdowns(Connection $connection, Carbon $start, Carbon $end): array
    {
        return [
            'listings' => $this->dimensionBreakdown($connection, $start, $end, 'listing_daily'),
            'keywords' => $this->dimensionBreakdown($connection, $start, $end, 'keyword_daily'),
            'objectives' => [],
            'placements' => [],
            'devices' => [],
        ];
    }
}
