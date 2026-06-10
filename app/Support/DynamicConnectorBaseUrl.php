<?php

namespace App\Support;

use App\Models\ConnectorBlueprint;

class DynamicConnectorBaseUrl
{
    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $sessionConfig
     */
    public static function resolve(ConnectorBlueprint $blueprint, array $credentials = [], ?array $sessionConfig = null): string
    {
        foreach ([
            $credentials['base_url'] ?? null,
            $sessionConfig['base_url'] ?? null,
            $blueprint->sync_config['base_url'] ?? null,
        ] as $url) {
            if (is_string($url) && trim($url) !== '') {
                return rtrim(trim($url), '/');
            }
        }

        return '';
    }

    public static function requiresPerDashboard(ConnectorBlueprint $blueprint): bool
    {
        if ((bool) ($blueprint->sync_config['require_base_url_per_dashboard'] ?? false)) {
            return true;
        }

        $template = (string) ($blueprint->sync_config['base_url'] ?? '');

        return $template === '' || str_contains($template, '{{');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $sessionConfig
     */
    public static function assertAvailable(
        ConnectorBlueprint $blueprint,
        array $credentials = [],
        ?array $sessionConfig = null,
    ): void {
        if (self::resolve($blueprint, $credentials, $sessionConfig) === '') {
            throw new \RuntimeException('A base URL is required for this connector. Provide the shop or API base URL for this dashboard.');
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $sessionConfig
     * @return array<string, mixed>
     */
    public static function mergeIntoCredentials(
        array $credentials,
        ?array $sessionConfig,
        ConnectorBlueprint $blueprint,
    ): array {
        $baseUrl = self::resolve($blueprint, $credentials, $sessionConfig);

        if ($baseUrl !== '') {
            $credentials['base_url'] = $baseUrl;
        }

        return $credentials;
    }
}
