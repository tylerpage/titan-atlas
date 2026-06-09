<?php

namespace App\Ingestion\Connectors\StackAdapt;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StackAdaptRestClient
{
    public function isEnabled(): bool
    {
        return (bool) config('titan.stackadapt.use_rest_fallback', false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deliveryStats(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
    ): array {
        if (! $this->isEnabled()) {
            throw new RuntimeException('StackAdapt REST fallback is disabled.');
        }

        Log::warning('StackAdapt REST fallback invoked; migrate to GraphQL-only access.');

        $response = Http::withHeaders([
            'X-Authorization' => $apiKey,
            'Accept' => 'application/json',
        ])->get($this->baseUrl().'/stats/delivery', [
            'advertiser_id' => $advertiserId,
            'start_date' => $fromDate,
            'end_date' => $toDate,
            'granularity' => 'daily',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $rows = $response->json('data') ?? $response->json();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('titan.stackadapt.rest_base_url', 'https://api.stackadapt.com/v2'), '/');
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('message') ?? $response->body();

        return is_string($message) && $message !== ''
            ? $message
            : 'StackAdapt REST API request failed.';
    }
}
