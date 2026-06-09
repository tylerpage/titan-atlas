<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('formula_notes')->nullable();
            $table->text('sql_template');
            $table->string('visualization_type');
            $table->json('visualization_config')->nullable();
            $table->json('connector_types')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();

            $table->unique(['client_dashboard_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_definitions');
    }
};
