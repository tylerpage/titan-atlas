<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_api_logs', function (Blueprint $table) {
            $table->json('request_headers')->nullable()->after('request_body');
            $table->string('request_body_format')->nullable()->after('request_headers');
            $table->json('response_headers')->nullable()->after('response_body');
        });
    }

    public function down(): void
    {
        Schema::table('connector_api_logs', function (Blueprint $table) {
            $table->dropColumn(['request_headers', 'request_body_format', 'response_headers']);
        });
    }
};
