<?php

namespace Tests\Unit;

use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Services\Ingestion\SyncProgressRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncProgressRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_expanding_date_coverage(): void
    {
        $connection = $this->makeConnection();
        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'backfill',
            'status' => 'running',
        ]);

        $recorder = app(SyncProgressRecorder::class);
        $recorder->recordChunkDates($syncRun, $connection, '2025-01-01', '2025-01-07');
        $recorder->recordChunkDates($syncRun->fresh(), $connection->fresh(), '2024-12-01', '2025-01-10');

        $syncRun->refresh();
        $connection->refresh();

        $this->assertSame('2024-12-01', $syncRun->progress_from_date->toDateString());
        $this->assertSame('2025-01-10', $syncRun->progress_through_date->toDateString());
        $this->assertSame('2024-12-01', $connection->data_from_date->toDateString());
        $this->assertSame('2025-01-10', $connection->data_through_date->toDateString());
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
            'name' => 'GSC',
            'connector_type' => 'search_console',
            'encrypted_credentials' => [
                'site_url' => 'https://example.com/',
                'refresh_token' => 'token',
            ],
        ]);
    }
}
