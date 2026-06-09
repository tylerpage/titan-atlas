<?php

namespace App\Ingestion\Connectors\Shopify;

use Illuminate\Http\Client\Response;
use RuntimeException;

class ShopifyAnalyticsClient
{
    public function __construct(
        protected string $shop,
        protected ShopifyHttpClient $http,
        protected string $apiVersion = '2025-10',
    ) {}

    /**
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    public function sessionsBySourceMedium(string $since, string $until): array
    {
        $shopifyql = sprintf(
            'FROM sessions SHOW sessions, online_store_visitors GROUP BY utm_source, utm_medium, day SINCE %s UNTIL %s LIMIT %d',
            $since,
            $until,
            max(1, (int) config('titan.shopify.analytics_row_limit', 1000)),
        );

        $response = $this->graphql(
            <<<'GRAPHQL'
            query ShopifyAnalytics($query: String!) {
              shopifyqlQuery(query: $query) {
                tableData {
                  columns { name }
                  rows
                }
                parseErrors
              }
            }
            GRAPHQL,
            ['query' => $shopifyql],
        );

        $result = $response->json('data.shopifyqlQuery');

        if (! is_array($result)) {
            throw new RuntimeException('Unexpected Shopify analytics response.');
        }

        $parseErrors = $result['parseErrors'] ?? [];

        if (is_array($parseErrors) && $parseErrors !== []) {
            throw new RuntimeException('ShopifyQL error: '.implode('; ', $parseErrors));
        }

        $rows = $result['tableData']['rows'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $records = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeSessionRow($row);

            if ($normalized) {
                $records[] = $normalized;
            }
        }

        return $records;
    }

    public function testAccess(): bool
    {
        $today = now()->toDateString();

        $response = $this->graphql(
            <<<'GRAPHQL'
            query ShopifyAnalyticsTest($query: String!) {
              shopifyqlQuery(query: $query) {
                parseErrors
              }
            }
            GRAPHQL,
            [
                'query' => "FROM sessions SHOW sessions GROUP BY day SINCE {$today} UNTIL {$today} LIMIT 1",
            ],
        );

        $parseErrors = $response->json('data.shopifyqlQuery.parseErrors') ?? [];

        return ! is_array($parseErrors) || $parseErrors === [];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function graphql(string $query, array $variables = []): Response
    {
        $response = $this->http->postJson(
            "https://{$this->shop}/admin/api/{$this->apiVersion}/graphql.json",
            [
                'query' => $query,
                'variables' => $variables,
            ],
        );

        $response->throw();

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)
                ->pluck('message')
                ->filter()
                ->implode('; ');

            throw new RuntimeException($message !== '' ? $message : 'Shopify GraphQL request failed.');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{resource_type: string, external_id: string, payload: array<string, mixed>}|null
     */
    protected function normalizeSessionRow(array $row): ?array
    {
        $day = $this->stringValue($row['day'] ?? null);

        if ($day === null) {
            return null;
        }

        $source = $this->stringValue($row['utm_source'] ?? null) ?? '(not set)';
        $medium = $this->stringValue($row['utm_medium'] ?? null) ?? '(not set)';
        $sessions = (float) ($row['sessions'] ?? 0);
        $visitors = (float) ($row['online_store_visitors'] ?? 0);

        if ($sessions <= 0 && $visitors <= 0) {
            return null;
        }

        $externalId = sha1("{$day}|{$source}|{$medium}");

        return [
            'resource_type' => 'session_attribution',
            'external_id' => $externalId,
            'payload' => [
                'date' => $day,
                'source' => $source,
                'medium' => $medium,
                'source_medium' => "{$source} / {$medium}",
                'sessions' => $sessions,
                'visitors' => $visitors,
            ],
        ];
    }

    protected function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
