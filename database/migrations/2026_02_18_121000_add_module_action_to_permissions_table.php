<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('permissions', 'module')) {
                $table->string('module', 100)->nullable()->after('name');
                $table->index('module');
            }

            if (! Schema::hasColumn('permissions', 'action')) {
                $table->string('action', 100)->nullable()->after('module');
                $table->index('action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            if (Schema::hasColumn('permissions', 'action')) {
                $table->dropIndex(['action']);
                $table->dropColumn('action');
            }

            if (Schema::hasColumn('permissions', 'module')) {
                $table->dropIndex(['module']);
                $table->dropColumn('module');
            }
        });
    }
};
