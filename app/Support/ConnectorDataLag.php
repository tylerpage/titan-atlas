<?php

namespace App\Support;

use Carbon\Carbon;

class ConnectorDataLag
{
    /**
     * @return array{days: int, complete_through: string}
     */
    public static function forConfigKey(string $configKey, int $defaultDays = 0): array
    {
        $days = max(0, (int) config("titan.{$configKey}.data_lag_days", $defaultDays));

        return [
            'days' => $days,
            'complete_through' => now()->subDays($days)->toDateString(),
        ];
    }
}
