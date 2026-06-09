<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminTestConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_test_new_shopify_connection(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'shop' => ['name' => 'Demo Store'],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson(route('admin.connections.test'), [
                'connector_type' => ConnectorType::Shopify->value,
                'credentials' => [
                    'shop_domain' => 'demo.myshopify.com',
                    'access_token' => 'valid-token',
                ],
            ])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'message' => 'Connected to Demo Store',
            ]);
    }

    public function test_admin_can_test_new_bigcommerce_connection(): void
    {
        Http::fake([
            'api.bigcommerce.com/*' => Http::response([
                'name' => 'Acme Store',
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson(route('admin.connections.test'), [
                'connector_type' => ConnectorType::BigCommerce->value,
                'credentials' => [
                    'store_hash' => 'abc123',
                    'access_token' => 'valid-token',
                ],
            ])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'message' => 'Connected to Acme Store',
            ]);
    }

    public function test_admin_can_test_existing_connection_with_stored_credentials(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'shop' => ['name' => 'Demo Store'],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createShopifyConnection();

        $this->actingAs($admin)
            ->postJson(route('admin.connections.test-existing', $connection), [
                'credentials' => [],
            ])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'message' => 'Connected to Demo Store',
            ]);
    }

    public function test_test_connection_returns_error_for_invalid_token(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([], 401),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->postJson(route('admin.connections.test'), [
                'connector_type' => ConnectorType::Shopify->value,
                'credentials' => [
                    'shop_domain' => 'demo.myshopify.com',
                    'access_token' => 'bad-token',
                ],
            ])
            ->assertOk()
            ->assertJson([
                'valid' => false,
                'message' => 'Invalid access token.',
            ])
            ->assertJsonPath('debug.http_status', 401)
            ->assertJsonPath('debug.shop_domain_resolved', 'demo.myshopify.com')
            ->assertJsonPath('debug.token_length', 9);
    }

    public function test_stored_credentials_decrypt_and_trim_before_test(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => function ($request) {
                $this->assertSame('trimmed-token', $request->header('X-Shopify-Access-Token')[0] ?? null);

                return Http::response([
                    'shop' => ['name' => 'Demo Store'],
                ], 200);
            },
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createShopifyConnection();
        $connection->update([
            'encrypted_credentials' => [
                'shop_domain' => ' demo.myshopify.com ',
                'access_token' => " trimmed-token\n",
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.connections.test-existing', $connection), [
                'credentials' => [],
            ])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'message' => 'Connected to Demo Store',
            ]);
    }

    public function test_client_cannot_test_connection(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($client)
            ->postJson(route('admin.connections.test'), [
                'connector_type' => ConnectorType::Shopify->value,
                'credentials' => [
                    'shop_domain' => 'demo.myshopify.com',
                    'access_token' => 'token',
                ],
            ])
            ->assertForbidden();
    }

    protected function createShopifyConnection(): Connection
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify Store',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => [
                'shop_domain' => 'demo.myshopify.com',
                'access_token' => 'demo-token',
            ],
        ]);
    }
}
