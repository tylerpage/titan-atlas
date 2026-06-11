<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connector_blueprint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('connector_type')->default('dynamic');
            $table->string('context');
            $table->string('method', 16);
            $table->text('url');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('stream_key')->nullable();
            $table->string('resource_type')->nullable();
            $table->json('request_query')->nullable();
            $table->json('request_body')->nullable();
            $table->longText('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['connection_id', 'created_at']);
            $table->index(['connector_blueprint_id', 'created_at']);
            $table->index(['context', 'created_at']);
            $table->index(['status_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_api_logs');
    }
};
