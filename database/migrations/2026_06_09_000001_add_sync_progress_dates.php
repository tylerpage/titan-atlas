<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->date('progress_from_date')->nullable()->after('records_written');
            $table->date('progress_through_date')->nullable()->after('progress_from_date');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->date('data_from_date')->nullable()->after('backfill_completed_at');
            $table->date('data_through_date')->nullable()->after('data_from_date');
            $table->unsignedBigInteger('last_transformed_payload_id')->nullable()->after('data_through_date');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['data_from_date', 'data_through_date', 'last_transformed_payload_id']);
        });

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn(['progress_from_date', 'progress_through_date']);
        });
    }
};
