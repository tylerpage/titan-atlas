<?php

namespace App\Services\Analytics;

use Carbon\Carbon;

class ReportQueryContext
{
    public function __construct(
        public int $dashboardId,
        public Carbon $startDate,
        public Carbon $endDate,
        public ?Carbon $compareStartDate = null,
        public ?Carbon $compareEndDate = null,
        public ?int $connectionId = null,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function bindings(): array
    {
        $bindings = [
            'dashboard_id' => $this->dashboardId,
            'start_date' => $this->startDate->toDateString(),
            'end_date' => $this->endDate->toDateString(),
        ];

        if ($this->compareStartDate && $this->compareEndDate) {
            $bindings['compare_start_date'] = $this->compareStartDate->toDateString();
            $bindings['compare_end_date'] = $this->compareEndDate->toDateString();
        }

        if ($this->connectionId) {
            $bindings['connection_id'] = $this->connectionId;
        }

        return $bindings;
    }
}
