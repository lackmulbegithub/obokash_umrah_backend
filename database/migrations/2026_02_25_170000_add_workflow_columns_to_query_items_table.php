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
            $table->string('workflow_status', 30)->default('pending')->after('item_status');
            $table->date('quotation_date')->nullable()->after('workflow_status');
            $table->date('follow_up_date')->nullable()->after('quotation_date');
            $table->unsignedSmallInteger('follow_up_count')->default(0)->after('follow_up_date');
            $table->text('finished_note')->nullable()->after('follow_up_count');
            $table->string('review_status', 30)->nullable()->after('finished_note'); // reviewed_with_call|reviewed_without_call
            $table->text('review_note')->nullable()->after('review_status');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');

            $table->index(['workflow_status', 'follow_up_date'], 'query_items_workflow_followup_idx');
        });

        DB::statement("UPDATE query_items SET workflow_status='finished' WHERE item_status='closed'");
        DB::statement("UPDATE query_items SET workflow_status='pending' WHERE item_status='active'");
    }

    public function down(): void
    {
        Schema::table('query_items', function (Blueprint $table): void {
            $table->dropIndex('query_items_workflow_followup_idx');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn([
                'workflow_status',
                'quotation_date',
                'follow_up_date',
                'follow_up_count',
                'finished_note',
                'review_status',
                'review_note',
                'reviewed_at',
            ]);
        });
    }
};

