<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "CREATE INDEX raw_payloads_conn_type_payload_date_idx
             ON raw_connector_payloads (connection_id, resource_type, ((payload->>'date')))",
        );

        DB::statement(
            'CREATE INDEX raw_payloads_connection_id_id_idx
             ON raw_connector_payloads (connection_id, id)',
        );

        DB::statement(
            "CREATE INDEX metric_snapshots_dashboard_connection_idx
             ON metric_snapshots (client_dashboard_id, ((dimensions->>'connection_id')))",
        );

        DB::statement(
            'CREATE INDEX metric_snapshots_dashboard_date_key_idx
             ON metric_snapshots (client_dashboard_id, snapshot_date, metric_key)',
        );

        DB::statement(
            'CREATE INDEX connections_dashboard_connector_type_idx
             ON connections (client_dashboard_id, connector_type)',
        );

        DB::statement(
            'CREATE INDEX sync_runs_connection_created_idx
             ON sync_runs (connection_id, created_at DESC)',
        );

        DB::statement('ANALYZE raw_connector_payloads');
        DB::statement('ANALYZE metric_snapshots');
        DB::statement('ANALYZE connections');
        DB::statement('ANALYZE sync_runs');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sync_runs_connection_created_idx');
        DB::statement('DROP INDEX IF EXISTS connections_dashboard_connector_type_idx');
        DB::statement('DROP INDEX IF EXISTS metric_snapshots_dashboard_date_key_idx');
        DB::statement('DROP INDEX IF EXISTS metric_snapshots_dashboard_connection_idx');
        DB::statement('DROP INDEX IF EXISTS raw_payloads_connection_id_id_idx');
        DB::statement('DROP INDEX IF EXISTS raw_payloads_conn_type_payload_date_idx');
    }
};
