<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connector_blueprint_dashboard_versions')) {
            return;
        }

        if ($this->hasCompositeUniqueIndex()) {
            return;
        }

        Schema::table('connector_blueprint_dashboard_versions', function (Blueprint $table) {
            $table->dropUnique(['connector_blueprint_id', 'version_number']);
        });

        Schema::table('connector_blueprint_dashboard_versions', function (Blueprint $table) {
            $table->unique(
                ['connector_blueprint_id', 'client_dashboard_id', 'version_number'],
                'cbdv_blueprint_dashboard_version_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('connector_blueprint_dashboard_versions')) {
            return;
        }

        Schema::table('connector_blueprint_dashboard_versions', function (Blueprint $table) {
            $table->dropUnique('cbdv_blueprint_dashboard_version_unique');
        });

        Schema::table('connector_blueprint_dashboard_versions', function (Blueprint $table) {
            $table->unique(['connector_blueprint_id', 'version_number']);
        });
    }

    protected function hasCompositeUniqueIndex(): bool
    {
        $indexes = Schema::getConnection()
            ->getSchemaBuilder()
            ->getIndexes('connector_blueprint_dashboard_versions');

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === 'cbdv_blueprint_dashboard_version_unique') {
                return true;
            }
        }

        return false;
    }
};
