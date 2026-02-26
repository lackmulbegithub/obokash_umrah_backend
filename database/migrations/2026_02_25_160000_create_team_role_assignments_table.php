<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('team_role', 30)->default('member'); // head|delegate_head|member
            $table->boolean('is_active')->default(true);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'team_role'], 'team_role_assignments_unique');
            $table->index(['team_id', 'team_role', 'is_active'], 'team_role_assignments_team_role_idx');
            $table->index(['user_id', 'is_active'], 'team_role_assignments_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_role_assignments');
    }
};

