<?php

namespace App\Services\Admin;

use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\ConnectorRegistry;
use App\Models\Connection;

class TestConnectionService
{
    public function __construct(protected ConnectorRegistry $connectors) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function test(ConnectorType $type, array $credentials): ValidationResult
    {
        $connection = new Connection([
            'connector_type' => $type,
            'encrypted_credentials' => array_map(
                fn ($value) => is_string($value) ? trim($value) : $value,
                $credentials,
            ),
        ]);

        return $this->connectors->make($type)->validateCredentials($connection);
    }

    /**
     * @param  array<string, mixed>  $submittedCredentials
     */
    public function testExisting(Connection $connection, array $submittedCredentials): ValidationResult
    {
        $credentials = $connection->credentials();

        foreach ($connection->connector_type->credentialKeys() as $key) {
            $value = $submittedCredentials[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $credentials[$key] = trim($value);
            }
        }

        $connection->encrypted_credentials = $credentials;

        return $this->connectors->make($connection->connector_type)->validateCredentials($connection);
    }
}
