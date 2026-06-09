<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ConnectorDashboardCache
{
    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     * @return array<string, mixed>
     */
    public function remember(
        Connection $connection,
        string $dateRange,
        ?array $customRange,
        DateComparison $comparison,
        callable $resolver,
    ): array {
        if (! config('titan.dashboard.connector_cache_enabled', true)) {
            return $resolver();
        }

        $ttlSeconds = max(1, (int) config('titan.dashboard.connector_cache_ttl_seconds', 90));
        $cacheKey = $this->cacheKey($connection, $dateRange, $customRange, $comparison);
        $startedAt = microtime(true);

        $data = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), $resolver);

        if (config('app.debug')) {
            $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
            Log::debug('Connector dashboard cache lookup', [
                'connection_id' => $connection->id,
                'cache_key' => $cacheKey,
                'elapsed_ms' => $elapsedMs,
            ]);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     */
    public function cacheKey(
        Connection $connection,
        string $dateRange,
        ?array $customRange,
        DateComparison $comparison,
    ): string {
        $fingerprint = [
            'connection_id' => $connection->id,
            'connector_type' => $connection->connector_type->value,
            'date_range' => $dateRange,
            'custom_range' => $customRange,
            'comparison' => $comparison->value,
            'last_synced_at' => $connection->last_synced_at?->timestamp,
        ];

        return 'connector_dashboard:'.hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }
}
