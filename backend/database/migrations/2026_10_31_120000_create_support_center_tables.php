<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'user_id']);
        });

        Schema::create('support_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->string('default_priority', 20)->default('normal');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 24)->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 180);
            $table->string('category', 60)->default('lainnya');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('new');
            $table->string('source', 20)->default('manual');
            $table->foreignId('assigned_agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('ai_failure_streak')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('reopen_until')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('feedback', 1000)->nullable();
            $table->timestamps();
            $table->index(['requester_user_id', 'updated_at']);
            $table->index(['status', 'priority', 'last_activity_at']);
            $table->index(['assigned_agent_user_id', 'status', 'last_activity_at'], 'support_tickets_agent_status_activity_idx');
            $table->index(['organization_id', 'status', 'last_activity_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'created_at', 'id']);
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('support_messages')->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
            $table->index('message_id');
        });

        Schema::create('support_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('slug', 200)->unique();
            $table->string('category', 60)->default('umum');
            $table->string('locale', 5)->default('id');
            $table->json('tags')->nullable();
            $table->text('route_path')->nullable();
            $table->longText('content_markdown');
            $table->string('content_hash', 64);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'locale', 'category']);
        });

        Schema::create('support_canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('category', 60)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'category']);
        });

        DB::table('support_categories')->insert([
            ['slug' => 'teknis', 'name' => 'Teknis', 'default_priority' => 'normal', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'langganan-pembayaran', 'name' => 'Langganan / Pembayaran', 'default_priority' => 'high', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'bug', 'name' => 'Bug', 'default_priority' => 'high', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'saran-fitur', 'name' => 'Saran Fitur', 'default_priority' => 'low', 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'lainnya', 'name' => 'Lainnya', 'default_priority' => 'normal', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_canned_responses');
        Schema::dropIfExists('support_articles');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_categories');
        Schema::dropIfExists('support_agents');
    }
};
