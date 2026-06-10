<?php

namespace App\Jobs\Ingestion;

use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TransformConnectionDataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout;

    public int $uniqueFor = 3600;

    public ?int $afterPayloadId = null;

    public bool $purgeExisting = false;

    public bool $syncRunCatchUp = false;

    public bool $readCursorFromConnection = false;

    public ?int $catchUpAfterPayloadId = null;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function __construct(
        public SyncRun $syncRun,
        ?int $afterPayloadId = null,
        bool $purgeExisting = false,
        bool $syncRunCatchUp = false,
        bool $readCursorFromConnection = false,
        ?int $catchUpAfterPayloadId = null,
    ) {
        $this->afterPayloadId = $afterPayloadId;
        $this->purgeExisting = $purgeExisting;
        $this->syncRunCatchUp = $syncRunCatchUp;
        $this->readCursorFromConnection = $readCursorFromConnection;
        $this->catchUpAfterPayloadId = $catchUpAfterPayloadId;
        $this->timeout = max(30, (int) config('titan.transform.job_timeout', 55));
        $this->onQueue(config('titan.queues.transform', 'transform'));
    }

    public function uniqueId(): string
    {
        return 'transform-connection-'.$this->syncRun->connection_id;
    }

    public function handle(TransformConnectionDataService $transformer): void
    {
        ini_set('memory_limit', (string) config('titan.transform.memory_limit', '512M'));

        $syncRun = $this->syncRun->fresh(['connection']);
        $afterPayloadId = $this->afterPayloadId;

        if ($this->readCursorFromConnection) {
            $afterPayloadId = $syncRun->connection->last_transformed_payload_id;
        }

        $result = $transformer->transform(
            $syncRun,
            $afterPayloadId,
            $this->purgeExisting,
            $this->syncRunCatchUp,
            $this->catchUpAfterPayloadId,
        );

        if ($result->hasMore && $result->lastPayloadId !== null) {
            self::dispatch(
                $syncRun,
                afterPayloadId: $result->syncRunCatchUp ? $afterPayloadId : $result->lastPayloadId,
                purgeExisting: false,
                syncRunCatchUp: $result->syncRunCatchUp,
                catchUpAfterPayloadId: $result->syncRunCatchUp ? $result->lastPayloadId : null,
            );
        }
    }
}
