<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_provider', 32)->nullable()->after('payment_ref');
            $table->string('payment_qr_url')->nullable()->after('payment_provider');
            $table->text('payment_qr_string')->nullable()->after('payment_qr_url');
            $table->json('payment_meta')->nullable()->after('payment_qr_string');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_provider', 'payment_qr_url', 'payment_qr_string', 'payment_meta']);
        });
    }
};
