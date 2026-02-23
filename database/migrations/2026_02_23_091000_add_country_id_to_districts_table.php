<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('id')->constrained('countries')->nullOnDelete();
        });

        // Remove old global uniqueness and enforce uniqueness per country.
        DB::statement('ALTER TABLE districts DROP CONSTRAINT IF EXISTS districts_district_name_unique');
        DB::statement('DROP INDEX IF EXISTS districts_district_name_unique');
        Schema::table('districts', function (Blueprint $table) {
            $table->unique(['country_id', 'district_name']);
            $table->index('district_name');
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'district_name']);
            $table->dropForeign(['country_id']);
            $table->dropColumn('country_id');
            $table->dropIndex(['district_name']);
        });

        Schema::table('districts', function (Blueprint $table) {
            $table->unique('district_name');
        });
    }
};
