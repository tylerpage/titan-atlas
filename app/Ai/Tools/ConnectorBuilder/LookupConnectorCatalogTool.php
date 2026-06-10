<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Support\EcommerceConnectorCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class LookupConnectorCatalogTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected EcommerceConnectorCatalog $catalog,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Look up curated dynamic connector recipes for ecommerce and marketing platforms. Returns auth_config, streams, payload fields, and blueprint templates for supported platforms such as Shopware, Magento, WooCommerce, and Miva.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request->string('query')->toString();
        $category = $request->string('category')->toString();

        return $this->json($this->catalog->lookup(
            query: $query !== '' ? $query : null,
            category: $category !== '' ? $category : null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Platform slug or name, e.g. shopware, magento, woocommerce, miva.'),
            'category' => $schema->string()->description('Optional category filter: ecommerce, advertising, email_sms, analytics, support_subscription.'),
        ];
    }
}
