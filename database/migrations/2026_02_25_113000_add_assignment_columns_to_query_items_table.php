<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_items', function (Blueprint $table): void {
            $table->string('assigned_type', 20)->default('team')->after('service_id');
            $table->foreignId('team_queue_owner_user_id')->nullable()->after('team_id')->constrained('users')->nullOnDelete();
        });

        DB::statement("UPDATE query_items SET assigned_type = CASE WHEN assigned_user_id IS NULL THEN 'team' ELSE 'self' END");

        DB::statement("ALTER TABLE query_items DROP CONSTRAINT IF EXISTS query_items_assigned_type_check");
        DB::statement(<<<'SQL'
            ALTER TABLE query_items
            ADD CONSTRAINT query_items_assigned_type_check
            CHECK (assigned_type IN ('self', 'team'))
        SQL);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE query_items DROP CONSTRAINT IF EXISTS query_items_assigned_type_check");

        Schema::table('query_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_queue_owner_user_id');
            $table->dropColumn('assigned_type');
        });
    }
};
