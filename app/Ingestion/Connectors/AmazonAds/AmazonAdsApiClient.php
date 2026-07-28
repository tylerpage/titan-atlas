<?php

namespace App\Ingestion\Connectors\AmazonAds;

use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AmazonAdsApiClient implements PaidMediaAdsApiClient
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('titan.amazon_ads.base_url', 'https://advertising-api.amazon.com'), '/');
    }

    protected function apiVersion(): string
    {
        return trim((string) config('titan.amazon_ads.api_version', 'v2'), '/');
    }

    protected function clientId(): string
    {
        return trim((string) config('titan.amazon_ads.client_id', ''));
    }

    protected function profileScope(): string
    {
        return trim((string) config('titan.amazon_ads.profile_scope', 'advertising::campaign_management'));
    }

    public function normalizeProfileId(string $id): string
    {
        return trim($id);
    }

    /**
     * @return list<array{accountId: string, name: string, currency: string}>
     */
    public function listAccounts(string $accessToken): array
    {
        $response = $this->request($accessToken, 'GET', '/profiles');
        $profiles = [];

        foreach (Arr::get($response, 'profiles', Arr::get($response, 'data', [])) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $profileId = $this->normalizeProfileId((string) ($row['profileId'] ?? $row['profile_id'] ?? $row['id'] ?? ''));

            if ($profileId === '') {
                continue;
            }

            $profiles[] = [
                'accountId' => $profileId,
                'name' => (string) ($row['accountInfo']['name'] ?? $row['name'] ?? "Profile {$profileId}"),
                'currency' => (string) ($row['currencyCode'] ?? $row['currency'] ?? 'USD'),
            ];
        }

        usort($profiles, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        return $profiles;
    }

    /**
     * @return array{accountId: string, name: string, currency: string}
     */
    public function testConnection(string $accessToken, string $accountId): array
    {
        $accountId = $this->normalizeProfileId($accountId);
        $profiles = $this->listAccounts($accessToken);

        foreach ($profiles as $profile) {
            if ($profile['accountId'] === $accountId) {
                return $profile;
            }
        }

        throw new RuntimeException('Amazon Ads profile not found for the selected profile ID.');
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
        $accountId = $this->normalizeProfileId($accountId);

        $response = $this->request(
            $accessToken,
            'POST',
            '/reporting/reports/query',
            profileId: $accountId,
            body: [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'stream' => $stream,
                'groupBy' => $stream === 'campaign_daily' ? ['date', 'campaign'] : ['date'],
                'metrics' => [
                    'impressions',
                    'clicks',
                    'cost',
                    'purchases',
                    'sales',
                ],
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
    protected function request(
        string $accessToken,
        string $method,
        string $path,
        ?string $profileId = null,
        array $body = [],
    ): array {
        $timeout = max(10, (int) config('titan.amazon_ads.http_timeout_seconds', 60));
        $url = $this->baseUrl().'/'.trim($this->apiVersion(), '/').'/'.ltrim($path, '/');
        $clientId = $this->clientId();

        $headers = [
            'Accept' => 'application/json',
            'Amazon-Advertising-API-ClientId' => $clientId !== '' ? $clientId : 'scaffold-client-id',
        ];

        if ($profileId !== null && $profileId !== '') {
            $headers['Amazon-Advertising-API-Scope'] = $profileId;
        }

        $pending = Http::withToken($accessToken)
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout($timeout);

        $response = strtoupper($method) === 'POST'
            ? $pending->post($url, $body)
            : $pending->get($url, $body);

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->json('details') ?? $response->body());

            throw new RuntimeException(
                'Amazon Ads API request failed ('.$response->status().'): '.($message !== '' ? $message : 'Unknown error'),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
