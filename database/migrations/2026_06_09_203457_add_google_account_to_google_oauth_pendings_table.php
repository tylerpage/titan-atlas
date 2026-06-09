<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_oauth_pendings', function (Blueprint $table) {
            $table->string('google_email')->nullable()->after('refresh_token');
            $table->string('google_name')->nullable()->after('google_email');
        });
    }

    public function down(): void
    {
        Schema::table('google_oauth_pendings', function (Blueprint $table) {
            $table->dropColumn(['google_email', 'google_name']);
        });
    }
};
