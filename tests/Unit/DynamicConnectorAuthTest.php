<?php

namespace Tests\Unit;

use App\Support\DynamicConnectorAuth;
use Tests\TestCase;

class DynamicConnectorAuthTest extends TestCase
{
    public function test_it_normalizes_oauth2_client_credentials_auth_config(): void
    {
        $normalized = DynamicConnectorAuth::normalize([
            'type' => 'oauth2_client_credentials',
            'token_url' => '/api/oauth/token',
        ]);

        $this->assertSame('oauth2_client_credentials', $normalized['type']);
        $this->assertSame('/api/oauth/token', $normalized['token_request']['path']);
        $this->assertSame('client_credentials', $normalized['token_request']['body']['grant_type']);
        $this->assertSame('{{client_id}}', $normalized['token_request']['body']['client_id']);
        $this->assertSame('{{client_secret}}', $normalized['token_request']['body']['client_secret']);
    }

    public function test_it_builds_default_credential_schema_for_oauth2_client_credentials(): void
    {
        $schema = DynamicConnectorAuth::normalizeCredentialSchema(null, [
            'type' => 'oauth2_client_credentials',
        ]);

        $this->assertCount(2, $schema);
        $this->assertSame('client_id', $schema[0]['key']);
        $this->assertSame('client_secret', $schema[1]['key']);
    }

    public function test_it_leaves_bearer_auth_config_unchanged(): void
    {
        $auth = [
            'type' => 'bearer',
            'credential_key' => 'access_token',
        ];

        $this->assertSame($auth, DynamicConnectorAuth::normalize($auth));
    }
}
