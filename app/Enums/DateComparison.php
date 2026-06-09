<?php

namespace App\Enums;

enum DateComparison: string
{
    case None = 'none';
    case PreviousPeriod = 'previous_period';
    case YearOverYear = 'year_over_year';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No comparison',
            self::PreviousPeriod => 'Compare to previous period',
            self::YearOverYear => 'Compare year over year',
        };
    }
}
