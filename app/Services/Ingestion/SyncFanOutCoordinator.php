<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\Cache;

class SyncFanOutCoordinator
{
    protected static function cacheKey(int $syncRunId): string
    {
        return "sync_fanout:{$syncRunId}:remaining";
    }

    public static function start(int $syncRunId, int $streamCount): void
    {
        Cache::put(self::cacheKey($syncRunId), $streamCount, now()->addDay());
    }

    public static function isActive(int $syncRunId): bool
    {
        return Cache::has(self::cacheKey($syncRunId));
    }

    /**
     * @return int|null Remaining stream jobs after decrement, or null when fan-out is not active.
     */
    public static function completeStream(int $syncRunId): ?int
    {
        if (! self::isActive($syncRunId)) {
            return null;
        }

        return (int) Cache::decrement(self::cacheKey($syncRunId));
    }

    public static function cleanup(int $syncRunId): void
    {
        Cache::forget(self::cacheKey($syncRunId));
    }
}
