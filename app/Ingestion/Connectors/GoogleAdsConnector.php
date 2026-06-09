<?php

namespace App\Ingestion\Connectors;

use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Models\Connection;

class GoogleAdsConnector extends AbstractConnector
{
    public function type(): string
    {
        return ConnectorType::GoogleAds->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['refresh_token']) || empty($credentials['customer_id'])) {
            return ValidationResult::fail('Google Ads requires refresh_token and customer_id.');
        }

        return ValidationResult::ok();
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        return new FetchResult(records: [], hasMore: false);
    }
}
