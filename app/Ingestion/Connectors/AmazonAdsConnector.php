<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\AmazonAds\AmazonAdsApiClient;
use App\Ingestion\Connectors\Concerns\SyncsPaidMediaStreams;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Models\Connection;

class AmazonAdsConnector extends AbstractConnector implements FanOutSyncConnector
{
    use SyncsPaidMediaStreams;
    use WalksSyncDateChunks;

    public function __construct(protected AmazonAdsApiClient $client) {}

    public function syncStreams(): array
    {
        return [
            'spend_daily',
            'campaign_daily',
            'ad_type_daily',
            'keyword_daily',
            'ad_product_daily',
        ];
    }

    public function type(): string
    {
        return ConnectorType::AmazonAds->value;
    }

    protected function paidMediaConfigKey(): string
    {
        return 'amazon_ads';
    }

    protected function paidMediaCursorPrefix(): string
    {
        return 'amazon';
    }

    protected function paidMediaApiClient(): PaidMediaAdsApiClient
    {
        return $this->client;
    }

    protected function paidMediaAccountCredentialKey(): string
    {
        return 'profile_id';
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($accessToken === '') {
            return ValidationResult::fail('Amazon Ads requires a Login with Amazon access token with Advertising API scope.');
        }

        $debug = ['token_length' => strlen($accessToken)];

        try {
            $profiles = $this->client->listAccounts($accessToken);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not authenticate with the Amazon Advertising API.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $debug['profiles'] = $profiles;

        $profileId = $this->client->normalizeProfileId((string) ($credentials['profile_id'] ?? ''));

        if ($profileId === '') {
            return ValidationResult::ok(
                'Amazon access token is valid. Select an advertising profile to finish setup.',
                $debug,
            );
        }

        try {
            $profile = $this->client->testConnection($accessToken, $profileId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not access the selected Amazon Ads profile.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        return ValidationResult::ok(
            'Connected to Amazon Ads profile '.$profile['name'],
            array_merge($debug, $profile),
        );
    }
}
