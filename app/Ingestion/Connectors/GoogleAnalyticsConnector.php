<?php

namespace App\Ingestion\Connectors;

use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Models\Connection;

class GoogleAnalyticsConnector extends AbstractConnector
{
    public function type(): string
    {
        return ConnectorType::GoogleAnalytics->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['property_id'])) {
            return ValidationResult::fail('Google Analytics requires property_id.');
        }

        if (empty($credentials['refresh_token'])) {
            return ValidationResult::fail('Google Analytics requires refresh_token.');
        }

        return ValidationResult::ok();
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        return new FetchResult(records: [], hasMore: false);
    }
}
