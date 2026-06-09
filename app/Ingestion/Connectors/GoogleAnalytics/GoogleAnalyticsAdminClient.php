<?php

namespace App\Ingestion\Connectors\GoogleAnalytics;

use App\Services\Google\GoogleTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAnalyticsAdminClient
{
    protected string $baseUrl = 'https://analyticsadmin.googleapis.com/v1beta';

    public function __construct(protected GoogleTokenService $tokens) {}

    /**
     * @return list<array{propertyId: string, displayName: string, accountName: string}>
     */
    public function listProperties(string $refreshToken): array
    {
        $response = $this->authorizedGet($refreshToken, "{$this->baseUrl}/accountSummaries");
        $summaries = $response->json('accountSummaries') ?? [];

        if (! is_array($summaries)) {
            return [];
        }

        $properties = [];

        foreach ($summaries as $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $accountName = (string) ($summary['displayName'] ?? 'Account');
            $propertySummaries = $summary['propertySummaries'] ?? [];

            if (! is_array($propertySummaries)) {
                continue;
            }

            foreach ($propertySummaries as $property) {
                if (! is_array($property)) {
                    continue;
                }

                $resourceName = (string) ($property['property'] ?? '');

                if ($resourceName === '') {
                    continue;
                }

                $propertyId = $this->normalizePropertyId($resourceName);

                if ($propertyId === '') {
                    continue;
                }

                $properties[] = [
                    'propertyId' => $propertyId,
                    'displayName' => (string) ($property['displayName'] ?? $propertyId),
                    'accountName' => $accountName,
                ];
            }
        }

        return collect($properties)
            ->unique('propertyId')
            ->sortBy('displayName')
            ->values()
            ->all();
    }

    public function normalizePropertyId(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, 'properties/')) {
            return substr($value, strlen('properties/'));
        }

        return $value;
    }

    protected function authorizedGet(string $refreshToken, string $url): Response
    {
        $response = Http::withToken($this->tokens->refreshAccessToken($refreshToken))->get($url);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('error') ?? $response->body();

        return 'Google Analytics Admin API error (HTTP '.$response->status().'): '.$message;
    }
}
