<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class JsonPayloadSql
{
    public static function text(string $column, string $key): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => self::pgsqlText($column, $key),
            default => "json_extract({$column}, '$.{$key}')",
        };
    }

    public static function real(string $column, string $key): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => '('.self::pgsqlText($column, $key).')::double precision',
            default => "cast(json_extract({$column}, '$.{$key}') as real)",
        };
    }

    public static function normalizeSql(string $sql): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $sql;
        }

        return (string) preg_replace_callback(
            "/json_extract\s*\(\s*([a-z_.]+)\s*,\s*'\$\.([^']+)'\s*\)/i",
            fn (array $matches): string => self::pgsqlText($matches[1], $matches[2]),
            $sql,
        );
    }

    public static function promptHint(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "Use payload->>'field' for text JSON fields and (payload->>'field')::double precision for numeric fields (e.g. r.payload->>'date', (r.payload->>'total')::double precision).",
            default => "Use json_extract(payload, '$.field') for JSON fields (e.g. json_extract(r.payload, '$.date')).",
        };
    }

    private static function pgsqlText(string $column, string $key): string
    {
        if (! str_contains($key, '.')) {
            return "{$column}->>'{$key}'";
        }

        $segments = explode('.', $key);

        return "{$column}#>>'{".implode(',', $segments)."}'";
    }
}
