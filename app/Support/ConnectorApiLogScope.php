<?php

namespace App\Support;

class ConnectorApiLogScope
{
    /** @var array<string, mixed>|null */
    protected static ?array $current = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function run(array $attributes, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = array_merge($previous ?? [], $attributes);

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }
}
