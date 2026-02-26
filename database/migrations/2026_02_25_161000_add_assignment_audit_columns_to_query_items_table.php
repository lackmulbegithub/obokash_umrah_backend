<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_items', function (Blueprint $table): void {
            $table->foreignId('assigned_by_user_id')->nullable()->after('assigned_user_id')->constrained('users')->nullOnDelete();
            $table->text('assignment_note')->nullable()->after('assigned_by_user_id');

            $table->index(['team_id', 'assigned_type', 'assigned_user_id'], 'query_items_team_assign_idx');
            $table->index(['assigned_user_id', 'assigned_type'], 'query_items_user_assign_idx');
        });
    }

    public function down(): void
    {
        Schema::table('query_items', function (Blueprint $table): void {
            $table->dropIndex('query_items_team_assign_idx');
            $table->dropIndex('query_items_user_assign_idx');
            $table->dropConstrainedForeignId('assigned_by_user_id');
            $table->dropColumn('assignment_note');
        });
    }
};

