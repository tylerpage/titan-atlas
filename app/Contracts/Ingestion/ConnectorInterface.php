<?php

namespace App\Contracts\Ingestion;

use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Models\Connection;

interface ConnectorInterface
{
    public function type(): string;

    public function validateCredentials(Connection $connection): ValidationResult;

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult;
}
