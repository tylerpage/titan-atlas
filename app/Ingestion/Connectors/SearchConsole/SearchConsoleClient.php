<?php

namespace App\Ingestion\Connectors\SearchConsole;

use App\Services\Google\GoogleTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SearchConsoleClient
{
    protected string $baseUrl = 'https://www.googleapis.com/webmasters/v3';

    public function __construct(protected GoogleTokenService $tokens) {}

    /**
     * @return list<array{siteUrl: string, permissionLevel: string}>
     */
    public function listSites(string $refreshToken): array
    {
        $response = $this->authorizedGet($refreshToken, "{$this->baseUrl}/sites");

        $entries = $response->json('siteEntry') ?? [];

        if (! is_array($entries)) {
            return [];
        }

        if (isset($entries['siteUrl'])) {
            $entries = [$entries];
        }

        return collect($entries)
            ->filter(fn ($entry) => is_array($entry) && isset($entry['siteUrl']))
            ->map(fn (array $entry) => [
                'siteUrl' => (string) $entry['siteUrl'],
                'permissionLevel' => (string) ($entry['permissionLevel'] ?? 'unknown'),
            ])
            ->sortBy('siteUrl')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public function querySearchAnalytics(string $refreshToken, string $siteUrl, array $body): array
    {
        $encodedSite = rawurlencode($siteUrl);
        $response = $this->authorizedPost(
            $refreshToken,
            "{$this->baseUrl}/sites/{$encodedSite}/searchAnalytics/query",
            $body,
        );

        $rows = $response->json('rows') ?? [];

        return is_array($rows) ? $rows : [];
    }

    public function encodeSiteUrl(string $siteUrl): string
    {
        return rawurlencode($siteUrl);
    }

    public function hasQueryablePermission(string $permissionLevel): bool
    {
        return in_array($permissionLevel, [
            'siteOwner',
            'siteFullUser',
            'siteRestrictedUser',
        ], true);
    }

    protected function authorizedGet(string $refreshToken, string $url): Response
    {
        $response = Http::withToken($this->tokens->refreshAccessToken($refreshToken))->get($url);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
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

        return 'Search Console API error (HTTP '.$response->status().'): '.$message;
    }
}
