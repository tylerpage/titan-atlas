<?php

namespace App\Ingestion\Connectors\RedditAds;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RedditAdsApiClient
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('titan.reddit_ads.base_url', 'https://ads-api.reddit.com/api/v3'), '/');
    }

    public function testConnection(string $accessToken, string $accountId): array
    {
        $response = $this->request(
            $accessToken,
            'GET',
            "/accounts/{$accountId}/campaigns",
            query: ['page' => ['size' => 1]],
        );

        return [
            'account_id' => $accountId,
            'campaign_sample_count' => count(Arr::get($response, 'data', [])),
        ];
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
        $body = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'metrics' => [
                'impressions',
                'clicks',
                'spend',
                'spend_micro',
                'ctr',
                'conversions',
                'conversion_rate',
            ],
        ];

        $body['group_by'] = match ($stream) {
            'campaign_daily' => ['DATE', 'CAMPAIGN'],
            default => ['DATE'],
        };

        $response = $this->request(
            $accessToken,
            'POST',
            "/accounts/{$accountId}/reports",
            body: $body,
        );

        $rows = Arr::get($response, 'data', Arr::get($response, 'rows', []));

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, fn ($row) => is_array($row)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function request(
        string $accessToken,
        string $method,
        string $path,
        array $query = [],
        array $body = [],
    ): array {
        $timeout = max(10, (int) config('titan.reddit_ads.http_timeout_seconds', 30));
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $pending = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout($timeout);

        $response = strtoupper($method) === 'POST'
            ? $pending->post($url, $body)
            : $pending->get($url, $query);

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?? $response->json('error.message') ?? $response->body());

            throw new RuntimeException(
                'Reddit Ads API request failed ('.$response->status().'): '.($message !== '' ? $message : 'Unknown error'),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function microsToCurrency(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return round(((float) $value) / 1_000_000, 6);
    }
}
