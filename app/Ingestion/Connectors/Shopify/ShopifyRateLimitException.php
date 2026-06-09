<?php

namespace App\Ingestion\Connectors\Shopify;

use RuntimeException;

class ShopifyRateLimitException extends RuntimeException
{
    public function __construct(
        string $message = 'Rate limited. Please retry later.',
        public readonly int $retryAfterSeconds = 60,
    ) {
        parent::__construct($message);
    }
}
