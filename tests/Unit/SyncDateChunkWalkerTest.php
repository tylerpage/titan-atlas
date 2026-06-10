<?php

namespace Tests\Unit;

use App\Enums\SyncRunType;
use App\Models\Connection;
use App\Support\SyncDateChunkWalker;
use Carbon\Carbon;
use Tests\TestCase;

class SyncDateChunkWalkerTest extends TestCase
{
    public function test_backfill_uses_newest_first_when_enabled(): void
    {
        config(['titan.sync.backfill_newest_first' => true]);

        $connection = new Connection([
            'backfill_completed_at' => null,
        ]);

        $this->assertTrue(SyncDateChunkWalker::shouldWalkBackward($connection, SyncRunType::Backfill));
    }

    public function test_incremental_sync_stays_forward(): void
    {
        config(['titan.sync.backfill_newest_first' => true]);

        $connection = new Connection([
            'backfill_completed_at' => null,
        ]);

        $this->assertFalse(SyncDateChunkWalker::shouldWalkBackward($connection, SyncRunType::Incremental));
    }

    public function test_backward_walk_starts_at_range_end_and_steps_older(): void
    {
        $state = SyncDateChunkWalker::initialState(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-10'),
            'backward',
            ['stream' => 'search_daily', 'start_row' => 0],
        );

        [$firstStart, $firstEnd] = SyncDateChunkWalker::currentChunkBounds($state, 3);
        $this->assertSame('2025-01-08', $firstStart->toDateString());
        $this->assertSame('2025-01-10', $firstEnd->toDateString());

        $next = SyncDateChunkWalker::nextDateChunkState($state, 3);
        $this->assertNotNull($next);

        [$secondStart, $secondEnd] = SyncDateChunkWalker::currentChunkBounds($next, 3);
        $this->assertSame('2025-01-05', $secondStart->toDateString());
        $this->assertSame('2025-01-07', $secondEnd->toDateString());
    }

    public function test_forward_walk_starts_at_range_start(): void
    {
        $state = SyncDateChunkWalker::initialState(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-10'),
            'forward',
            ['stream' => 'search_daily', 'start_row' => 0],
        );

        [$start, $end] = SyncDateChunkWalker::currentChunkBounds($state, 3);
        $this->assertSame('2025-01-01', $start->toDateString());
        $this->assertSame('2025-01-03', $end->toDateString());
    }
}
