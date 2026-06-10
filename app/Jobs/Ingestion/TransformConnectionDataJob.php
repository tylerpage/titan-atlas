<?php

namespace App\Jobs\Ingestion;

use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TransformConnectionDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout;

    public ?int $afterPayloadId = null;

    public bool $purgeExisting = true;

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
        bool $purgeExisting = true,
    ) {
        $this->afterPayloadId = $afterPayloadId;
        $this->purgeExisting = $purgeExisting;
        $this->timeout = max(30, (int) config('titan.transform.job_timeout', 55));
        $this->onQueue(config('titan.queues.transform', 'transform'));
    }

    public function handle(TransformConnectionDataService $transformer): void
    {
        ini_set('memory_limit', (string) config('titan.transform.memory_limit', '512M'));

        $result = $transformer->transform(
            $this->syncRun->fresh(),
            $this->afterPayloadId,
            $this->purgeExisting,
        );

        if ($result->hasMore && $result->lastPayloadId !== null) {
            self::dispatch($this->syncRun, $result->lastPayloadId, purgeExisting: false);
        }
    }
}
