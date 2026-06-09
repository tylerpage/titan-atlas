<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\GoogleOAuthPending;
use App\Models\User;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_stores_pending_record_and_redirects_to_create_form(): void
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
            'user_id' => $admin->id,
        ]]);

        $this->fakeGoogleOAuthApis();

        $response = $this->actingAs($admin)
            ->get(route('admin.google.oauth.callback', [
                'code' => 'auth-code',
                'state' => $state,
            ]));

        $response->assertRedirect(route('admin.dashboards.connections.create', $dashboard));

        $this->assertDatabaseHas('google_oauth_pendings', [
            'user_id' => $admin->id,
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::SearchConsole->value,
            'google_email' => 'search@example.com',
            'google_name' => 'Search User',
        ]);

        $createPage = $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.create', $dashboard));

        $createPage->assertOk();
        $createPage->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboards/Connections/Create')
            ->where('defaultConnectorType', ConnectorType::SearchConsole->value)
            ->where('googleOauth.connected', true)
            ->where('googleOauth.google_email', 'search@example.com')
            ->where('googleOauth.google_name', 'Search User')
            ->has('googleOauth.sites', 1));
    }

    public function test_oauth_callback_stores_pending_when_session_is_lost(): void
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
            'user_id' => $admin->id,
        ]]);

        $this->fakeGoogleOAuthApis();

        $response = $this->get(route('admin.google.oauth.callback', [
            'code' => 'auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect(route('login'));

        $this->assertDatabaseHas('google_oauth_pendings', [
            'user_id' => $admin->id,
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::SearchConsole->value,
        ]);
    }

    public function test_create_form_reads_pending_oauth_from_database(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        GoogleOAuthPending::query()->create([
            'user_id' => $admin->id,
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::SearchConsole->value,
            'refresh_token' => 'refresh-token',
            'google_email' => 'client@example.com',
            'google_name' => 'Client User',
            'sites' => [
                ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.create', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultConnectorType', ConnectorType::SearchConsole->value)
                ->where('googleOauth.connected', true)
                ->where('googleOauth.google_email', 'client@example.com')
                ->where('googleOauth.google_name', 'Client User')
                ->where('googleOauth.sites.0.siteUrl', 'https://example.com/'));
    }

    protected function fakeGoogleOAuthApis(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ], 200),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'search@example.com',
                'name' => 'Search User',
            ], 200),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);
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
