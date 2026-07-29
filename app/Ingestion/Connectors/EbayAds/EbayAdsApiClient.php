<?php

namespace App\Ingestion\Connectors\EbayAds;

use App\Ingestion\Connectors\Concerns\MapsMarketplaceReportRows;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Support\AsyncReportPoller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EbayAdsApiClient implements PaidMediaAdsApiClient
{
    use MapsMarketplaceReportRows;

    public function __construct(protected AsyncReportPoller $poller) {}

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
        $configuration = $this->reportConfiguration($stream);

        if ($configuration === null) {
            return [];
        }

        return $this->mapEbayRows(
            $this->runAsyncReport($accessToken, $accountId, $startDate, $endDate, $configuration),
            $stream,
        );
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<array<string, mixed>>
     */
    protected function runAsyncReport(
        string $accessToken,
        string $accountId,
        string $startDate,
        string $endDate,
        array $configuration,
    ): array {
        $createResponse = $this->request(
            $accessToken,
            'POST',
            '/ad_report',
            body: array_merge($configuration, [
                'marketplaceId' => $this->marketplaceId(),
                'dateFrom' => $startDate,
                'dateTo' => $endDate,
                'reportFormat' => 'JSON',
            ]),
        );

        $reportId = (string) ($createResponse['reportId'] ?? $createResponse['reportTaskId'] ?? $createResponse['id'] ?? '');

        if ($reportId === '') {
            throw new RuntimeException('eBay report task creation did not return a report ID.');
        }

        $status = $this->poller->waitUntilReady(
            fn () => $this->request($accessToken, 'GET', "/ad_report/{$reportId}"),
            fn (array $payload) => in_array(strtoupper((string) ($payload['reportTaskStatus'] ?? $payload['status'] ?? '')), ['SUCCESS', 'COMPLETED'], true),
            fn (array $payload) => in_array(strtoupper((string) ($payload['reportTaskStatus'] ?? $payload['status'] ?? '')), ['FAILED', 'CANCELLED'], true),
        );

        $downloadUrl = (string) ($status['reportHref'] ?? $status['url'] ?? '');

        if ($downloadUrl === '') {
            throw new RuntimeException('eBay report completed without a download URL.');
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
                'reportType' => 'ACCOUNT_PERFORMANCE_REPORT',
                'dimensions' => ['DAY'],
                'metricKeys' => ['IMPRESSIONS', 'CLICKS', 'AD_FEES', 'SALES', 'SALE_AMOUNT'],
            ],
            'campaign_daily' => [
                'reportType' => 'CAMPAIGN_PERFORMANCE_REPORT',
                'dimensions' => ['DAY', 'CAMPAIGN'],
                'metricKeys' => ['IMPRESSIONS', 'CLICKS', 'AD_FEES', 'SALES', 'SALE_AMOUNT'],
            ],
            'listing_daily' => [
                'reportType' => 'LISTING_PERFORMANCE_REPORT',
                'dimensions' => ['DAY', 'LISTING'],
                'metricKeys' => ['IMPRESSIONS', 'CLICKS', 'AD_FEES', 'SALES', 'SALE_AMOUNT'],
            ],
            'keyword_daily' => [
                'reportType' => 'KEYWORD_PERFORMANCE_REPORT',
                'dimensions' => ['DAY', 'KEYWORD'],
                'metricKeys' => ['IMPRESSIONS', 'CLICKS', 'AD_FEES', 'SALES', 'SALE_AMOUNT'],
            ],
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function mapEbayRows(array $rows, string $stream): array
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
            } elseif ($stream === 'listing_daily') {
                $listingId = (string) ($row['listingId'] ?? $row['listing_id'] ?? '');

                if ($listingId === '') {
                    continue;
                }

                $mappedRow['dimension_key'] = $listingId;
                $mappedRow['dimension_label'] = (string) ($row['listingTitle'] ?? $row['listing_title'] ?? $listingId);
            } elseif ($stream === 'keyword_daily') {
                $keyword = (string) ($row['keyword'] ?? $row['keywordText'] ?? '');

                if ($keyword === '') {
                    continue;
                }

                $mappedRow['dimension_key'] = md5(strtolower($keyword));
                $mappedRow['dimension_label'] = $keyword;
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
