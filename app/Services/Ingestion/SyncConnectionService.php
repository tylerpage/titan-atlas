<?php

namespace App\Services\Ingestion;

use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use App\Ingestion\ConnectorRegistry;
use App\Ingestion\Connectors\Shopify\ShopifyRateLimitException;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Jobs\Ingestion\TransformConnectionDataJob;
use App\Models\Connection;
use App\Models\SyncRun;
use Throwable;

class SyncConnectionService
{
    public function __construct(
        protected ConnectorRegistry $connectors,
        protected RawConnectorPayloadWriter $payloadWriter,
    ) {}

    public function sync(
        Connection $connection,
        SyncRunType $type = SyncRunType::Incremental,
        ?int $syncRunId = null,
        ?string $cursor = null,
        int $fetched = 0,
        int $written = 0,
    ): SyncRun {
        if ($syncRunId) {
            $syncRun = SyncRun::query()->findOrFail($syncRunId);
        } else {
            $syncRun = $connection->syncRuns()->create([
                'type' => $type,
                'status' => SyncStatus::Pending,
            ]);

            $connection->markSyncRunning();
            $syncRun->markRunning();

            if ($type === SyncRunType::Backfill && $connection->backfill_started_at === null) {
                $connection->update(['backfill_started_at' => now()]);
            }
        }

        try {
            $connector = $this->connectors->make($connection->connector_type);
            $validation = $connector->validateCredentials($connection);

            if (! $validation->valid) {
                throw new \RuntimeException($validation->message ?? 'Invalid credentials.');
            }

            $pagesProcessed = 0;
            $maxPages = max(1, (int) config('titan.sync.pages_per_job', 20));
            $hasMore = true;

            while ($hasMore && ($cursor !== null || $pagesProcessed === 0)) {
                $result = $connector->fetch($connection, $cursor);
                $fetched += count($result->records);

                foreach ($result->records as $record) {
                    if ($this->payloadWriter->upsert($connection, $syncRun, $record)) {
                        $written++;
                    }
                }

                $cursor = $result->nextCursor;
                $hasMore = $result->hasMore;
                unset($result);
                $pagesProcessed++;

                if ($pagesProcessed % 2 === 0) {
                    gc_collect_cycles();
                }

                $syncRun->update([
                    'records_fetched' => $fetched,
                    'records_written' => $written,
                ]);

                if ($pagesProcessed >= $maxPages && $hasMore && $cursor !== null) {
                    SyncConnectionJob::dispatch(
                        $connection,
                        $type,
                        $syncRun->id,
                        $cursor,
                        $fetched,
                        $written,
                    );

                    return $syncRun->fresh();
                }
            }

            $syncRun->markFinished(SyncStatus::Success, $fetched, $written);
            $connection->markSyncSuccess();

            if ($type === SyncRunType::Backfill && ! $hasMore) {
                $connection->update(['backfill_completed_at' => now()]);
            }

            TransformConnectionDataJob::dispatch($syncRun);

            return $syncRun->fresh();
        } catch (ShopifyRateLimitException $e) {
            $syncRun->update([
                'records_fetched' => $fetched,
                'records_written' => $written,
            ]);

            $delaySeconds = max(
                $e->retryAfterSeconds,
                (int) config('titan.shopify.rate_limit.job_retry_delay_seconds', 120),
            );

            SyncConnectionJob::dispatch(
                $connection,
                $type,
                $syncRun->id,
                $cursor,
                $fetched,
                $written,
            )->delay(now()->addSeconds($delaySeconds));

            return $syncRun->fresh();
        } catch (Throwable $e) {
            $syncRun->markFailed($e->getMessage());
            $connection->markSyncFailed($e->getMessage());

            throw $e;
        }
    }
}
