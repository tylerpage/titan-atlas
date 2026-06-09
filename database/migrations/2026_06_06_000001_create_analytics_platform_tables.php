<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('client')->after('email');
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        Schema::create('dashboard_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('default_widgets')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('client_dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dashboard_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#1e40af');
            $table->string('secondary_color', 7)->default('#64748b');
            $table->string('powered_by_text')->nullable();
            $table->boolean('show_powered_by')->default(true);
            $table->string('timezone')->default('America/Chicago');
            $table->string('currency', 3)->default('USD');
            $table->string('default_date_range')->default('last_30_days');
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('client_dashboard_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_dashboard_id', 'user_id']);
        });

        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('connector_type');
            $table->text('encrypted_credentials');
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('backfill_started_at')->nullable();
            $table->timestamp('backfill_completed_at')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('incremental');
            $table->string('status')->default('pending');
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_written')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('raw_connector_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_type');
            $table->string('external_id')->nullable();
            $table->json('payload');
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['connection_id', 'resource_type', 'fetched_at']);
        });

        Schema::create('widget_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('widget_type');
            $table->string('title')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('column_span')->default(1);
            $table->json('configuration')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('metric_key');
            $table->decimal('metric_value', 16, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();

            $table->unique(['client_dashboard_id', 'snapshot_date', 'metric_key'], 'metric_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('widget_placements');
        Schema::dropIfExists('raw_connector_payloads');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('client_dashboard_user');
        Schema::dropIfExists('client_dashboards');
        Schema::dropIfExists('dashboard_templates');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('companies');
    }
};
