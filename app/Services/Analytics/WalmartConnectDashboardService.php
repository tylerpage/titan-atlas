<?php

namespace App\Services\Analytics;

use App\Models\Connection;
use Carbon\Carbon;

class WalmartConnectDashboardService extends RetailMediaDashboardService
{
    protected function retailMediaKind(): string
    {
        return 'walmart_connect';
    }

    protected function retailMediaConfigKey(): string
    {
        return 'walmart_connect';
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraBreakdowns(Connection $connection, Carbon $start, Carbon $end): array
    {
        return [
            'keywords' => $this->dimensionBreakdown($connection, $start, $end, 'keyword_daily'),
            'page_types' => $this->dimensionBreakdown($connection, $start, $end, 'page_type_daily'),
            'tactics' => $this->dimensionBreakdown($connection, $start, $end, 'tactic_daily'),
            'objectives' => [],
            'placements' => [],
            'devices' => [],
        ];
    }
}
