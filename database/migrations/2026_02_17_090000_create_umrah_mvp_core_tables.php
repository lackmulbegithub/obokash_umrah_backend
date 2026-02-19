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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('team_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });

        Schema::create('official_whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('wa_number', 20)->unique();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('official_emails', function (Blueprint $table) {
            $table->id();
            $table->string('email_address')->unique();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('query_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('service_name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('mobile_number', 20)->unique();
            $table->string('customer_name');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('whatsapp_number', 20);
            $table->string('visit_record');
            $table->string('country')->nullable();
            $table->string('district')->nullable();
            $table->string('address_line')->nullable();
            $table->string('customer_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('customer_name');
        });

        Schema::create('customer_category_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('customer_categories')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'category_id']);
        });

        Schema::create('customer_source_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('customer_sources')->restrictOnDelete();
            $table->foreignId('source_wa_id')->nullable()->constrained('official_whatsapp_numbers')->nullOnDelete();
            $table->foreignId('source_email_id')->nullable()->constrained('official_emails')->nullOnDelete();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->json('old_data_json');
            $table->json('new_data_json');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('customer_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('referred_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['referrer_customer_id', 'referred_customer_id']);
        });

        Schema::create('queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('query_details_text');
            $table->enum('query_status', ['active', 'closed'])->default('active');
            $table->enum('assigned_type', ['unassigned', 'self', 'team'])->default('unassigned');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamps();
            $table->index('query_status');
        });

        Schema::create('query_source_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained('queries')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('query_sources')->restrictOnDelete();
            $table->foreignId('source_wa_id')->nullable()->constrained('official_whatsapp_numbers')->nullOnDelete();
            $table->foreignId('source_email_id')->nullable()->constrained('official_emails')->nullOnDelete();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('query_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained('queries')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->enum('item_status', ['active', 'closed'])->default('active');
            $table->timestamps();
            $table->index('item_status');
        });

        Schema::create('query_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained('queries')->cascadeOnDelete();
            $table->string('file_path_or_url');
            $table->string('file_type')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('service_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('queue_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['service_id', 'team_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('action', 50);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('service_queues');
        Schema::dropIfExists('query_attachments');
        Schema::dropIfExists('query_items');
        Schema::dropIfExists('query_source_logs');
        Schema::dropIfExists('queries');
        Schema::dropIfExists('customer_referrals');
        Schema::dropIfExists('customer_edit_requests');
        Schema::dropIfExists('customer_source_logs');
        Schema::dropIfExists('customer_category_map');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('query_sources');
        Schema::dropIfExists('customer_sources');
        Schema::dropIfExists('customer_categories');
        Schema::dropIfExists('official_emails');
        Schema::dropIfExists('official_whatsapp_numbers');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        Schema::dropIfExists('teams');
    }
};
