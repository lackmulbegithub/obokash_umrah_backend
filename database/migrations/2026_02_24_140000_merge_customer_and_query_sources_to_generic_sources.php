<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('generic_sources')) {
            Schema::create('generic_sources', function (Blueprint $table) {
                $table->id();
                $table->string('source_name')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $customerSources = Schema::hasTable('customer_sources')
            ? DB::table('customer_sources')->select('source_name', 'is_active')->get()
            : collect();
        $querySources = Schema::hasTable('query_sources')
            ? DB::table('query_sources')->select('source_name', 'is_active')->get()
            : collect();

        $merged = $customerSources
            ->concat($querySources)
            ->groupBy('source_name')
            ->map(function ($rows, $name) {
                return [
                    'source_name' => (string) $name,
                    'is_active' => (bool) $rows->contains(fn ($item) => (bool) $item->is_active),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->values()
            ->all();

        if ($merged !== []) {
            DB::table('generic_sources')->upsert($merged, ['source_name'], ['is_active', 'updated_at']);
        }

        $genericByName = DB::table('generic_sources')->pluck('id', 'source_name');

        if (Schema::hasTable('customer_source_logs') && Schema::hasTable('customer_sources')) {
            $customerNameById = DB::table('customer_sources')->pluck('source_name', 'id');
            $customerLogs = DB::table('customer_source_logs')->select('id', 'source_id')->get();
            foreach ($customerLogs as $log) {
                $name = $customerNameById[$log->source_id] ?? null;
                $genericId = $name ? ($genericByName[$name] ?? null) : null;
                if ($genericId) {
                    DB::table('customer_source_logs')->where('id', $log->id)->update(['source_id' => $genericId]);
                }
            }
        }

        if (Schema::hasTable('query_source_logs') && Schema::hasTable('query_sources')) {
            $queryNameById = DB::table('query_sources')->pluck('source_name', 'id');
            $queryLogs = DB::table('query_source_logs')->select('id', 'source_id')->get();
            foreach ($queryLogs as $log) {
                $name = $queryNameById[$log->source_id] ?? null;
                $genericId = $name ? ($genericByName[$name] ?? null) : null;
                if ($genericId) {
                    DB::table('query_source_logs')->where('id', $log->id)->update(['source_id' => $genericId]);
                }
            }
        }

        DB::statement('ALTER TABLE customer_source_logs DROP CONSTRAINT IF EXISTS customer_source_logs_source_id_foreign');
        DB::statement('ALTER TABLE query_source_logs DROP CONSTRAINT IF EXISTS query_source_logs_source_id_foreign');

        Schema::table('customer_source_logs', function (Blueprint $table) {
            $table->foreign('source_id')->references('id')->on('generic_sources')->restrictOnDelete();
        });
        Schema::table('query_source_logs', function (Blueprint $table) {
            $table->foreign('source_id')->references('id')->on('generic_sources')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('generic_sources')) {
            return;
        }

        $genericById = DB::table('generic_sources')->pluck('source_name', 'id');
        $customerIdByName = Schema::hasTable('customer_sources')
            ? DB::table('customer_sources')->pluck('id', 'source_name')
            : collect();
        $queryIdByName = Schema::hasTable('query_sources')
            ? DB::table('query_sources')->pluck('id', 'source_name')
            : collect();

        if (Schema::hasTable('customer_source_logs') && Schema::hasTable('customer_sources')) {
            $logs = DB::table('customer_source_logs')->select('id', 'source_id')->get();
            foreach ($logs as $log) {
                $name = $genericById[$log->source_id] ?? null;
                $customerId = $name ? ($customerIdByName[$name] ?? null) : null;
                if ($customerId) {
                    DB::table('customer_source_logs')->where('id', $log->id)->update(['source_id' => $customerId]);
                }
            }
        }

        if (Schema::hasTable('query_source_logs') && Schema::hasTable('query_sources')) {
            $logs = DB::table('query_source_logs')->select('id', 'source_id')->get();
            foreach ($logs as $log) {
                $name = $genericById[$log->source_id] ?? null;
                $queryId = $name ? ($queryIdByName[$name] ?? null) : null;
                if ($queryId) {
                    DB::table('query_source_logs')->where('id', $log->id)->update(['source_id' => $queryId]);
                }
            }
        }

        DB::statement('ALTER TABLE customer_source_logs DROP CONSTRAINT IF EXISTS customer_source_logs_source_id_foreign');
        DB::statement('ALTER TABLE query_source_logs DROP CONSTRAINT IF EXISTS query_source_logs_source_id_foreign');

        if (Schema::hasTable('customer_sources')) {
            Schema::table('customer_source_logs', function (Blueprint $table) {
                $table->foreign('source_id')->references('id')->on('customer_sources')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('query_sources')) {
            Schema::table('query_source_logs', function (Blueprint $table) {
                $table->foreign('source_id')->references('id')->on('query_sources')->restrictOnDelete();
            });
        }

        Schema::dropIfExists('generic_sources');
    }
};
