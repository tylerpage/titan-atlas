<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_stores_pending_session_and_redirects_to_create_form(): void
    {
        config([
            'titan.google.client_id' => 'test-client-id',
            'titan.google.client_secret' => 'test-client-secret',
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $oauth = app(GoogleOAuthService::class);
        $state = $this->invokeProtected($oauth, 'encodeState', [[
            'connector_type' => ConnectorType::SearchConsole->value,
            'dashboard_id' => $dashboard->id,
            'connection_id' => null,
            'return_to' => 'create',
        ]]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ], 200),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.google.oauth.callback', [
                'code' => 'auth-code',
                'state' => $state,
            ]));

        $response->assertRedirect(route('admin.dashboards.connections.create', $dashboard));

        $pending = session('google_oauth_pending');

        $this->assertIsArray($pending);
        $this->assertSame('refresh-token', $pending['refresh_token']);
        $this->assertSame($dashboard->id, $pending['dashboard_id']);
        $this->assertCount(1, $pending['sites']);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    protected function invokeProtected(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
