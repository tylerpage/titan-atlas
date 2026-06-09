<?php

namespace App\Ingestion\Connectors;

use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Models\Connection;
use Illuminate\Support\Facades\Http;

class BigCommerceConnector extends AbstractCommerceConnector
{
    public function type(): string
    {
        return ConnectorType::BigCommerce->value;
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        if (empty($credentials['store_hash']) || empty($credentials['access_token'])) {
            return ValidationResult::fail('BigCommerce requires store_hash and access_token.');
        }

        $storeHash = (string) $credentials['store_hash'];
        $token = (string) $credentials['access_token'];

        $response = Http::withHeaders([
            'X-Auth-Token' => $token,
            'Accept' => 'application/json',
        ])->get("https://api.bigcommerce.com/stores/{$storeHash}/v2/store");

        if ($response->status() === 401) {
            return ValidationResult::fail('Invalid access token.');
        }

        if ($response->status() === 404) {
            return ValidationResult::fail('Store not found. Check the store hash.');
        }

        if (! $response->successful()) {
            return ValidationResult::fail('Could not connect to BigCommerce (HTTP '.$response->status().').');
        }

        $name = $response->json('name');

        return $name
            ? new ValidationResult(true, "Connected to {$name}")
            : ValidationResult::ok();
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $credentials = $connection->credentials();
        $storeHash = (string) $credentials['store_hash'];
        $token = (string) $credentials['access_token'];
        $pageSize = min(250, max(1, (int) config('titan.commerce.orders_page_size', 50)));
        $page = max(1, (int) ($cursor ?? 1));

        $query = [
            'limit' => $pageSize,
            'page' => $page,
            'min_date_created' => $this->resolveSinceDate($connection)->toIso8601String(),
        ];

        $url = "https://api.bigcommerce.com/stores/{$storeHash}/v2/orders?".http_build_query($query);

        $response = Http::withHeaders([
            'X-Auth-Token' => $token,
            'Accept' => 'application/json',
        ])->get($url);

        $response->throw();

        $orders = $response->json() ?? [];
        unset($response);

        if (! is_array($orders)) {
            $orders = [];
        }

        $records = [];

        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }

            $orderId = (string) ($order['id'] ?? '');

            $normalized = $this->normalizeOrder([
                'external_id' => $orderId,
                'created_at' => $order['date_created'] ?? null,
                'total' => $order['total_inc_tax'] ?? $order['total'] ?? 0,
                'order_number' => $orderId !== '' ? '#'.$orderId : null,
                'channel' => $order['order_source'] ?? $order['external_source'] ?? null,
                'referring_site' => $order['referrer_url'] ?? null,
                'landing_site' => $order['landing_site'] ?? null,
                'currency' => $order['currency_code'] ?? $order['default_currency_code'] ?? null,
            ]);

            if (! $normalized) {
                continue;
            }

            $records[] = $normalized;

            foreach ($this->fetchOrderLineItems($storeHash, $token, $orderId) as $lineItem) {
                $lineRecord = $this->normalizeLineItemRecord($lineItem, $this->orderContextFromNormalizedOrder($normalized));

                if ($lineRecord) {
                    $records[] = $lineRecord;
                }
            }
        }

        $orderCount = count($orders);
        unset($orders);

        // Paginate on orders returned by BigCommerce, not total records (orders + line items).
        $hasMore = $orderCount === $pageSize;
        $nextCursor = $hasMore ? (string) ($page + 1) : null;

        return $this->result($records, $nextCursor, $hasMore);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchOrderLineItems(string $storeHash, string $token, string $orderId): array
    {
        if (! $this->lineItemsEnabled() || $orderId === '') {
            return [];
        }

        $response = Http::withHeaders([
            'X-Auth-Token' => $token,
            'Accept' => 'application/json',
        ])->get("https://api.bigcommerce.com/stores/{$storeHash}/v2/orders/{$orderId}/products");

        if (! $response->successful()) {
            return [];
        }

        $products = $response->json() ?? [];

        if (! is_array($products)) {
            return [];
        }

        $lines = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $quantity = (int) ($product['quantity'] ?? 0);
            $unitPrice = (float) ($product['base_price'] ?? $product['price_ex_tax'] ?? $product['price_inc_tax'] ?? 0);
            $lineTotal = (float) ($product['total_inc_tax'] ?? $product['total_ex_tax'] ?? ($quantity * $unitPrice));
            $discount = max(0, ($quantity * $unitPrice) - $lineTotal);

            $lines[] = [
                'id' => $product['id'] ?? null,
                'product_id' => $product['product_id'] ?? null,
                'variant_id' => $product['variant_id'] ?? null,
                'sku' => $product['sku'] ?? $product['upc'] ?? null,
                'name' => $product['name'] ?? null,
                'variant_title' => $product['name_customer'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'compare_at_price' => isset($product['base_price']) ? (float) $product['base_price'] : null,
                'discount_amount' => $discount > 0 ? $discount : null,
                'line_total' => $lineTotal,
                'sale_price' => $quantity > 0 ? $lineTotal / $quantity : $unitPrice,
                'image_url' => $product['image_url'] ?? null,
            ];
        }

        return $lines;
    }
}
