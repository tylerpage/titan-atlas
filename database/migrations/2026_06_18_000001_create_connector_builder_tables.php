<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_builder_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('pending_credentials')->nullable();
            $table->timestamps();
        });

        Schema::create('connector_builder_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connector_builder_session_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('connector_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_builder_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug');
            $table->string('label');
            $table->string('status')->default('draft');
            $table->text('original_prompt')->nullable();
            $table->json('auth_config')->nullable();
            $table->json('credential_schema')->nullable();
            $table->json('sync_config')->nullable();
            $table->json('transform_config')->nullable();
            $table->json('dashboard_spec')->nullable();
            $table->json('dev_tasks')->nullable();
            $table->timestamps();

            $table->unique(['client_dashboard_id', 'slug']);
        });

        Schema::create('connector_blueprint_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connector_blueprint_id')->constrained()->cascadeOnDelete();
            $table->string('stream_key');
            $table->string('resource_type');
            $table->string('http_method')->default('GET');
            $table->string('path_template');
            $table->json('query_params')->nullable();
            $table->json('headers')->nullable();
            $table->json('pagination')->nullable();
            $table->json('response_mapping')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['connector_blueprint_id', 'stream_key']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreignId('connector_blueprint_id')->nullable()->after('connector_type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connector_blueprint_id');
        });

        Schema::dropIfExists('connector_blueprint_streams');
        Schema::dropIfExists('connector_blueprints');
        Schema::dropIfExists('connector_builder_messages');
        Schema::dropIfExists('connector_builder_sessions');
    }
};
