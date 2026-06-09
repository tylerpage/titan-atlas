<?php

namespace App\Support;

class MetricDimensions
{
    /**
     * @param  array<string, mixed>|null  $dimensions
     */
    public static function hash(?array $dimensions): string
    {
        if ($dimensions === null || $dimensions === []) {
            return hash('sha256', '{}');
        }

        $normalized = self::normalize($dimensions);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    public static function normalize(array $dimensions): array
    {
        ksort($dimensions);

        foreach ($dimensions as $key => $value) {
            if (is_array($value)) {
                $dimensions[$key] = self::normalize($value);
            }
        }

        return $dimensions;
    }
}
