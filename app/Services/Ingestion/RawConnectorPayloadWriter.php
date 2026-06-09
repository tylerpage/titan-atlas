<?php

namespace App\Services\Ingestion;

use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;

class RawConnectorPayloadWriter
{
    /**
     * @param  array{resource_type: string, external_id?: string|null, payload: array<string, mixed>}  $record
     */
    public function upsert(Connection $connection, SyncRun $syncRun, array $record): bool
    {
        $externalId = $record['external_id'] ?? null;
        $payloadHash = hash('sha256', json_encode($record['payload']));

        if ($externalId === null || $externalId === '') {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
                'resource_type' => $record['resource_type'],
                'external_id' => null,
                'payload' => $record['payload'],
                'payload_hash' => $payloadHash,
                'fetched_at' => now(),
            ]);

            return true;
        }

        $existing = RawConnectorPayload::query()
            ->where('connection_id', $connection->id)
            ->where('resource_type', $record['resource_type'])
            ->where('external_id', $externalId)
            ->first();

        if ($existing && $existing->payload_hash === $payloadHash) {
            return false;
        }

        RawConnectorPayload::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'resource_type' => $record['resource_type'],
                'external_id' => $externalId,
            ],
            [
                'sync_run_id' => $syncRun->id,
                'payload' => $record['payload'],
                'payload_hash' => $payloadHash,
                'fetched_at' => now(),
            ],
        );

        return true;
    }
}
