<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\SyncsPaidMediaStreams;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Ingestion\Connectors\WalmartConnect\WalmartConnectApiClient;
use App\Models\Connection;

class WalmartConnectConnector extends AbstractConnector implements FanOutSyncConnector
{
    use SyncsPaidMediaStreams;
    use WalksSyncDateChunks;

    public function __construct(protected WalmartConnectApiClient $client) {}

    public function syncStreams(): array
    {
        return [
            'spend_daily',
            'campaign_daily',
            'keyword_daily',
            'page_type_daily',
            'tactic_daily',
        ];
    }

    public function type(): string
    {
        return ConnectorType::WalmartConnect->value;
    }

    protected function paidMediaConfigKey(): string
    {
        return 'walmart_connect';
    }

    protected function paidMediaCursorPrefix(): string
    {
        return 'walmart';
    }

    protected function paidMediaApiClient(): PaidMediaAdsApiClient
    {
        return $this->client;
    }

    protected function paidMediaAccountCredentialKey(): string
    {
        return 'advertiser_id';
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($accessToken === '') {
            return ValidationResult::fail('Walmart Connect requires an OAuth access token with Ads API scope.');
        }

        $debug = ['token_length' => strlen($accessToken)];

        try {
            $advertisers = $this->client->listAccounts($accessToken);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not authenticate with the Walmart Connect API.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $debug['advertisers'] = $advertisers;

        $advertiserId = $this->client->normalizeAdvertiserId((string) ($credentials['advertiser_id'] ?? ''));

        if ($advertiserId === '') {
            return ValidationResult::ok(
                'Walmart access token is valid. Select an advertiser to finish setup.',
                $debug,
            );
        }

        try {
            $advertiser = $this->client->testConnection($accessToken, $advertiserId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not access the selected Walmart Connect advertiser.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        return ValidationResult::ok(
            'Connected to Walmart Connect advertiser '.$advertiser['name'],
            array_merge($debug, $advertiser),
        );
    }
}
