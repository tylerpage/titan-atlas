<?php

namespace App\Ingestion\Connectors;

use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Models\Connection;

class SemrushConnector extends AbstractConnector
{
    /**
     * Phase 1 scope (see config/titan.php semrush.resources):
     * - domain_overview (authority, organic traffic trend)
     * - organic_keywords (position, volume; limited to keyword_limit)
     */
    public function type(): string
    {
        return ConnectorType::Semrush->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['api_key'])) {
            return ValidationResult::fail('SEMrush requires api_key.');
        }

        return ValidationResult::ok();
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        return new FetchResult(records: [], hasMore: false);
    }
}
