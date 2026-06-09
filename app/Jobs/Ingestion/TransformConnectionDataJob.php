<?php

namespace App\Jobs\Ingestion;

use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TransformConnectionDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public SyncRun $syncRun)
    {
        $this->onQueue(config('titan.queues.transform', 'transform'));
    }

    public function handle(TransformConnectionDataService $transformer): void
    {
        $transformer->transform($this->syncRun->fresh());
    }
}
