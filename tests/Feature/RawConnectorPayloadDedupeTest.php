<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\CommerceDashboardService;
use App\Services\Analytics\TransformConnectionDataService;
use App\Services\Ingestion\RawConnectorPayloadDeduper;
use App\Services\Ingestion\RawConnectorPayloadWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RawConnectorPayloadDedupeTest extends TestCase
{
    use RefreshDatabase;

    protected function withoutPayloadUniqueIndex(): void
    {
        Schema::table('raw_connector_payloads', function (Blueprint $table) {
            $table->dropUnique('raw_payloads_connection_resource_external_unique');
        });
    }

    protected function createConnection(): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
        ]);
    }

    public function test_deduper_removes_stale_duplicate_payloads(): void
    {
        $this->withoutPayloadUniqueIndex();
        $connection = $this->createConnection();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => ['date' => '2024-06-01', 'total' => 100],
            'payload_hash' => hash('sha256', 'old'),
            'fetched_at' => now()->subDay(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => ['date' => '2024-06-01', 'total' => 150],
            'payload_hash' => hash('sha256', 'new'),
            'fetched_at' => now(),
        ]);

        $deleted = app(RawConnectorPayloadDeduper::class)->dedupeForConnection($connection);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, RawConnectorPayload::query()->where('connection_id', $connection->id)->count());

        $payload = RawConnectorPayload::query()->where('connection_id', $connection->id)->first();
        $this->assertSame(150.0, (float) $payload->payload['total']);
    }

    public function test_payload_writer_upserts_instead_of_duplicating(): void
    {
        $connection = $this->createConnection();
        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => 'running',
        ]);

        $writer = app(RawConnectorPayloadWriter::class);
        $record = [
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => ['date' => '2024-06-01', 'total' => 100],
        ];

        $this->assertTrue($writer->upsert($connection, $syncRun, $record));
        $this->assertFalse($writer->upsert($connection, $syncRun, $record));
        $this->assertTrue($writer->upsert($connection, $syncRun, [
            ...$record,
            'payload' => ['date' => '2024-06-01', 'total' => 125],
        ]));

        $this->assertSame(1, RawConnectorPayload::query()->where('connection_id', $connection->id)->count());

        $payload = RawConnectorPayload::query()->where('connection_id', $connection->id)->first();
        $this->assertSame(125.0, (float) $payload->payload['total']);
        $this->assertSame('2024-06-01', $payload->payload_date?->toDateString());
    }

    public function test_commerce_dashboard_counts_deduped_orders_only(): void
    {
        $this->withoutPayloadUniqueIndex();
        $connection = $this->createConnection();
        $dashboard = $connection->clientDashboard;

        foreach ([100, 100] as $i => $total) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'resource_type' => 'order',
                'external_id' => '1001',
                'payload' => ['date' => '2024-06-01', 'total' => $total],
                'payload_hash' => hash('sha256', (string) $i),
                'fetched_at' => now()->addSeconds($i),
            ]);
        }

        $data = app(CommerceDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2024-06-01', 'end' => '2024-06-01'],
        );

        $this->assertSame(100.0, $data['summary']['revenue']);
        $this->assertSame(1.0, $data['summary']['orders']);
    }

    public function test_transform_rebuild_does_not_double_count_duplicate_payloads(): void
    {
        $this->withoutPayloadUniqueIndex();
        $connection = $this->createConnection();
        $dashboard = $connection->clientDashboard;
        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => 'running',
        ]);

        foreach ([100, 100] as $i => $total) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
                'resource_type' => 'order',
                'external_id' => '1001',
                'payload' => ['date' => '2024-06-01', 'total' => $total],
                'payload_hash' => hash('sha256', (string) $i),
                'fetched_at' => now()->addSeconds($i),
            ]);
        }

        app(TransformConnectionDataService::class)->transform($syncRun->fresh(['connection.clientDashboard']));

        $revenue = MetricSnapshot::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->where('metric_key', 'revenue')
            ->sum('metric_value');

        $orders = MetricSnapshot::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->where('metric_key', 'orders')
            ->sum('metric_value');

        $this->assertSame(100.0, (float) $revenue);
        $this->assertSame(1.0, (float) $orders);
    }
}
