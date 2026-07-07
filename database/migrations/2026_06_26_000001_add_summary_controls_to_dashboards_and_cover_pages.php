<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_dashboards', function (Blueprint $table) {
            $table->boolean('show_summary_tab')->default(true)->after('default_date_range');
        });

        Schema::table('cover_pages', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('cover_pages', function (Blueprint $table) {
            $table->dropColumn('is_draft');
        });

        Schema::table('client_dashboards', function (Blueprint $table) {
            $table->dropColumn('show_summary_tab');
        });
    }
};
