<?php

namespace App\Support;

class ValueFormatter
{
    public static function currency(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return '$'.number_format((float) $value, 2);
    }

    public static function format(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        $number = (float) $value;

        return match ($format) {
            'currency' => self::currency($number),
            'percent' => number_format($number, 1).'%',
            default => number_format($number, $number == (int) $number ? 0 : 2),
        };
    }
}
