<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('connector_blueprints')) {
            return;
        }

        DB::table('connector_blueprints')
            ->where('is_global', true)
            ->where(function ($query) {
                $query->whereNotNull('client_dashboard_id')
                    ->orWhereNotNull('company_id');
            })
            ->update([
                'client_dashboard_id' => null,
                'company_id' => null,
            ]);
    }

    public function down(): void
    {
        // Non-reversible data cleanup.
    }
};
