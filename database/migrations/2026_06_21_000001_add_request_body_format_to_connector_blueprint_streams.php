<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_blueprint_streams', function (Blueprint $table) {
            $table->string('request_body_format')->default('json')->after('request_body');
        });
    }

    public function down(): void
    {
        Schema::table('connector_blueprint_streams', function (Blueprint $table) {
            $table->dropColumn('request_body_format');
        });
    }
};
