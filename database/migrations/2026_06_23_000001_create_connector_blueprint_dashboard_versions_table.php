<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_blueprint_dashboard_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connector_blueprint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('dashboard_spec');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['connector_blueprint_id', 'client_dashboard_id', 'version_number'],
                'cbdv_blueprint_dashboard_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_blueprint_dashboard_versions');
    }
};
