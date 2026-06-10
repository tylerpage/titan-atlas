<?php

namespace App\Ingestion\Connectors\Concerns;

use App\Data\Ingestion\FetchResult;
use App\Models\Connection;
use App\Support\SyncDateChunkWalker;
use Carbon\Carbon;

trait WalksSyncDateChunks
{
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function nextStreamCursorState(Connection $connection, string $nextStream, array $state): array
    {
        [$rangeStart, $rangeEnd] = $this->resolveDateRange($connection);
        $walk = (string) ($state['walk'] ?? SyncDateChunkWalker::walkForConnection($connection));

        return SyncDateChunkWalker::initialState(
            $rangeStart,
            $rangeEnd,
            $walk,
            [
                'stream' => $nextStream,
                'start_row' => 0,
                'fan_out' => (bool) ($state['fan_out'] ?? false),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function chunkProgressDates(array $state, int $chunkDays): array
    {
        return SyncDateChunkWalker::progressDates($state, $chunkDays);
    }

    /**
     * @param  list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>  $records
     */
    protected function result(
        array $records,
        ?string $nextCursor,
        bool $hasMore,
        ?string $chunkDateFrom = null,
        ?string $chunkDateThrough = null,
    ): FetchResult {
        return new FetchResult(
            records: $records,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            chunkDateFrom: $chunkDateFrom,
            chunkDateThrough: $chunkDateThrough,
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    abstract protected function resolveDateRange(Connection $connection): array;
}
