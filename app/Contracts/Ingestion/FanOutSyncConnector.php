<?php

namespace App\Contracts\Ingestion;

use App\Models\Connection;

interface FanOutSyncConnector extends ConnectorInterface
{
    /**
     * @return list<string>
     */
    public function syncStreams(): array;

    public function initialSyncCursor(Connection $connection, string $stream, bool $fanOut = false): string;
}
