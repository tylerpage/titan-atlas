<?php

namespace App\Ingestion\Connectors\AmazonAds;

use App\Ingestion\Connectors\Concerns\MapsMarketplaceReportRows;
use App\Ingestion\Connectors\Contracts\PaidMediaAdsApiClient;
use App\Support\AsyncReportPoller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AmazonAdsApiClient implements PaidMediaAdsApiClient
{
    use MapsMarketplaceReportRows;

    public function __construct(protected AsyncReportPoller $poller) {}

    protected function baseUrl(): string
    {
        return rtrim((string) config('titan.amazon_ads.base_url', 'https://advertising-api.amazon.com'), '/');
    }

    protected function clientId(): string
    {
        return trim((string) config('titan.amazon_ads.client_id', ''));
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
        $response = $this->request($accessToken, 'GET', '/v2/profiles');
        $source = is_array($response) && array_is_list($response)
            ? $response
            : Arr::get($response, 'profiles', Arr::get($response, 'data', []));
        $profiles = [];

        foreach ($source as $row) {
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

        foreach ($this->listAccounts($accessToken) as $profile) {
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

        if ($stream === 'spend_daily') {
            return $this->aggregateDailySpend(
                $this->fetchAdProductReports($accessToken, $accountId, $startDate, $endDate, 'spend_daily'),
            );
        }

        if ($stream === 'ad_type_daily') {
            return $this->fetchAdTypeDaily($accessToken, $accountId, $startDate, $endDate);
        }

        $configuration = $this->reportConfiguration($stream);

        if ($configuration === null) {
            return [];
        }

        return $this->mapAmazonRows(
            $this->runAsyncReport($accessToken, $accountId, $startDate, $endDate, $configuration),
            $stream,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function mapAmazonRows(array $rows, string $stream): array
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
                $mappedRow['objective'] = (string) ($row['adProduct'] ?? $row['campaignType'] ?? '');
            } elseif ($stream === 'keyword_daily') {
                $keyword = (string) ($row['searchTerm'] ?? $row['keyword'] ?? $row['targeting'] ?? '');

                if ($keyword === '') {
                    continue;
                }

                $mappedRow['dimension_key'] = md5(strtolower($keyword));
                $mappedRow['dimension_label'] = $keyword;
            } elseif ($stream === 'ad_product_daily') {
                $asin = (string) ($row['advertisedAsin'] ?? $row['asin'] ?? '');

                if ($asin === '') {
                    continue;
                }

                $mappedRow['dimension_key'] = $asin;
                $mappedRow['dimension_label'] = (string) ($row['advertisedSku'] ?? $row['sku'] ?? $asin);
            } elseif ($stream === 'ad_type_daily') {
                $adType = (string) ($row['ad_product'] ?? $row['adProduct'] ?? '');

                if ($adType === '') {
                    continue;
                }

                $mappedRow['dimension_key'] = $adType;
                $mappedRow['dimension_label'] = $this->formatAdProductLabel($adType);
            }

            $mapped[] = $mappedRow;
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchAdTypeDaily(
        string $accessToken,
        string $accountId,
        string $startDate,
        string $endDate,
    ): array {
        $rows = [];

        foreach ($this->adProducts() as $adProduct => $label) {
            $configuration = $this->reportConfiguration('spend_daily', $adProduct);
            $reportRows = $this->runAsyncReport($accessToken, $accountId, $startDate, $endDate, $configuration);

            foreach ($reportRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $date = $this->mapDate($row);

                if ($date === '') {
                    continue;
                }

                $rows[] = array_merge(
                    ['date' => $date, 'ad_product' => $adProduct],
                    $this->mapStandardMetrics($row),
                );
            }
        }

        return $this->mapAmazonRows($rows, 'ad_type_daily');
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @return list<array<string, mixed>>
     */
    protected function fetchAdProductReports(
        string $accessToken,
        string $accountId,
        string $startDate,
        string $endDate,
        string $stream,
    ): array {
        $reports = [];

        foreach (array_keys($this->adProducts()) as $adProduct) {
            $configuration = $this->reportConfiguration($stream, $adProduct);
            $reports[] = $this->runAsyncReport($accessToken, $accountId, $startDate, $endDate, $configuration);
        }

        return $reports;
    }

    /**
     * @param  list<list<array<string, mixed>>>  $reports
     * @return list<array<string, mixed>>
     */
    protected function aggregateDailySpend(array $reports): array
    {
        $byDate = [];

        foreach ($reports as $reportRows) {
            foreach ($reportRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $date = $this->mapDate($row);

                if ($date === '') {
                    continue;
                }

                $metrics = $this->mapStandardMetrics($row);

                if (! isset($byDate[$date])) {
                    $byDate[$date] = array_merge(['date' => $date], $metrics);

                    continue;
                }

                foreach (['cost', 'impressions', 'clicks', 'conversions', 'conversions_value'] as $key) {
                    $byDate[$date][$key] += $metrics[$key];
                }

                $impressions = $byDate[$date]['impressions'];
                $clicks = $byDate[$date]['clicks'];
                $byDate[$date]['ctr'] = $impressions > 0 ? round(($clicks / $impressions) * 100, 4) : 0.0;
            }
        }

        ksort($byDate);

        return array_values($byDate);
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
            '/reporting/reports',
            profileId: $accountId,
            body: [
                'name' => 'titan-'.($configuration['reportTypeId'] ?? 'report').'-'.uniqid(),
                'startDate' => str_replace('-', '', $startDate),
                'endDate' => str_replace('-', '', $endDate),
                'configuration' => $configuration,
            ],
            contentType: 'application/vnd.createasyncreportrequest.v3+json',
            accept: 'application/vnd.createasyncreportresponse.v3+json',
        );

        $reportId = (string) ($createResponse['reportId'] ?? $createResponse['id'] ?? '');

        if ($reportId === '') {
            throw new RuntimeException('Amazon Ads report creation did not return a report ID.');
        }

        $status = $this->poller->waitUntilReady(
            fn () => $this->request(
                $accessToken,
                'GET',
                "/reporting/reports/{$reportId}",
                profileId: $accountId,
                accept: 'application/vnd.getasyncreportresponse.v3+json',
            ),
            fn (array $payload) => in_array(strtoupper((string) ($payload['status'] ?? '')), ['COMPLETED', 'SUCCESS'], true),
            fn (array $payload) => in_array(strtoupper((string) ($payload['status'] ?? '')), ['FAILED', 'CANCELLED', 'FATAL'], true),
        );

        $downloadUrl = (string) ($status['url'] ?? $status['location'] ?? '');

        if ($downloadUrl === '') {
            throw new RuntimeException('Amazon Ads report completed without a download URL.');
        }

        return $this->poller->downloadJsonRows($downloadUrl, $accessToken);
    }

    /**
     * @return array<string, string>
     */
    protected function adProducts(): array
    {
        return [
            'SPONSORED_PRODUCTS' => 'Sponsored Products',
            'SPONSORED_BRANDS' => 'Sponsored Brands',
            'SPONSORED_DISPLAY' => 'Sponsored Display',
        ];
    }

    protected function formatAdProductLabel(string $adProduct): string
    {
        return $this->adProducts()[$adProduct] ?? ucwords(strtolower(str_replace('_', ' ', $adProduct)));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function reportConfiguration(string $stream, ?string $adProduct = null): ?array
    {
        $columns = [
            'date',
            'impressions',
            'clicks',
            'cost',
            'purchases14d',
            'sales14d',
        ];

        return match ($stream) {
            'spend_daily' => [
                'adProduct' => $adProduct ?? 'SPONSORED_PRODUCTS',
                'groupBy' => [],
                'columns' => $columns,
                'reportTypeId' => 'spCampaigns',
                'timeUnit' => 'DAILY',
                'format' => 'GZIP_JSON',
            ],
            'campaign_daily' => [
                'adProduct' => 'SPONSORED_PRODUCTS',
                'groupBy' => ['campaign'],
                'columns' => array_merge($columns, ['campaignId', 'campaignName', 'campaignStatus']),
                'reportTypeId' => 'spCampaigns',
                'timeUnit' => 'DAILY',
                'format' => 'GZIP_JSON',
            ],
            'keyword_daily' => [
                'adProduct' => 'SPONSORED_PRODUCTS',
                'groupBy' => ['searchTerm'],
                'columns' => array_merge($columns, ['searchTerm']),
                'reportTypeId' => 'spSearchTerm',
                'timeUnit' => 'DAILY',
                'format' => 'GZIP_JSON',
            ],
            'ad_product_daily' => [
                'adProduct' => 'SPONSORED_PRODUCTS',
                'groupBy' => ['advertisedAsin'],
                'columns' => array_merge($columns, ['advertisedAsin', 'advertisedSku']),
                'reportTypeId' => 'spAdvertisedProduct',
                'timeUnit' => 'DAILY',
                'format' => 'GZIP_JSON',
            ],
            default => null,
        };
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
        ?string $contentType = null,
        ?string $accept = null,
    ): array {
        $timeout = max(10, (int) config('titan.amazon_ads.http_timeout_seconds', 60));
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $clientId = $this->clientId();

        $headers = [
            'Accept' => $accept ?? 'application/json',
            'Amazon-Advertising-API-ClientId' => $clientId !== '' ? $clientId : 'scaffold-client-id',
        ];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        if ($profileId !== null && $profileId !== '') {
            $headers['Amazon-Advertising-API-Scope'] = $profileId;
        }

        $pending = Http::withToken($accessToken)
            ->withHeaders($headers)
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
