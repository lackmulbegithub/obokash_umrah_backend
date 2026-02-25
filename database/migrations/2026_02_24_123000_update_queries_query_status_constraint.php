<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old check constraint created by enum('active','closed').
        DB::statement("ALTER TABLE queries DROP CONSTRAINT IF EXISTS queries_query_status_check");

        // Migrate legacy statuses to the new workflow statuses.
        DB::statement("UPDATE queries SET query_status = 'running' WHERE query_status = 'active'");
        DB::statement("UPDATE queries SET query_status = 'finished' WHERE query_status = 'closed'");

        // New default status for fresh query input.
        DB::statement("ALTER TABLE queries ALTER COLUMN query_status SET DEFAULT 'pending'");

        // New allowed statuses.
        DB::statement(<<<'SQL'
            ALTER TABLE queries
            ADD CONSTRAINT queries_query_status_check
            CHECK (query_status IN ('pending', 'running', 'follow_up', 'sold', 'finished'))
        SQL);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE queries DROP CONSTRAINT IF EXISTS queries_query_status_check");

        // Map new statuses back to legacy ones for rollback compatibility.
        DB::statement("UPDATE queries SET query_status = 'active' WHERE query_status IN ('pending', 'running', 'follow_up')");
        DB::statement("UPDATE queries SET query_status = 'closed' WHERE query_status IN ('sold', 'finished')");

        DB::statement("ALTER TABLE queries ALTER COLUMN query_status SET DEFAULT 'active'");

        DB::statement(<<<'SQL'
            ALTER TABLE queries
            ADD CONSTRAINT queries_query_status_check
            CHECK (query_status IN ('active', 'closed'))
        SQL);
    }
};

