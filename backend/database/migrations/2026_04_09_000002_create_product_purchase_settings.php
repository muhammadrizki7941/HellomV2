<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_purchase_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('service_type')->default('dine_in'); // dine_in, take_away, delivery, pre_order
            $table->boolean('enabled')->default(true);
            $table->string('name'); // Display name in admin
            $table->string('description')->nullable();
            
            // Order timing
            $table->string('order_timing')->default('immediate'); // immediate, scheduled, reservation
            $table->integer('lead_time_minutes')->default(0); // Minutes before pickup/delivery
            $table->json('available_days')->nullable(); // ['mon','tue','wed','thu','fri','sat','sun']
            $table->time('start_time')->nullable(); // Daily start time
            $table->time('end_time')->nullable(); // Daily end time
            
            // Payment requirements
            $table->boolean('require_payment_first')->default(true); // Pay before order confirmed
            
            // Table/reservation requirement
            $table->boolean('require_table')->default(false); // Require table selection
            $table->boolean('require_reservation')->default(false); // Require reservation
            
            // Limits
            $table->integer('max_order_per_day')->nullable()->unlimited();
            $table->integer('min_order_amount')->default(0);
            $table->integer('max_order_amount')->nullable();
            
            // Display
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            
            $table->timestamps();

            $table->unique(['organization_id', 'service_type']);
            $table->index(['organization_id', 'enabled']);
        });

        // Add service_type to products table for granular control
        Schema::table('products', function (Blueprint $table) {
            $table->json('available_purchase_types')->nullable()->after('is_available');
            $table->boolean('preorder_enabled')->default(false)->after('available_purchase_types');
            $table->integer('preorder_lead_time_minutes')->default(30)->after('preorder_enabled');
            $table->boolean('hide_when_unavailable')->default(true)->after('preorder_lead_time_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'available_purchase_types',
                'preorder_enabled',
                'preorder_lead_time_minutes',
                'hide_when_unavailable',
            ]);
        });

        Schema::dropIfExists('product_purchase_settings');
    }
};