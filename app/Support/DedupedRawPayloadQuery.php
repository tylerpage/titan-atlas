<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DedupedRawPayloadQuery
{
    /**
     * Subquery returning the canonical payload id per external_id group.
     */
    public static function latestIdSubquery(int $connectionId, ?string $resourceType = null): Builder
    {
        $query = DB::table('raw_connector_payloads')
            ->selectRaw('MAX(id) as id')
            ->where('connection_id', $connectionId)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->groupBy('connection_id', 'resource_type', 'external_id');

        if ($resourceType !== null) {
            $query->where('resource_type', $resourceType);
        }

        return $query;
    }

    /**
     * Subquery returning the canonical payload id for legacy null/empty external_id rows.
     */
    public static function latestNullExternalIdSubquery(int $connectionId, ?string $resourceType = null): Builder
    {
        $query = DB::table('raw_connector_payloads')
            ->selectRaw('MAX(id) as id')
            ->where('connection_id', $connectionId)
            ->where(function (Builder $builder): void {
                $builder->whereNull('external_id')->orWhere('external_id', '=', '');
            })
            ->groupBy('connection_id', 'resource_type', 'external_id');

        if ($resourceType !== null) {
            $query->where('resource_type', $resourceType);
        }

        return $query;
    }

    public static function applyToQueryBuilder(Builder $query, int $connectionId, ?string $resourceType = null, string $column = 'id'): Builder
    {
        if (self::usesDirectPostgresFilter()) {
            return self::applyDirectFilter($query, $connectionId, $resourceType, $column);
        }

        return $query->whereIn($column, self::latestIdSubquery($connectionId, $resourceType));
    }

    /**
     * @param  EloquentBuilder<\App\Models\RawConnectorPayload>  $query
     * @return EloquentBuilder<\App\Models\RawConnectorPayload>
     */
    public static function applyToEloquent(EloquentBuilder $query, int $connectionId, ?string $resourceType = null): EloquentBuilder
    {
        if (self::usesDirectPostgresFilter()) {
            return self::applyDirectFilter($query, $connectionId, $resourceType, 'id');
        }

        return $query->whereIn('id', self::latestIdSubquery($connectionId, $resourceType));
    }

    protected static function usesDirectPostgresFilter(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @param  Builder|EloquentBuilder<\App\Models\RawConnectorPayload>  $query
     * @return Builder|EloquentBuilder<\App\Models\RawConnectorPayload>
     */
    protected static function applyDirectFilter(Builder|EloquentBuilder $query, int $connectionId, ?string $resourceType, string $column): Builder|EloquentBuilder
    {
        $query->where('connection_id', $connectionId);

        if ($resourceType !== null) {
            $query->where('resource_type', $resourceType);
        }

        $query->where(function (Builder|EloquentBuilder $outer) use ($connectionId, $resourceType, $column): void {
            $outer->where(function (Builder|EloquentBuilder $withExternalId): void {
                $withExternalId->whereNotNull('external_id')->where('external_id', '!=', '');
            })->orWhereIn($column, self::latestNullExternalIdSubquery($connectionId, $resourceType));
        });

        return $query;
    }
}
