<?php

namespace App\Support;

use App\Enums\SyncRunType;
use App\Models\Connection;
use Carbon\Carbon;

class SyncDateChunkWalker
{
    public static function shouldWalkBackward(Connection $connection, SyncRunType $type): bool
    {
        return $type === SyncRunType::Backfill
            && $connection->backfill_completed_at === null
            && config('titan.sync.backfill_newest_first', true);
    }

    public static function walkForConnection(Connection $connection): string
    {
        return (string) (($connection->settings ?? [])['date_walk'] ?? 'forward');
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    public static function initialState(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        string $walk,
        array $extras = [],
    ): array {
        if ($walk === 'backward') {
            return array_merge($extras, [
                'walk' => 'backward',
                'range_start' => $rangeStart->toDateString(),
                'range_end' => $rangeEnd->toDateString(),
                'chunk_end' => $rangeEnd->toDateString(),
            ]);
        }

        return array_merge($extras, [
            'walk' => 'forward',
            'range_start' => $rangeStart->toDateString(),
            'start_date' => $rangeStart->toDateString(),
            'end_date' => $rangeEnd->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function currentChunkBounds(array $state, int $chunkDays): array
    {
        if (($state['walk'] ?? 'forward') === 'backward') {
            $rangeStart = Carbon::parse((string) $state['range_start']);
            $chunkEnd = Carbon::parse((string) $state['chunk_end']);
            $chunkStart = $chunkEnd->copy()->subDays($chunkDays - 1);

            if ($chunkStart->lt($rangeStart)) {
                $chunkStart = $rangeStart->copy();
            }

            return [$chunkStart, $chunkEnd];
        }

        $start = Carbon::parse((string) $state['start_date']);
        $rangeEnd = Carbon::parse((string) $state['end_date']);
        $chunkEnd = $start->copy()->addDays($chunkDays - 1);

        if ($chunkEnd->gt($rangeEnd)) {
            $chunkEnd = $rangeEnd->copy();
        }

        return [$start, $chunkEnd];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public static function nextDateChunkState(array $state, int $chunkDays): ?array
    {
        [$chunkStart, $chunkEnd] = self::currentChunkBounds($state, $chunkDays);

        if (($state['walk'] ?? 'forward') === 'backward') {
            $rangeStart = Carbon::parse((string) $state['range_start']);
            $nextChunkEnd = $chunkStart->copy()->subDay();

            if ($nextChunkEnd->lt($rangeStart)) {
                return null;
            }

            $next = $state;
            $next['chunk_end'] = $nextChunkEnd->toDateString();
            $next['start_row'] = 0;

            return $next;
        }

        $rangeEnd = Carbon::parse((string) $state['end_date']);
        $nextStart = $chunkEnd->copy()->addDay();

        if ($nextStart->gt($rangeEnd)) {
            return null;
        }

        $next = $state;
        $next['start_date'] = $nextStart->toDateString();
        $next['start_row'] = 0;

        return $next;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: ?string, 1: ?string}
     */
    public static function progressDates(array $state, int $chunkDays): array
    {
        if (! isset($state['walk'])) {
            return [null, null];
        }

        [$chunkStart, $chunkEnd] = self::currentChunkBounds($state, $chunkDays);

        if (($state['walk'] ?? 'forward') === 'backward') {
            return [
                $chunkStart->toDateString(),
                Carbon::parse((string) $state['range_end'])->toDateString(),
            ];
        }

        return [
            Carbon::parse((string) ($state['range_start'] ?? $state['start_date']))->toDateString(),
            $chunkEnd->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function mergeDecodedState(array $state, Connection $connection): array
    {
        if (($state['walk'] ?? '') === 'backward') {
            return $state;
        }

        $state['walk'] = ($state['walk'] ?? '') !== ''
            ? (string) $state['walk']
            : self::walkForConnection($connection);

        return $state;
    }
}
