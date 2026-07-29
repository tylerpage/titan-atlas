<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Concerns\SyncsPaidMediaStreams;
use App\Ingestion\Connectors\Concerns\WalksSyncDateChunks;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Ingestion\Connectors\EbayAds\EbayAdsApiClient;
use App\Models\Connection;

class EbayAdsConnector extends AbstractConnector implements FanOutSyncConnector
{
    use SyncsPaidMediaStreams;
    use WalksSyncDateChunks;

    public function __construct(protected EbayAdsApiClient $client) {}

    public function syncStreams(): array
    {
        return [
            'spend_daily',
            'campaign_daily',
            'listing_daily',
            'keyword_daily',
        ];
    }

    public function type(): string
    {
        return ConnectorType::EbayAds->value;
    }

    protected function paidMediaConfigKey(): string
    {
        return 'ebay_ads';
    }

    protected function paidMediaCursorPrefix(): string
    {
        return 'ebay';
    }

    protected function paidMediaApiClient(): PaidMediaAdsApiClient
    {
        return $this->client;
    }

    protected function paidMediaAccountCredentialKey(): string
    {
        return 'account_id';
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($accessToken === '') {
            return ValidationResult::fail('eBay Advertising requires an OAuth access token with sell.marketing.readonly scope.');
        }

        $debug = ['token_length' => strlen($accessToken)];

        try {
            $accounts = $this->client->listAccounts($accessToken);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not authenticate with the eBay Advertising API.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        $debug['accounts'] = $accounts;

        $accountId = $this->client->normalizeAccountId((string) ($credentials['account_id'] ?? ''));

        if ($accountId === '') {
            return ValidationResult::ok(
                'eBay access token is valid. Select an ad account to finish setup.',
                $debug,
            );
        }

        try {
            $account = $this->client->testConnection($accessToken, $accountId);
        } catch (\Throwable $e) {
            return ValidationResult::fail(
                'Could not access the selected eBay ad account.',
                array_merge($debug, ['hint' => $e->getMessage()]),
            );
        }

        return ValidationResult::ok(
            'Connected to eBay ad account '.$account['name'],
            array_merge($debug, $account),
        );
    }
}
