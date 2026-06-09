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

    public function test_google_analytics_credentials_include_oauth_guidance(): void
    {
        $fields = ConnectorType::GoogleAnalytics->credentialFields();

        $this->assertNotNull(ConnectorType::GoogleAnalytics->credentialAccessSummary());
        $this->assertStringContainsString('Google Analytics Data API', ConnectorType::GoogleAnalytics->credentialAccessSummary());
        $this->assertStringContainsString('Search Console', ConnectorType::GoogleAnalytics->credentialAccessSummary());
        $this->assertTrue(ConnectorType::GoogleAnalytics->usesGoogleOAuth());
        $this->assertTrue(ConnectorType::GoogleAnalytics->supportsLiveConnectionTest());

        $propertyId = collect($fields)->firstWhere('key', 'property_id');
        $refreshToken = collect($fields)->firstWhere('key', 'refresh_token');

        $this->assertStringContainsString('GA4 property', $propertyId['help']);
        $this->assertSame('oauth_hidden', $refreshToken['type']);
    }

    public function test_google_ads_credentials_include_oauth_guidance(): void
    {
        $fields = ConnectorType::GoogleAds->credentialFields();

        $this->assertNotNull(ConnectorType::GoogleAds->credentialAccessSummary());
        $this->assertStringContainsString('Google Ads API', ConnectorType::GoogleAds->credentialAccessSummary());
        $this->assertStringContainsString('GOOGLE_ADS_DEVELOPER_TOKEN', ConnectorType::GoogleAds->credentialAccessSummary());
        $this->assertTrue(ConnectorType::GoogleAds->usesGoogleOAuth());
        $this->assertTrue(ConnectorType::GoogleAds->supportsLiveConnectionTest());

        $customerId = collect($fields)->firstWhere('key', 'customer_id');
        $refreshToken = collect($fields)->firstWhere('key', 'refresh_token');

        $this->assertStringContainsString('Ads account', $customerId['help']);
        $this->assertSame('oauth_hidden', $refreshToken['type']);
        $this->assertContains('login_customer_id', ConnectorType::GoogleAds->optionalCredentialKeys());
    }

    public function test_stackadapt_credentials_include_graphql_guidance(): void
    {
        $fields = ConnectorType::StackAdapt->credentialFields();

        $this->assertNotNull(ConnectorType::StackAdapt->credentialAccessSummary());
        $this->assertStringContainsString('GraphQL', ConnectorType::StackAdapt->credentialAccessSummary());
        $this->assertTrue(ConnectorType::StackAdapt->supportsLiveConnectionTest());
        $this->assertContains('rest_api_key', ConnectorType::StackAdapt->optionalCredentialKeys());

        $graphqlKey = collect($fields)->firstWhere('key', 'graphql_api_key');
        $advertiserId = collect($fields)->firstWhere('key', 'advertiser_id');

        $this->assertSame('password', $graphqlKey['type']);
        $this->assertStringContainsString('Test connection', $advertiserId['help']);
    }
}
