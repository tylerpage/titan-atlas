<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_report_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('analytics_report_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_report_session_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analytics_report_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('prompt');
            $table->text('sql');
            $table->string('visualization_type');
            $table->json('visualization_config');
            $table->string('model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_reports');
        Schema::dropIfExists('analytics_report_messages');
        Schema::dropIfExists('analytics_report_sessions');
    }
};
