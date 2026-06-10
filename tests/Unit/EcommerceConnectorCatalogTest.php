<?php

namespace Tests\Unit;

use App\Support\EcommerceConnectorCatalog;
use Tests\TestCase;

class EcommerceConnectorCatalogTest extends TestCase
{
    public function test_it_lists_supported_ecommerce_recipes(): void
    {
        $catalog = app(EcommerceConnectorCatalog::class);

        $supported = collect($catalog->supportedPlatforms('ecommerce'))
            ->pluck('slug')
            ->all();

        $this->assertContains('shopware', $supported);
        $this->assertContains('magento', $supported);
        $this->assertContains('woocommerce', $supported);
        $this->assertContains('miva', $supported);
    }

    public function test_it_returns_blueprint_template_for_magento(): void
    {
        $catalog = app(EcommerceConnectorCatalog::class);

        $result = $catalog->lookup('magento');

        $this->assertTrue($result['success']);
        $this->assertSame('magento', $result['platform']['slug']);
        $this->assertSame('order', $result['platform']['blueprint_template']['streams'][0]['resource_type']);
        $this->assertSame('bearer', $result['platform']['blueprint_template']['auth_config']['type']);
    }

    public function test_it_returns_supported_platform_index_without_query(): void
    {
        $catalog = app(EcommerceConnectorCatalog::class);

        $result = $catalog->lookup(null);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['supported_platforms']);
    }
}
