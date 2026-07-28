<?php

namespace App\Ingestion\Connectors\WalmartConnect;

use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WalmartConnectApiClient implements PaidMediaAdsApiClient
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('titan.walmart_connect.base_url', 'https://developer.api.walmart.com/api-proxy/service/WPA/AdsApi/v1'), '/');
    }

    public function normalizeAdvertiserId(string $id): string
    {
        return trim($id);
    }

    /**
     * @return list<array{accountId: string, name: string, currency: string}>
     */
    public function listAccounts(string $accessToken): array
    {
        $response = $this->request($accessToken, 'GET', '/advertisers');
        $advertisers = [];

        foreach (Arr::get($response, 'advertisers', Arr::get($response, 'data', [])) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $advertiserId = $this->normalizeAdvertiserId((string) ($row['advertiserId'] ?? $row['advertiser_id'] ?? $row['id'] ?? ''));

            if ($advertiserId === '') {
                continue;
            }

            $advertisers[] = [
                'accountId' => $advertiserId,
                'name' => (string) ($row['name'] ?? $row['advertiserName'] ?? "Advertiser {$advertiserId}"),
                'currency' => (string) ($row['currency'] ?? 'USD'),
            ];
        }

        usort($advertisers, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        return $advertisers;
    }

    /**
     * @return array{accountId: string, name: string, currency: string}
     */
    public function testConnection(string $accessToken, string $accountId): array
    {
        $accountId = $this->normalizeAdvertiserId($accountId);

        foreach ($this->listAccounts($accessToken) as $advertiser) {
            if ($advertiser['accountId'] === $accountId) {
                return $advertiser;
            }
        }

        throw new RuntimeException('Walmart Connect advertiser not found for the selected advertiser ID.');
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
        $accountId = $this->normalizeAdvertiserId($accountId);

        $response = $this->request(
            $accessToken,
            'POST',
            "/advertisers/{$accountId}/reports",
            body: [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'stream' => $stream,
                'groupBy' => $stream === 'campaign_daily' ? ['DATE', 'CAMPAIGN'] : ['DATE'],
                'metrics' => ['impressions', 'clicks', 'adSpend', 'attributedSales', 'attributedOrders'],
            ],
        );

        $rows = Arr::get($response, 'rows', Arr::get($response, 'data', []));

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
        $timeout = max(10, (int) config('titan.walmart_connect.http_timeout_seconds', 60));
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $pending = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout($timeout);

        $response = strtoupper($method) === 'POST'
            ? $pending->post($url, $body)
            : $pending->get($url, $body);

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->json('error.message') ?? $response->body());

            throw new RuntimeException(
                'Walmart Connect API request failed ('.$response->status().'): '.($message !== '' ? $message : 'Unknown error'),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
