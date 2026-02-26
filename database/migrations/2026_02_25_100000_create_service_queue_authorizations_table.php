<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_queue_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_view_queue')->default(true);
            $table->boolean('can_distribute')->default(false);
            $table->boolean('can_assign_to_self')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_id', 'team_id', 'user_id'], 'service_queue_auth_unique');
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_queue_authorizations');
    }
};
