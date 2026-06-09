<?php

namespace App\Enums;

enum ConnectorType: string
{
    case Shopify = 'shopify';
    case BigCommerce = 'bigcommerce';
    case GoogleAds = 'google_ads';
    case SearchConsole = 'search_console';
    case GoogleAnalytics = 'google_analytics';
    case Semrush = 'semrush';

    public function label(): string
    {
        return match ($this) {
            self::Shopify => 'Shopify',
            self::BigCommerce => 'BigCommerce',
            self::GoogleAds => 'Google Ads',
            self::SearchConsole => 'Google Search Console',
            self::GoogleAnalytics => 'Google Analytics 4',
            self::Semrush => 'SEMrush',
        };
    }

    /**
     * @return list<array{key: string, label: string, type?: string, placeholder?: string, help?: string}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Shopify => [
                [
                    'key' => 'shop_domain',
                    'label' => 'Shop domain',
                    'placeholder' => 'store.myshopify.com',
                    'help' => 'Your *.myshopify.com hostname from Shopify Admin → Settings → Domains. Use the myshopify.com domain, not a custom storefront URL (e.g. use store.myshopify.com, not www.yourbrand.com).',
                ],
                [
                    'key' => 'access_token',
                    'label' => 'Admin API access token',
                    'type' => 'password',
                    'help' => 'From a custom app: Shopify Admin → Settings → Apps → Develop apps → create/install an app → API credentials → Admin API access token. Not a storefront or theme token. Required scopes: read_orders, read_reports.',
                ],
            ],
            self::BigCommerce => [
                [
                    'key' => 'store_hash',
                    'label' => 'Store hash',
                    'placeholder' => 'abc123',
                    'help' => 'The store ID in BigCommerce API URLs (api.bigcommerce.com/stores/{store_hash}/...). Find it in Settings → API → API Accounts when creating credentials, or in the store path BigCommerce shows for your account.',
                ],
                [
                    'key' => 'access_token',
                    'label' => 'API access token',
                    'type' => 'password',
                    'help' => 'Create a store-level API account in Settings → API with Orders (read-only) access. Copy the access token BigCommerce generates — this is sent as the X-Auth-Token header.',
                ],
            ],
            self::GoogleAds => [
                ['key' => 'customer_id', 'label' => 'Customer ID', 'placeholder' => '1234567890'],
                ['key' => 'refresh_token', 'label' => 'Refresh token', 'type' => 'password'],
            ],
            self::SearchConsole => [
                [
                    'key' => 'site_url',
                    'label' => 'Search Console property',
                    'placeholder' => 'https://example.com/',
                    'help' => 'Choose a verified property after connecting with Google. URL-prefix properties use a trailing slash (https://example.com/). Domain properties use sc-domain:example.com.',
                ],
                [
                    'key' => 'refresh_token',
                    'label' => 'Google refresh token',
                    'type' => 'oauth_hidden',
                ],
            ],
            self::GoogleAnalytics => [
                ['key' => 'property_id', 'label' => 'GA4 property ID', 'placeholder' => '123456789'],
                ['key' => 'refresh_token', 'label' => 'Refresh token', 'type' => 'password'],
            ],
            self::Semrush => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password'],
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        return array_column($this->credentialFields(), 'key');
    }

    public function isCommerce(): bool
    {
        return in_array($this, [self::Shopify, self::BigCommerce], true);
    }

    public function supportsLiveConnectionTest(): bool
    {
        return in_array($this, [self::Shopify, self::BigCommerce, self::SearchConsole], true);
    }

    public function usesGoogleOAuth(): bool
    {
        return $this === self::SearchConsole;
    }

    /**
     * @return list<string>
     */
    public function oauthHiddenCredentialKeys(): array
    {
        return match ($this) {
            self::SearchConsole => ['refresh_token'],
            default => [],
        };
    }

    public function credentialAccessSummary(): ?string
    {
        return match ($this) {
            self::Shopify => self::productName().' syncs orders and session attribution from Shopify using the Admin API. You need a custom app Admin API access token with read_orders and read_reports scopes.',
            self::BigCommerce => self::productName().' syncs orders from BigCommerce using the v2 Orders API. You need a store API account with Orders (read-only) permission.',
            self::SearchConsole => self::productName().' syncs Google Search Console search analytics: daily site totals, query (keyword) performance, and landing-page metrics. Platform setup: enable the Search Console API in Google Cloud, create an OAuth web client, add redirect URI '.route('admin.google.oauth.callback', absolute: true).', and set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in '.self::productName().'. Then click Connect with Google — you must have owner, full, or restricted access on the property.',
            default => null,
        };
    }

    public static function productName(): string
    {
        return (string) config('app.name', 'Atlas');
    }

    /**
     * @return array{value: string, label: string, fields: list<array<string, mixed>>, access_summary: string|null, supports_test: bool, uses_google_oauth: bool}
     */
    public function toConnectorOption(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'fields' => $this->credentialFields(),
            'access_summary' => $this->credentialAccessSummary(),
            'supports_test' => $this->supportsLiveConnectionTest(),
            'uses_google_oauth' => $this->usesGoogleOAuth(),
        ];
    }
}
