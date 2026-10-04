<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sewa / booking jadwal (product type "rental"): per-day or per-slot bookings with a number of
 * units, opening hours and closed dates (landing_products.booking_settings). Each order holds its
 * time in landing_bookings while the buyer pays; paid = confirmed, expired/failed = released.
 * starts_at/ends_at are WIB wall-clock times (Asia/Jakarta), not converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_products', function (Blueprint $table): void {
            $table->json('booking_settings')->nullable()->after('weight_grams');
        });

        Schema::create('landing_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->dateTime('starts_at');                 // WIB wall clock
            $table->dateTime('ends_at');                   // WIB wall clock, exclusive
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('status', 16);                  // held|confirmed|released|cancelled
            $table->timestamp('held_until')->nullable();   // held: free again after this (order expiry)
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['organization_id', 'starts_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_bookings');
        Schema::table('landing_products', function (Blueprint $table): void {
            $table->dropColumn('booking_settings');
        });
    }
};
