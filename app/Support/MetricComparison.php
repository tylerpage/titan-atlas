<?php

namespace App\Support;

class MetricComparison
{
    public static function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            if ($current == 0.0) {
                return 0.0;
            }

            return $current > 0 ? 100.0 : -100.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
