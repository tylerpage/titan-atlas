<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_connector_payloads', function (Blueprint $table) {
            $table->date('payload_date')->nullable()->after('payload');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "UPDATE raw_connector_payloads
             SET payload_date = (payload->>'date')::date
             WHERE payload_date IS NULL
               AND payload->>'date' IS NOT NULL
               AND payload->>'date' != ''",
        );

        DB::statement('DROP INDEX IF EXISTS raw_payloads_conn_type_payload_date_idx');

        DB::statement(
            'CREATE INDEX raw_payloads_conn_type_payload_date_idx
             ON raw_connector_payloads (connection_id, resource_type, payload_date)',
        );

        DB::statement('ANALYZE raw_connector_payloads');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS raw_payloads_conn_type_payload_date_idx');

            DB::statement(
                "CREATE INDEX raw_payloads_conn_type_payload_date_idx
                 ON raw_connector_payloads (connection_id, resource_type, ((payload->>'date')))",
            );
        }

        Schema::table('raw_connector_payloads', function (Blueprint $table) {
            $table->dropColumn('payload_date');
        });
    }
};
