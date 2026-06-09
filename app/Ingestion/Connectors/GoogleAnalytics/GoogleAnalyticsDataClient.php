<?php

namespace App\Ingestion\Connectors\GoogleAnalytics;

use App\Services\Google\GoogleTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAnalyticsDataClient
{
    protected string $baseUrl = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(
        protected GoogleTokenService $tokens,
        protected GoogleAnalyticsAdminClient $admin,
    ) {}

    /**
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @return list<array<string, mixed>>
     */
    public function runReport(
        string $refreshToken,
        string $propertyId,
        string $startDate,
        string $endDate,
        array $dimensions,
        array $metrics,
        int $limit = 5000,
        int $offset = 0,
    ): array {
        $propertyId = $this->admin->normalizePropertyId($propertyId);
        $url = "{$this->baseUrl}/properties/{$propertyId}:runReport";

        $response = $this->authorizedPost($refreshToken, $url, [
            'dateRanges' => [
                [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ],
            ],
            'dimensions' => array_map(fn (string $name) => ['name' => $name], $dimensions),
            'metrics' => array_map(fn (string $name) => ['name' => $name], $metrics),
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $rows = $response->json('rows') ?? [];

        return is_array($rows) ? $rows : [];
    }

    public function testConnection(string $refreshToken, string $propertyId): void
    {
        $this->runReport(
            $refreshToken,
            $propertyId,
            now()->subDays(3)->toDateString(),
            now()->subDays(3)->toDateString(),
            ['date'],
            ['sessions'],
            1,
            0,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function authorizedPost(string $refreshToken, string $url, array $body): Response
    {
        $response = Http::withToken($this->tokens->refreshAccessToken($refreshToken))
            ->acceptJson()
            ->post($url, $body);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('error') ?? $response->body();

        return 'Google Analytics Data API error (HTTP '.$response->status().'): '.$message;
    }
}
