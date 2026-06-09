<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\ConnectorInterface;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Models\Connection;

abstract class AbstractConnector implements ConnectorInterface
{
    public function validateCredentials(Connection $connection): ValidationResult
    {
        $credentials = $connection->credentials();

        if ($credentials === []) {
            return ValidationResult::fail('Credentials are missing.');
        }

        return $this->validateConnectorCredentials($connection, $credentials);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    abstract protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult;

    abstract public function fetch(Connection $connection, ?string $cursor = null): FetchResult;
}
