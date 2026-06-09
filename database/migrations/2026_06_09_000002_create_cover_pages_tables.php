<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cover_page_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cover_page_id')->constrained()->cascadeOnDelete();
            $table->string('block_type');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('column_span')->default(1);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_page_blocks');
        Schema::dropIfExists('cover_pages');
    }
};
