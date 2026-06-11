<?php

namespace App\Services\Ingestion;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use App\Ingestion\ConnectorRegistry;
use App\Ingestion\Connectors\Shopify\ShopifyRateLimitException;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Jobs\Ingestion\TransformConnectionDataJob;
use App\Models\Connection;
use App\Services\ConnectorBuilder\ConnectorDashboardSyncCoordinator;
use App\Models\SyncRun;
use App\Support\SyncDateChunkWalker;
use Throwable;

class SyncConnectionService
{
    public function __construct(
        protected ConnectorRegistry $connectors,
        protected RawConnectorPayloadWriter $payloadWriter,
        protected SyncProgressRecorder $progressRecorder,
        protected ConnectorDashboardSyncCoordinator $connectorDashboards,
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
            $this->configureDateWalk($connection, $type);

            if ($type === SyncRunType::Backfill && $connection->backfill_started_at === null) {
                $connection->update(['backfill_started_at' => now()]);
            }

            $connector = $this->connectors->make($connection->connector_type);
            $validation = $connector->validateCredentials($connection);

            if (! $validation->valid) {
                throw new \RuntimeException($validation->message ?? 'Invalid credentials.');
            }

            if (
                $cursor === null
                && $connector instanceof FanOutSyncConnector
                && config('titan.sync.stream_fan_out_enabled', true)
                && count($connector->syncStreams()) > 1
            ) {
                return $this->startFanOutSync($connection, $type, $syncRun, $connector);
            }
        }

        try {
            $connector = $this->connectors->make($connection->connector_type);
            $validation = $connector->validateCredentials($connection);

            if (! $validation->valid) {
                throw new \RuntimeException($validation->message ?? 'Invalid credentials.');
            }

            $initialFetched = $fetched;
            $initialWritten = $written;
            $pagesProcessed = 0;
            $maxPages = max(1, (int) config('titan.sync.pages_per_job', 2));
            $maxSeconds = max(10, (int) config('titan.sync.max_seconds_per_job', 45));
            $startedAt = microtime(true);
            $hasMore = true;

            while ($hasMore && ($cursor !== null || $pagesProcessed === 0)) {
                $result = $connector->fetch($connection, $cursor);
                $fetched += count($result->records);

                foreach ($result->records as $record) {
                    if ($this->payloadWriter->upsert($connection, $syncRun, $record)) {
                        $written++;
                    }
                }

                $chunkDateFrom = $result->chunkDateFrom;
                $chunkDateThrough = $result->chunkDateThrough;
                $cursor = $result->nextCursor;
                $hasMore = $result->hasMore;
                unset($result);

                $this->progressRecorder->recordChunkDates(
                    $syncRun,
                    $connection,
                    $chunkDateFrom,
                    $chunkDateThrough,
                );
                $pagesProcessed++;

                if ($pagesProcessed % 2 === 0) {
                    gc_collect_cycles();
                }

                if (! SyncFanOutCoordinator::isActive($syncRun->id)) {
                    $syncRun->update([
                        'records_fetched' => $fetched,
                        'records_written' => $written,
                    ]);
                }

                $elapsedSeconds = microtime(true) - $startedAt;
                $shouldContinueInNewJob = $hasMore
                    && $cursor !== null
                    && ($pagesProcessed >= $maxPages || $elapsedSeconds >= $maxSeconds);

                if ($shouldContinueInNewJob) {
                    $this->dispatchIncrementalTransform($connection->fresh(), $syncRun);

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

            $deltaFetched = $fetched - $initialFetched;
            $deltaWritten = $written - $initialWritten;
            $remainingStreams = SyncFanOutCoordinator::completeStream($syncRun->id);

            if ($remainingStreams !== null) {
                if ($deltaFetched > 0) {
                    $syncRun->increment('records_fetched', $deltaFetched);
                }

                if ($deltaWritten > 0) {
                    $syncRun->increment('records_written', $deltaWritten);
                }

                if ($remainingStreams > 0) {
                    $this->dispatchIncrementalTransform($connection->fresh(), $syncRun);

                    return $syncRun->fresh();
                }

                SyncFanOutCoordinator::cleanup($syncRun->id);
                $syncRun = $syncRun->fresh();
                $fetched = (int) $syncRun->records_fetched;
                $written = (int) $syncRun->records_written;
            }

            $syncRun->markFinished(SyncStatus::Success, $fetched, $written);
            $connection->markSyncSuccess();
            $this->clearDateWalk($connection);

            if ($type === SyncRunType::Backfill && ! $hasMore) {
                $connection->update(['backfill_completed_at' => now()]);
            }

            $this->dispatchFinalizeTransform($connection->fresh(), $syncRun);
            $this->connectorDashboards->afterSuccessfulSync($connection->fresh(), $syncRun->fresh(), $written);

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
            SyncFanOutCoordinator::cleanup($syncRun->id);
            $syncRun->markFailed($e->getMessage());
            $connection->markSyncFailed($e->getMessage());

            throw $e;
        }
    }

    protected function startFanOutSync(
        Connection $connection,
        SyncRunType $type,
        SyncRun $syncRun,
        FanOutSyncConnector $connector,
    ): SyncRun {
        $streams = $connector->syncStreams();

        SyncFanOutCoordinator::start($syncRun->id, count($streams));

        foreach ($streams as $stream) {
            SyncConnectionJob::dispatch(
                $connection,
                $type,
                $syncRun->id,
                $connector->initialSyncCursor($connection, $stream, true),
            );
        }

        return $syncRun->fresh();
    }

    protected function configureDateWalk(Connection $connection, SyncRunType $type): void
    {
        $settings = $connection->settings ?? [];

        if (SyncDateChunkWalker::shouldWalkBackward($connection, $type)) {
            $settings['date_walk'] = 'backward';
        } else {
            unset($settings['date_walk']);
        }

        $connection->update(['settings' => $settings]);
        $connection->settings = $settings;
    }

    protected function clearDateWalk(Connection $connection): void
    {
        $settings = $connection->settings ?? [];
        unset($settings['date_walk']);

        if ($settings !== ($connection->settings ?? [])) {
            $connection->update(['settings' => $settings]);
        }
    }

    protected function dispatchIncrementalTransform(Connection $connection, SyncRun $syncRun): void
    {
        if (! config('titan.sync.transform_during_sync', true)) {
            return;
        }

        if (
            $syncRun->type === SyncRunType::Backfill
            && $connection->backfill_completed_at === null
            && ! config('titan.sync.transform_during_backfill', false)
        ) {
            return;
        }

        TransformConnectionDataJob::dispatch(
            $syncRun,
            readCursorFromConnection: true,
        );
    }

    protected function dispatchFinalizeTransform(Connection $connection, SyncRun $syncRun): void
    {
        TransformConnectionDataJob::dispatch(
            $syncRun,
            syncRunCatchUp: true,
            readCursorFromConnection: true,
        );
    }
}
