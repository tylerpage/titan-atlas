<?php

namespace App\Services\Ingestion;

use App\Models\Connection;
use App\Models\RawConnectorPayload;
use Illuminate\Support\Facades\DB;

class RawConnectorPayloadDeduper
{
    public function dedupeForConnection(Connection $connection): int
    {
        $idsToKeep = DB::table('raw_connector_payloads')
            ->selectRaw('MAX(id) as id')
            ->where('connection_id', $connection->id)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->groupBy('connection_id', 'resource_type', 'external_id')
            ->pluck('id');

        if ($idsToKeep->isEmpty()) {
            return 0;
        }

        return RawConnectorPayload::query()
            ->where('connection_id', $connection->id)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }

    public function dedupeAll(): int
    {
        $deleted = 0;

        Connection::query()
            ->orderBy('id')
            ->each(function (Connection $connection) use (&$deleted): void {
                $deleted += $this->dedupeForConnection($connection);
            });

        return $deleted;
    }
}
