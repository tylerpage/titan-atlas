<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('saved_dashboard_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analytics_report_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('column_span')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_dashboard_blocks');
        Schema::dropIfExists('saved_dashboards');
    }
};
