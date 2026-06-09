<?php

namespace App\Ingestion\Connectors;

use App\Data\Ingestion\FetchResult;
use App\Models\Connection;
use Carbon\Carbon;
use Illuminate\Support\Str;

abstract class AbstractCommerceConnector extends AbstractConnector
{
    protected function resolveSinceDate(Connection $connection): Carbon
    {
        $years = (int) config('titan.commerce.backfill_years', 2);
        $earliest = now()->subYears($years)->startOfDay();

        if ($connection->backfill_completed_at === null) {
            return $earliest;
        }

        if ($connection->last_synced_at) {
            return $connection->last_synced_at->copy()->subDay()->startOfDay();
        }

        return $earliest;
    }

    protected function lineItemsEnabled(): bool
    {
        return (bool) config('titan.commerce.line_items_enabled', true);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{resource_type: string, external_id: string, payload: array<string, mixed>}|null
     */
    protected function normalizeOrder(array $order): ?array
    {
        $externalId = (string) ($order['external_id'] ?? '');

        if ($externalId === '') {
            return null;
        }

        $date = Carbon::parse($order['created_at'] ?? now())->toDateString();
        [$source, $medium] = $this->resolveSourceMedium($order);

        return [
            'resource_type' => 'order',
            'external_id' => $externalId,
            'payload' => array_filter([
                'date' => $date,
                'total' => (float) ($order['total'] ?? 0),
                'order_number' => $order['order_number'] ?? null,
                'source' => $source,
                'medium' => $medium,
                'source_medium' => ($source && $medium) ? "{$source} / {$medium}" : null,
                'channel' => $order['channel'] ?? null,
                'referring_site' => $order['referring_site'] ?? null,
                'currency' => $order['currency'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $orderContext
     * @return array{resource_type: string, external_id: string, payload: array<string, mixed>}|null
     */
    protected function normalizeLineItem(array $line, array $orderContext): ?array
    {
        $lineItemId = (string) ($line['line_item_id'] ?? $line['id'] ?? '');
        $orderId = (string) ($orderContext['order_id'] ?? '');

        if ($lineItemId === '' || $orderId === '') {
            return null;
        }

        $quantity = max(0, (int) ($line['quantity'] ?? 0));

        if ($quantity === 0) {
            return null;
        }

        $unitPrice = (float) ($line['unit_price'] ?? $line['price'] ?? 0);
        $discountAmount = (float) ($line['discount_amount'] ?? $line['total_discount'] ?? 0);
        $lineTotal = (float) ($line['line_total'] ?? max(0, ($quantity * $unitPrice) - $discountAmount));
        $salePrice = (float) ($line['sale_price'] ?? ($quantity > 0 ? $lineTotal / $quantity : $unitPrice));

        return [
            'resource_type' => 'order_line_item',
            'external_id' => "{$orderId}:{$lineItemId}",
            'payload' => array_filter([
                'order_id' => $orderId,
                'order_number' => $orderContext['order_number'] ?? null,
                'line_item_id' => $lineItemId,
                'date' => $orderContext['date'] ?? null,
                'product_id' => isset($line['product_id']) ? (string) $line['product_id'] : null,
                'variant_id' => isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                'sku' => $line['sku'] ?? null,
                'name' => $line['name'] ?? null,
                'variant_title' => $line['variant_title'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'compare_at_price' => isset($line['compare_at_price']) ? (float) $line['compare_at_price'] : null,
                'sale_price' => $salePrice,
                'discount_amount' => $discountAmount > 0 ? $discountAmount : null,
                'line_total' => $lineTotal,
                'image_url' => $line['image_url'] ?? null,
                'vendor' => $line['vendor'] ?? null,
                'source' => $orderContext['source'] ?? null,
                'medium' => $orderContext['medium'] ?? null,
                'source_medium' => $orderContext['source_medium'] ?? null,
                'channel' => $orderContext['channel'] ?? null,
                'currency' => $orderContext['currency'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array{resource_type: string, external_id: string, payload: array<string, mixed>}  $order
     * @return array<string, mixed>
     */
    protected function orderContextFromNormalizedOrder(array $order): array
    {
        $payload = $order['payload'];

        return [
            'order_id' => $order['external_id'],
            'order_number' => $payload['order_number'] ?? null,
            'date' => $payload['date'] ?? null,
            'source' => $payload['source'] ?? null,
            'medium' => $payload['medium'] ?? null,
            'source_medium' => $payload['source_medium'] ?? null,
            'channel' => $payload['channel'] ?? null,
            'currency' => $payload['currency'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $orderContext
     * @return array{resource_type: string, external_id: string, payload: array<string, mixed>}|null
     */
    protected function normalizeLineItemRecord(array $line, array $orderContext): ?array
    {
        if (! $this->lineItemsEnabled()) {
            return null;
        }

        return $this->normalizeLineItem($line, $orderContext);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveSourceMedium(array $order): array
    {
        $source = $order['source'] ?? null;
        $medium = $order['medium'] ?? null;

        if ($source || $medium) {
            return [$source, $medium];
        }

        $landingSite = (string) ($order['landing_site'] ?? '');

        if ($landingSite !== '') {
            $query = parse_url($landingSite, PHP_URL_QUERY);

            if (is_string($query)) {
                parse_str($query, $params);

                return [
                    isset($params['utm_source']) ? (string) $params['utm_source'] : null,
                    isset($params['utm_medium']) ? (string) $params['utm_medium'] : null,
                ];
            }
        }

        $referrer = (string) ($order['referring_site'] ?? '');

        if ($referrer !== '') {
            $host = parse_url($referrer, PHP_URL_HOST);

            return [$host ? Str::lower($host) : $referrer, 'referral'];
        }

        return [null, null];
    }

    /**
     * @param  list<array{resource_type: string, external_id?: string|null, payload: array<string, mixed>}>  $records
     */
    protected function result(array $records, ?string $nextCursor, bool $hasMore): FetchResult
    {
        return new FetchResult(
            records: $records,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
        );
    }
}
