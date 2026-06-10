<?php

namespace Tests\Unit;

use App\Support\DynamicConnectorReadOnlyGuard;
use Tests\TestCase;

class DynamicConnectorReadOnlyGuardTest extends TestCase
{
    public function test_it_defaults_streams_to_get(): void
    {
        $guard = app(DynamicConnectorReadOnlyGuard::class);

        $sanitized = $guard->sanitizeStream([
            'stream_key' => 'deals',
            'path_template' => '/deals',
        ]);

        $this->assertSame('GET', $sanitized['http_method']);
    }

    public function test_it_allows_post_for_read_streams(): void
    {
        $guard = app(DynamicConnectorReadOnlyGuard::class);

        $sanitized = $guard->sanitizeStream([
            'stream_key' => 'search',
            'http_method' => 'POST',
            'path_template' => '/search',
        ]);

        $this->assertSame('POST', $sanitized['http_method']);
    }

    public function test_it_rejects_mutating_stream_methods(): void
    {
        $guard = app(DynamicConnectorReadOnlyGuard::class);

        $this->expectException(\InvalidArgumentException::class);

        $guard->sanitizeStream([
            'stream_key' => 'deals',
            'http_method' => 'DELETE',
            'path_template' => '/deals',
        ]);
    }

    public function test_it_rejects_mutating_http_requests(): void
    {
        $guard = app(DynamicConnectorReadOnlyGuard::class);

        $this->expectException(\InvalidArgumentException::class);

        $guard->assertHttpMethodAllowed('DELETE');
    }

    public function test_it_detects_write_intent_in_user_prompts(): void
    {
        $guard = app(DynamicConnectorReadOnlyGuard::class);

        $this->assertTrue($guard->detectsWriteIntent('Create new HubSpot contacts when form submits'));
        $this->assertTrue($guard->detectsWriteIntent('Update HubSpot deals from our dashboard'));
        $this->assertFalse($guard->detectsWriteIntent('Connect HubSpot and import contacts for reporting'));
        $this->assertFalse($guard->detectsWriteIntent('Use POST to authenticate with client credentials'));
    }

    public function test_it_does_not_treat_internal_dashboard_requests_as_external_write_intent(): void
    {
        $guard = app(DynamicConnectorReadOnlyGuard::class);

        $this->assertTrue($guard->detectsInternalDashboardIntent('Can you create a dashboard for this connection'));
        $this->assertFalse($guard->detectsWriteIntent('Yes, please attempt to create the dashboard again'));
        $this->assertFalse($guard->detectsWriteIntent('Build analytics widgets on the saved dashboard'));
    }

    public function test_agent_message_adds_read_only_reminder_for_write_intent(): void
    {
        $service = app(\App\Services\AI\ConnectorBuilderAgentService::class);

        $ref = new \ReflectionMethod($service, 'agentMessage');
        $ref->setAccessible(true);

        $message = $ref->invoke($service, 'Delete inactive HubSpot contacts nightly');

        $this->assertStringContainsString('READ-ONLY ENFORCEMENT', $message);
        $this->assertStringContainsString('Delete inactive HubSpot contacts nightly', $message);
    }
}
