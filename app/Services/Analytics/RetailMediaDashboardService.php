<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use Carbon\Carbon;

abstract class RetailMediaDashboardService extends MetaAdsDashboardService
{
    abstract protected function retailMediaKind(): string;

    abstract protected function retailMediaConfigKey(): string;

    protected function paidMediaDashboardKind(): string
    {
        return $this->retailMediaKind();
    }

    protected function paidMediaDashboardConfigKey(): string
    {
        return $this->retailMediaConfigKey();
    }

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
        [$start, $end] = $this->widgets->resolveDateRange($dashboard, $dateRange, $customRange);
        $data = parent::dataFor($dashboard, $connection, $dateRange, $customRange, $comparison);

        return array_merge($data, $this->extraBreakdowns($connection, $start, $end));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraBreakdowns(Connection $connection, Carbon $start, Carbon $end): array
    {
        return [];
    }
}
