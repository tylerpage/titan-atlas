<?php

namespace App\Ingestion\Connectors\EbayAds;

use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EbayAdsApiClient implements PaidMediaAdsApiClient
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('titan.ebay_ads.base_url', 'https://api.ebay.com/sell/marketing/v1'), '/');
    }

    protected function marketplaceId(): string
    {
        return trim((string) config('titan.ebay_ads.marketplace_id', 'EBAY_US'));
    }

    public function normalizeAccountId(string $id): string
    {
        return trim($id);
    }

    /**
     * @return list<array{accountId: string, name: string, currency: string}>
     */
    public function listAccounts(string $accessToken): array
    {
        $response = $this->request($accessToken, 'GET', '/ad_account');
        $accounts = [];

        foreach (Arr::get($response, 'accounts', Arr::get($response, 'adAccounts', [])) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $accountId = $this->normalizeAccountId((string) ($row['accountId'] ?? $row['account_id'] ?? $row['id'] ?? ''));

            if ($accountId === '') {
                continue;
            }

            $accounts[] = [
                'accountId' => $accountId,
                'name' => (string) ($row['name'] ?? $row['accountName'] ?? "Account {$accountId}"),
                'currency' => (string) ($row['currency'] ?? 'USD'),
            ];
        }

        if ($accounts === [] && isset($response['accountId'])) {
            $accounts[] = [
                'accountId' => $this->normalizeAccountId((string) $response['accountId']),
                'name' => (string) ($response['name'] ?? 'Default ad account'),
                'currency' => (string) ($response['currency'] ?? 'USD'),
            ];
        }

        usort($accounts, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        return $accounts;
    }

    /**
     * @return array{accountId: string, name: string, currency: string}
     */
    public function testConnection(string $accessToken, string $accountId): array
    {
        $accountId = $this->normalizeAccountId($accountId);

        foreach ($this->listAccounts($accessToken) as $account) {
            if ($account['accountId'] === $accountId) {
                return $account;
            }
        }

        throw new RuntimeException('eBay ad account not found for the selected account ID.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reportRows(
        string $accessToken,
        string $accountId,
        string $startDate,
        string $endDate,
        string $stream,
    ): array {
        $accountId = $this->normalizeAccountId($accountId);

        $response = $this->request(
            $accessToken,
            'POST',
            "/ad_account/{$accountId}/report",
            body: [
                'marketplaceId' => $this->marketplaceId(),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'stream' => $stream,
                'dimensions' => $stream === 'campaign_daily' ? ['DAY', 'CAMPAIGN'] : ['DAY'],
                'metrics' => ['IMPRESSIONS', 'CLICKS', 'AD_FEES', 'SALES', 'SALE_AMOUNT'],
            ],
        );

        $rows = Arr::get($response, 'records', Arr::get($response, 'rows', Arr::get($response, 'data', [])));

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, fn ($row) => is_array($row)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function request(string $accessToken, string $method, string $path, array $body = []): array
    {
        $timeout = max(10, (int) config('titan.ebay_ads.http_timeout_seconds', 60));
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $pending = Http::withToken($accessToken)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Language' => 'en-US',
            ])
            ->acceptJson()
            ->timeout($timeout);

        $response = strtoupper($method) === 'POST'
            ? $pending->post($url, $body)
            : $pending->get($url, $body);

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->json('errors.0.message') ?? $response->body());

            throw new RuntimeException(
                'eBay Advertising API request failed ('.$response->status().'): '.($message !== '' ? $message : 'Unknown error'),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
