<?php

use App\Models\ClientDashboard;
use App\Models\ConnectorBlueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_blueprints', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        ConnectorBlueprint::query()
            ->whereNotNull('client_dashboard_id')
            ->with('dashboard:id,company_id')
            ->each(function (ConnectorBlueprint $blueprint): void {
                if ($blueprint->dashboard === null) {
                    return;
                }

                $blueprint->update(['company_id' => $blueprint->dashboard->company_id]);
            });

        Schema::table('connector_blueprints', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropColumn('connection_id');
            $table->dropUnique(['client_dashboard_id', 'slug']);
            $table->foreignId('client_dashboard_id')->nullable()->change();
            $table->unique(['company_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('connector_blueprints', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'slug']);
            $table->unique(['client_dashboard_id', 'slug']);
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
