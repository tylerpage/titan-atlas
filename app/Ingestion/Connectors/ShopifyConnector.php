<?php

namespace App\Ingestion\Connectors;

use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Shopify\ShopifyAnalyticsClient;
use App\Ingestion\Connectors\Shopify\ShopifyHttpClient;
use App\Models\Connection;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyConnector extends AbstractCommerceConnector
{
    protected function restApiVersion(): string
    {
        return (string) config('titan.shopify.rest_api_version', '2024-10');
    }

    public function type(): string
    {
        return ConnectorType::Shopify->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['shop_domain']) || empty($credentials['access_token'])) {
            return ValidationResult::fail('Shopify requires shop_domain and access_token.');
        }

        $shopInput = (string) $credentials['shop_domain'];
        $shop = $this->normalizeShopDomain($shopInput);
        $token = (string) $credentials['access_token'];
        $url = "https://{$shop}/admin/api/{$this->restApiVersion()}/shop.json";
        $debug = $this->credentialDebug($shopInput, $shop, $token, $url);

        $response = $this->httpClient($token)->get($url);

        if ($response->status() === 401) {
            $this->logValidationFailure($connection, $debug, $response);

            return ValidationResult::fail(
                'Invalid access token.',
                $this->failureDebug($debug, $response, 'Confirm this is an Admin API access token from a custom app with read_orders and read_reports scopes.'),
            );
        }

        if ($response->status() === 404) {
            $this->logValidationFailure($connection, $debug, $response);

            return ValidationResult::fail(
                'Shop not found. Check the shop domain.',
                $this->failureDebug($debug, $response, 'Use your *.myshopify.com domain, not a custom storefront domain.'),
            );
        }

        if (! $response->successful()) {
            $this->logValidationFailure($connection, $debug, $response);

            return ValidationResult::fail(
                'Could not connect to Shopify (HTTP '.$response->status().').',
                $this->failureDebug($debug, $response),
            );
        }

        $name = $response->json('shop.name');

        return $name
            ? ValidationResult::ok("Connected to {$name}", $debug)
            : ValidationResult::ok(debug: $debug);
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        if ($cursor !== null && str_starts_with($cursor, 'analytics:')) {
            return $this->fetchAnalytics($connection, $cursor);
        }

        return $this->fetchOrders($connection, $cursor);
    }

    protected function fetchOrders(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $shop = $this->normalizeShopDomain((string) $credentials['shop_domain']);
        $token = (string) $credentials['access_token'];

        if ($cursor) {
            $url = "https://{$shop}/admin/api/{$this->restApiVersion()}/orders.json?limit=250&page_info={$cursor}";
        } else {
            $since = $this->resolveSinceDate($connection)->toIso8601String();
            $url = "https://{$shop}/admin/api/{$this->restApiVersion()}/orders.json?status=any&limit=250&created_at_min=".urlencode($since);
        }

        $response = $this->httpClient($token)->get($url);

        $response->throw();

        $orders = $response->json('orders') ?? [];
        $records = [];

        foreach ($orders as $order) {
            $normalized = $this->normalizeOrder([
                'external_id' => (string) ($order['id'] ?? ''),
                'created_at' => $order['created_at'] ?? null,
                'total' => $order['total_price'] ?? 0,
                'order_number' => $order['name'] ?? null,
                'channel' => $order['source_name'] ?? null,
                'referring_site' => $order['referring_site'] ?? null,
                'landing_site' => $order['landing_site'] ?? null,
                'currency' => $order['currency'] ?? null,
            ]);

            if (! $normalized) {
                continue;
            }

            $records[] = $normalized;

            $orderContext = $this->orderContextFromNormalizedOrder($normalized);

            foreach ($order['line_items'] ?? [] as $lineItem) {
                if (! is_array($lineItem)) {
                    continue;
                }

                $quantity = (int) ($lineItem['quantity'] ?? 0);
                $unitPrice = (float) ($lineItem['price'] ?? 0);
                $discount = (float) ($lineItem['total_discount'] ?? 0);

                $lineRecord = $this->normalizeLineItemRecord([
                    'id' => $lineItem['id'] ?? null,
                    'product_id' => $lineItem['product_id'] ?? null,
                    'variant_id' => $lineItem['variant_id'] ?? null,
                    'sku' => $lineItem['sku'] ?? null,
                    'name' => $lineItem['name'] ?? $lineItem['title'] ?? null,
                    'variant_title' => $lineItem['variant_title'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'price' => $unitPrice,
                    'compare_at_price' => isset($lineItem['compare_at_price']) ? (float) $lineItem['compare_at_price'] : null,
                    'discount_amount' => $discount,
                    'line_total' => max(0, ($quantity * $unitPrice) - $discount),
                    'vendor' => $lineItem['vendor'] ?? null,
                    'image_url' => $lineItem['image_url'] ?? $lineItem['featured_image'] ?? null,
                ], $orderContext);

                if ($lineRecord) {
                    $records[] = $lineRecord;
                }
            }
        }

        $nextCursor = $this->parseNextPageInfo($response->header('Link'));

        if ($nextCursor !== null) {
            return $this->result($records, $nextCursor, true);
        }

        if (! config('titan.shopify.analytics_enabled', true)) {
            return $this->result($records, null, false);
        }

        $analyticsStart = $this->resolveSinceDate($connection)->toDateString();

        return $this->result($records, "analytics:{$analyticsStart}", true);
    }

    protected function fetchAnalytics(Connection $connection, string $cursor): FetchResult
    {
        $credentials = $connection->credentials();
        $shop = $this->normalizeShopDomain((string) $credentials['shop_domain']);
        $token = (string) $credentials['access_token'];
        $chunkDays = max(1, (int) config('titan.shopify.analytics_chunk_days', 30));

        $start = Carbon::parse(substr($cursor, strlen('analytics:')));
        $end = $start->copy()->addDays($chunkDays - 1);

        if ($end->isFuture()) {
            $end = now()->startOfDay();
        }

        if ($start->gt($end)) {
            return $this->result([], null, false);
        }

        $client = new ShopifyAnalyticsClient(
            shop: $shop,
            http: $this->httpClient($token),
            apiVersion: (string) config('titan.shopify.analytics_api_version', '2025-10'),
        );

        $records = $client->sessionsBySourceMedium(
            $start->toDateString(),
            $end->toDateString(),
        );

        $nextStart = $end->copy()->addDay();
        $hasMore = $nextStart->lte(now()->startOfDay());
        $nextCursor = $hasMore ? 'analytics:'.$nextStart->toDateString() : null;

        return $this->result($records, $nextCursor, $hasMore);
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentialDebug(string $shopInput, string $shop, string $token, string $url): array
    {
        return [
            'shop_domain_input' => $shopInput,
            'shop_domain_resolved' => $shop,
            'request_url' => $url,
            'analytics_api_version' => (string) config('titan.shopify.analytics_api_version', '2025-10'),
            'token_length' => strlen($token),
            'token_preview' => $this->tokenPreview($token),
            'token_has_leading_or_trailing_whitespace' => $token !== trim($token),
            'token_looks_encrypted' => $this->tokenLooksEncrypted($token),
        ];
    }

    /**
     * @param  array<string, mixed>  $debug
     * @return array<string, mixed>
     */
    protected function failureDebug(array $debug, Response $response, ?string $hint = null): array
    {
        $debug['http_status'] = $response->status();
        $debug['shopify_error'] = $this->shopifyErrorMessage($response);

        if ($hint) {
            $debug['hint'] = $hint;
        }

        return $debug;
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    protected function logValidationFailure(Connection $connection, array $debug, Response $response): void
    {
        Log::warning('Shopify connection validation failed', [
            'connection_id' => $connection->exists ? $connection->id : null,
            'shop_domain_resolved' => $debug['shop_domain_resolved'] ?? null,
            'token_length' => $debug['token_length'] ?? null,
            'token_looks_encrypted' => $debug['token_looks_encrypted'] ?? null,
            'http_status' => $response->status(),
            'shopify_error' => $this->shopifyErrorMessage($response),
        ]);
    }

    protected function shopifyErrorMessage(Response $response): ?string
    {
        $errors = $response->json('errors');

        if (is_string($errors)) {
            return $errors;
        }

        if (is_array($errors)) {
            return json_encode($errors, JSON_UNESCAPED_SLASHES) ?: null;
        }

        $body = trim($response->body());

        return $body !== '' ? Str::limit($body, 500) : null;
    }

    protected function tokenPreview(string $token): string
    {
        if ($token === '') {
            return '(empty)';
        }

        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 4).'...'.substr($token, -4);
    }

    protected function tokenLooksEncrypted(string $token): bool
    {
        if (! str_starts_with($token, 'eyJ')) {
            return false;
        }

        $decoded = base64_decode($token, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && array_key_exists('iv', $payload)
            && array_key_exists('value', $payload);
    }

    protected function normalizeShopDomain(string $shop): string
    {
        $shop = Str::replace(['https://', 'http://'], '', trim($shop));
        $shop = rtrim($shop, '/');

        if (! str_contains($shop, '.')) {
            return $shop.'.myshopify.com';
        }

        return $shop;
    }

    protected function httpClient(string $token): ShopifyHttpClient
    {
        return new ShopifyHttpClient($token);
    }

    protected function parseNextPageInfo(?string $linkHeader): ?string
    {
        if (! $linkHeader) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $link) {
            if (! str_contains($link, 'rel="next"')) {
                continue;
            }

            if (preg_match('/page_info=([^>&]+)/', $link, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
