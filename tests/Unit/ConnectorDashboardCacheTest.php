<?php

namespace Tests\Unit;

use App\Enums\ConnectorType;
use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Services\Analytics\ConnectorDashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ConnectorDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_reuses_cached_connector_payload_until_sync_changes(): void
    {
        Cache::flush();

        $connection = $this->makeConnection();
        $cache = app(ConnectorDashboardCache::class);
        $calls = 0;

        $resolver = function () use (&$calls) {
            $calls++;

            return ['kind' => 'google_ads', 'summary' => ['cost' => 100.0]];
        };

        $first = $cache->remember($connection, 'last_30_days', null, DateComparison::None, $resolver);
        $second = $cache->remember($connection, 'last_30_days', null, DateComparison::None, $resolver);

        $this->assertSame(100.0, $first['summary']['cost']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);

        $connection->update(['last_synced_at' => now()]);

        $third = $cache->remember($connection->fresh(), 'last_30_days', null, DateComparison::None, $resolver);

        $this->assertSame(2, $calls);
        $this->assertSame(100.0, $third['summary']['cost']);
    }

    protected function makeConnection(): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Google Ads',
            'connector_type' => ConnectorType::GoogleAds,
            'encrypted_credentials' => [
                'customer_id' => '1234567890',
                'refresh_token' => 'token',
            ],
            'last_synced_at' => now()->subHour(),
        ]);
    }
}
