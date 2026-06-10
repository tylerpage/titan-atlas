<?php

namespace App\Services\Ingestion;

use App\Models\Connection;
use App\Models\SyncRun;

class SyncProgressRecorder
{
    public function recordChunkDates(
        SyncRun $syncRun,
        Connection $connection,
        ?string $fromDate,
        ?string $throughDate,
    ): void {
        if ($fromDate === null && $throughDate === null) {
            return;
        }

        $syncRun->refresh();
        $connection->refresh();

        $syncUpdates = [];

        if ($fromDate !== null) {
            $syncUpdates['progress_from_date'] = $syncRun->progress_from_date === null
                ? $fromDate
                : min($syncRun->progress_from_date->toDateString(), $fromDate);
        }

        if ($throughDate !== null) {
            $syncUpdates['progress_through_date'] = $syncRun->progress_through_date === null
                ? $throughDate
                : max($syncRun->progress_through_date->toDateString(), $throughDate);
        }

        if ($syncUpdates !== []) {
            $syncRun->update($syncUpdates);
        }

        $connectionUpdates = [];

        if ($fromDate !== null) {
            $connectionUpdates['data_from_date'] = $connection->data_from_date === null
                ? $fromDate
                : min($connection->data_from_date->toDateString(), $fromDate);
        }

        if ($throughDate !== null) {
            $connectionUpdates['data_through_date'] = $connection->data_through_date === null
                ? $throughDate
                : max($connection->data_through_date->toDateString(), $throughDate);
        }

        if ($connectionUpdates !== []) {
            $connection->update($connectionUpdates);
        }
    }
}
