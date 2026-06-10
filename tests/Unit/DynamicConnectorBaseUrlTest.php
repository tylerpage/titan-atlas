<?php

namespace Tests\Unit;

use App\Models\ConnectorBlueprint;
use App\Support\DynamicConnectorBaseUrl;
use Tests\TestCase;

class DynamicConnectorBaseUrlTest extends TestCase
{
    public function test_resolve_prefers_credentials_over_session_and_blueprint(): void
    {
        $blueprint = new ConnectorBlueprint([
            'sync_config' => ['base_url' => 'https://blueprint.example.com'],
        ]);

        $resolved = DynamicConnectorBaseUrl::resolve(
            $blueprint,
            ['base_url' => 'https://credentials.example.com'],
            ['base_url' => 'https://session.example.com'],
        );

        $this->assertSame('https://credentials.example.com', $resolved);
    }

    public function test_resolve_uses_session_config_when_credentials_missing(): void
    {
        $blueprint = new ConnectorBlueprint([
            'sync_config' => ['base_url' => 'https://blueprint.example.com'],
        ]);

        $resolved = DynamicConnectorBaseUrl::resolve(
            $blueprint,
            [],
            ['base_url' => 'https://session.example.com/'],
        );

        $this->assertSame('https://session.example.com', $resolved);
    }

    public function test_requires_per_dashboard_when_flag_set_or_base_url_empty(): void
    {
        $required = new ConnectorBlueprint([
            'sync_config' => ['require_base_url_per_dashboard' => true, 'base_url' => 'https://ignored.example.com'],
        ]);

        $empty = new ConnectorBlueprint([
            'sync_config' => [],
        ]);

        $fixed = new ConnectorBlueprint([
            'sync_config' => ['base_url' => 'https://fixed.example.com'],
        ]);

        $this->assertTrue(DynamicConnectorBaseUrl::requiresPerDashboard($required));
        $this->assertTrue(DynamicConnectorBaseUrl::requiresPerDashboard($empty));
        $this->assertFalse(DynamicConnectorBaseUrl::requiresPerDashboard($fixed));
    }

    public function test_merge_into_credentials_includes_resolved_base_url(): void
    {
        $blueprint = new ConnectorBlueprint([
            'sync_config' => ['base_url' => 'https://blueprint.example.com'],
        ]);

        $merged = DynamicConnectorBaseUrl::mergeIntoCredentials(
            ['client_id' => 'abc'],
            ['base_url' => 'https://session.example.com'],
            $blueprint,
        );

        $this->assertSame('https://session.example.com', $merged['base_url']);
        $this->assertSame('abc', $merged['client_id']);
    }
}
