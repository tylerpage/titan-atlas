<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $idsToKeep = DB::table('raw_connector_payloads')
            ->selectRaw('MAX(id) as id')
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->groupBy('connection_id', 'resource_type', 'external_id')
            ->pluck('id');

        if ($idsToKeep->isNotEmpty()) {
            DB::table('raw_connector_payloads')
                ->whereNotNull('external_id')
                ->where('external_id', '!=', '')
                ->whereNotIn('id', $idsToKeep)
                ->delete();
        }

        Schema::table('raw_connector_payloads', function (Blueprint $table) {
            $table->unique(
                ['connection_id', 'resource_type', 'external_id'],
                'raw_payloads_connection_resource_external_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('raw_connector_payloads', function (Blueprint $table) {
            $table->dropUnique('raw_payloads_connection_resource_external_unique');
        });
    }
};
