<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_marketing_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('event_name', 40);
            $table->string('visitor_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('path', 255)->default('/');
            $table->string('source', 120)->default('direct');
            $table->string('campaign', 120)->default('');
            $table->string('app_slug', 80)->default('');
            $table->string('device', 16)->default('desktop');
            $table->unsignedBigInteger('amount')->default(0);
            $table->text('context')->nullable();
            $table->string('delivery_status', 20)->default('disabled');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('delivery_message', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();
            $table->index(['created_at', 'event_name']);
            $table->index(['delivery_status', 'created_at']);
        });
        Schema::table('product_purchases', function (Blueprint $table) { $table->text('marketing_context')->nullable(); });
    }

    public function down(): void
    {
        Schema::table('product_purchases', function (Blueprint $table) { $table->dropColumn('marketing_context'); });
        Schema::dropIfExists('platform_marketing_events');
    }
};
