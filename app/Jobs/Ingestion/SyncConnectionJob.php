<?php

namespace App\Jobs\Ingestion;

use App\Enums\SyncRunType;
use App\Models\Connection;
use App\Services\Ingestion\SyncConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public ?int $syncRunId = null;

    public ?string $cursor = null;

    public int $fetched = 0;

    public int $written = 0;

    public function __construct(
        public Connection $dashboardConnection,
        public SyncRunType $type = SyncRunType::Incremental,
        ?int $syncRunId = null,
        ?string $cursor = null,
        int $fetched = 0,
        int $written = 0,
    ) {
        $this->syncRunId = $syncRunId;
        $this->cursor = $cursor;
        $this->fetched = $fetched;
        $this->written = $written;
        $this->onQueue(config('titan.queues.ingestion', 'ingestion'));
    }

    public function handle(SyncConnectionService $sync): void
    {
        ini_set('memory_limit', (string) config('titan.sync.memory_limit', '512M'));

        $sync->sync(
            $this->dashboardConnection->fresh(),
            $this->type,
            $this->syncRunId,
            $this->cursor,
            $this->fetched,
            $this->written,
        );
    }
}
