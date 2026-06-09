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

    public static function applyToQueryBuilder(Builder $query, int $connectionId, ?string $resourceType = null, string $column = 'id'): Builder
    {
        return $query->whereIn($column, self::latestIdSubquery($connectionId, $resourceType));
    }

    /**
     * @param  EloquentBuilder<\App\Models\RawConnectorPayload>  $query
     * @return EloquentBuilder<\App\Models\RawConnectorPayload>
     */
    public static function applyToEloquent(EloquentBuilder $query, int $connectionId, ?string $resourceType = null): EloquentBuilder
    {
        return $query->whereIn('id', self::latestIdSubquery($connectionId, $resourceType));
    }
}
