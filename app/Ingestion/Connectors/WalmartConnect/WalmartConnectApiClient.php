<?php

namespace App\Ingestion\Connectors\WalmartConnect;

use App\Ingestion\Connectors\Concerns\MapsMarketplaceReportRows;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Support\AsyncReportPoller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WalmartConnectApiClient implements PaidMediaAdsApiClient
{
    use MapsMarketplaceReportRows;

    public function __construct(protected AsyncReportPoller $poller) {}

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
        $configuration = $this->reportConfiguration($stream);

        if ($configuration === null) {
            return [];
        }

        return $this->mapWalmartRows(
            $this->runAsyncSnapshot($accessToken, $accountId, $startDate, $endDate, $configuration),
            $stream,
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<array<string, mixed>>
     */
    protected function runAsyncSnapshot(
        string $accessToken,
        string $accountId,
        string $startDate,
        string $endDate,
        array $configuration,
    ): array {
        $createResponse = $this->request(
            $accessToken,
            'POST',
            "/advertisers/{$accountId}/snapshot",
            body: array_merge($configuration, [
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]),
        );

        $snapshotId = (string) ($createResponse['snapshotId'] ?? $createResponse['requestId'] ?? $createResponse['id'] ?? '');

        if ($snapshotId === '') {
            throw new RuntimeException('Walmart snapshot request did not return a snapshot ID.');
        }

        $status = $this->poller->waitUntilReady(
            fn () => $this->request($accessToken, 'GET', "/advertisers/{$accountId}/snapshot/{$snapshotId}"),
            fn (array $payload) => in_array(strtoupper((string) ($payload['status'] ?? $payload['jobStatus'] ?? '')), ['DONE', 'COMPLETED', 'READY'], true),
            fn (array $payload) => in_array(strtoupper((string) ($payload['status'] ?? $payload['jobStatus'] ?? '')), ['FAILED', 'ERROR', 'CANCELLED'], true),
        );

        $downloadUrl = (string) ($status['downloadUrl'] ?? $status['url'] ?? '');

        if ($downloadUrl === '') {
            throw new RuntimeException('Walmart snapshot completed without a download URL.');
        }

        return $this->poller->downloadJsonRows($downloadUrl, $accessToken);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function reportConfiguration(string $stream): ?array
    {
        return match ($stream) {
            'spend_daily' => [
                'reportType' => 'accountPerformance',
                'groupBy' => ['DATE'],
                'metrics' => ['impressions', 'clicks', 'adSpend', 'attributedSales', 'attributedOrders'],
            ],
            'campaign_daily' => [
                'reportType' => 'campaignPerformance',
                'groupBy' => ['DATE', 'CAMPAIGN'],
                'metrics' => ['impressions', 'clicks', 'adSpend', 'attributedSales', 'attributedOrders'],
            ],
            'keyword_daily' => [
                'reportType' => 'keywordPerformance',
                'groupBy' => ['DATE', 'KEYWORD'],
                'metrics' => ['impressions', 'clicks', 'adSpend', 'attributedSales', 'attributedOrders'],
            ],
            'page_type_daily' => [
                'reportType' => 'pageTypePerformance',
                'groupBy' => ['DATE', 'PAGE_TYPE'],
                'metrics' => ['impressions', 'clicks', 'adSpend', 'attributedSales', 'attributedOrders'],
            ],
            'tactic_daily' => [
                'reportType' => 'tacticPerformance',
                'groupBy' => ['DATE', 'TACTIC'],
                'metrics' => ['impressions', 'clicks', 'adSpend', 'attributedSales', 'attributedOrders'],
            ],
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function mapWalmartRows(array $rows, string $stream): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->mapDate($row);

            if ($date === '') {
                continue;
            }

            $metrics = $this->mapStandardMetrics($row);
            $mappedRow = array_merge(['date' => $date], $metrics);

            if ($stream === 'campaign_daily') {
                $campaignId = (string) ($row['campaignId'] ?? $row['campaign_id'] ?? '');

                if ($campaignId === '') {
                    continue;
                }

                $mappedRow['campaign_id'] = $campaignId;
                $mappedRow['campaign_name'] = (string) ($row['campaignName'] ?? $row['campaign_name'] ?? $campaignId);
            } elseif (in_array($stream, ['keyword_daily', 'page_type_daily', 'tactic_daily'], true)) {
                $dimensionKey = (string) ($row['keyword'] ?? $row['pageType'] ?? $row['tactic'] ?? $row['dimension'] ?? '');

                if ($dimensionKey === '') {
                    continue;
                }

                $mappedRow['dimension_key'] = md5(strtolower($dimensionKey));
                $mappedRow['dimension_label'] = $dimensionKey;
            }

            $mapped[] = $mappedRow;
        }

        return $mapped;
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
