<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;

class RedditAdsDashboardService extends GoogleAdsDashboardService
{
    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     * @return array<string, mixed>
     */
    public function dataFor(
        ClientDashboard $dashboard,
        Connection $connection,
        ?string $dateRange = null,
        ?array $customRange = null,
        DateComparison|string|null $comparison = null,
    ): array {
        $data = parent::dataFor($dashboard, $connection, $dateRange, $customRange, $comparison);
        $data['kind'] = 'reddit_ads';

        return $data;
    }
}
