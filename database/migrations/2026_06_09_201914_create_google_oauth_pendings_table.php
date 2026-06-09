<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_oauth_pendings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('connector_type');
            $table->text('refresh_token');
            $table->json('sites');
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'client_dashboard_id', 'connector_type'], 'google_oauth_pendings_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_oauth_pendings');
    }
};
