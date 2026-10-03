<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hellom Page physical products with real courier rates (RajaOngkir): the seller's ship-from
 * place and the couriers they offer. { origin: { id, label }, couriers: [jne, jnt, …] }
 * Products use shipping_mode "courier" (+ weight_grams, already present).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->json('landing_shipping')->nullable()->after('landing_suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('landing_shipping');
        });
    }
};
