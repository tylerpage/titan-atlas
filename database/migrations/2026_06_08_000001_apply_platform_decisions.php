<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        if (Schema::hasColumn('users', 'company_id')) {
            DB::table('users')
                ->whereNotNull('company_id')
                ->orderBy('id')
                ->chunkById(100, function ($users): void {
                    foreach ($users as $user) {
                        DB::table('company_user')->insertOrIgnore([
                            'company_id' => $user->company_id,
                            'user_id' => $user->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });

            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_id');
            });
        }

        Schema::table('client_dashboards', function (Blueprint $table) {
            $table->string('custom_domain')->nullable()->unique()->after('slug');
            $table->unsignedSmallInteger('attribution_window_days')->default(30)->after('default_date_range');
        });

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->char('dimension_hash', 64)->default(hash('sha256', '{}'))->after('metric_key');
        });

        DB::table('metric_snapshots')->update([
            'dimension_hash' => hash('sha256', '{}'),
        ]);

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->dropUnique('metric_snapshots_unique');
            $table->unique(
                ['client_dashboard_id', 'snapshot_date', 'metric_key', 'dimension_hash'],
                'metric_snapshots_dimensional_unique',
            );
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('client');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->json('dashboard_ids')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->dropUnique('metric_snapshots_dimensional_unique');
            $table->unique(['client_dashboard_id', 'snapshot_date', 'metric_key'], 'metric_snapshots_unique');
            $table->dropColumn('dimension_hash');
        });

        Schema::table('client_dashboards', function (Blueprint $table) {
            $table->dropColumn(['custom_domain', 'attribution_window_days']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        DB::table('company_user')
            ->orderBy('id')
            ->chunkById(100, function ($memberships): void {
                foreach ($memberships as $membership) {
                    DB::table('users')
                        ->where('id', $membership->user_id)
                        ->whereNull('company_id')
                        ->update(['company_id' => $membership->company_id]);
                }
            });

        Schema::dropIfExists('company_user');
    }
};
