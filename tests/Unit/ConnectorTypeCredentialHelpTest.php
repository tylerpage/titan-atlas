<?php

namespace Tests\Unit;

use App\Enums\ConnectorType;
use Tests\TestCase;

class ConnectorTypeCredentialHelpTest extends TestCase
{
    public function test_shopify_credentials_include_access_guidance(): void
    {
        $fields = ConnectorType::Shopify->credentialFields();

        $this->assertNotNull(ConnectorType::Shopify->credentialAccessSummary());
        $this->assertStringContainsString('read_orders', ConnectorType::Shopify->credentialAccessSummary());

        $shopDomain = collect($fields)->firstWhere('key', 'shop_domain');
        $accessToken = collect($fields)->firstWhere('key', 'access_token');

        $this->assertStringContainsString('myshopify.com', $shopDomain['help']);
        $this->assertStringContainsString('Settings → Domains', $shopDomain['help']);
        $this->assertSame('Admin API access token', $accessToken['label']);
        $this->assertStringContainsString('Admin API access token', $accessToken['help']);
        $this->assertStringContainsString('read_reports', $accessToken['help']);
    }

    public function test_bigcommerce_credentials_include_access_guidance(): void
    {
        $fields = ConnectorType::BigCommerce->credentialFields();

        $this->assertNotNull(ConnectorType::BigCommerce->credentialAccessSummary());
        $this->assertStringContainsString('Orders', ConnectorType::BigCommerce->credentialAccessSummary());

        $storeHash = collect($fields)->firstWhere('key', 'store_hash');
        $accessToken = collect($fields)->firstWhere('key', 'access_token');

        $this->assertStringContainsString('store_hash', $storeHash['help']);
        $this->assertStringContainsString('Settings → API', $storeHash['help']);
        $this->assertStringContainsString('Orders', $accessToken['help']);
        $this->assertStringContainsString('X-Auth-Token', $accessToken['help']);
    }

    public function test_search_console_credentials_include_oauth_guidance(): void
    {
        $fields = ConnectorType::SearchConsole->credentialFields();

        $this->assertNotNull(ConnectorType::SearchConsole->credentialAccessSummary());
        $this->assertStringContainsString('Search Console API', ConnectorType::SearchConsole->credentialAccessSummary());
        $this->assertStringContainsString('GOOGLE_CLIENT_ID', ConnectorType::SearchConsole->credentialAccessSummary());
        $this->assertTrue(ConnectorType::SearchConsole->usesGoogleOAuth());
        $this->assertTrue(ConnectorType::SearchConsole->supportsLiveConnectionTest());

        $siteUrl = collect($fields)->firstWhere('key', 'site_url');
        $refreshToken = collect($fields)->firstWhere('key', 'refresh_token');

        $this->assertStringContainsString('sc-domain', $siteUrl['help']);
        $this->assertSame('oauth_hidden', $refreshToken['type']);
    }
}
