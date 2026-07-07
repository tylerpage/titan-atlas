<?php

namespace App\Ingestion;

use App\Contracts\Ingestion\ConnectorInterface;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\BigCommerceConnector;
use App\Ingestion\Connectors\DynamicConnector;
use App\Ingestion\Connectors\GoogleAdsConnector;
use App\Ingestion\Connectors\GoogleAnalyticsConnector;
use App\Ingestion\Connectors\MetaAdsConnector;
use App\Ingestion\Connectors\RedditAdsConnector;
use App\Ingestion\Connectors\SearchConsoleConnector;
use App\Ingestion\Connectors\SemrushConnector;
use App\Ingestion\Connectors\ShopifyConnector;
use App\Ingestion\Connectors\StackAdaptConnector;
use InvalidArgumentException;

class ConnectorRegistry
{
    /**
     * @return array<string, class-string<ConnectorInterface>>
     */
    public function connectors(): array
    {
        return [
            ConnectorType::Shopify->value => ShopifyConnector::class,
            ConnectorType::BigCommerce->value => BigCommerceConnector::class,
            ConnectorType::GoogleAds->value => GoogleAdsConnector::class,
            ConnectorType::SearchConsole->value => SearchConsoleConnector::class,
            ConnectorType::GoogleAnalytics->value => GoogleAnalyticsConnector::class,
            ConnectorType::Semrush->value => SemrushConnector::class,
            ConnectorType::StackAdapt->value => StackAdaptConnector::class,
            ConnectorType::RedditAds->value => RedditAdsConnector::class,
            ConnectorType::MetaAds->value => MetaAdsConnector::class,
            ConnectorType::Dynamic->value => DynamicConnector::class,
        ];
    }

    public function make(ConnectorType|string $type): ConnectorInterface
    {
        $key = $type instanceof ConnectorType ? $type->value : $type;
        $class = $this->connectors()[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown connector type [{$key}].");
        }

        return app($class);
    }
}
