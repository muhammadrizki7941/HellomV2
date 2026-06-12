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
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_amount')) {
                // Jumlah yang dibayar pelanggan (untuk hitung kembalian)
                $table->integer('payment_amount')->default(0)
                    ->after('payment_status');
            }
            if (!Schema::hasColumn('orders', 'payment_change')) {
                // Kembalian (untuk tunai)
                $table->integer('payment_change')->default(0)
                    ->after('payment_amount');
            }
            if (!Schema::hasColumn('orders', 'payment_note')) {
                // Catatan pembayaran (misal: no. referensi transfer)
                $table->string('payment_note')->nullable()
                    ->after('payment_change');
            }
            if (!Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()
                    ->after('payment_note');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_amount',
                'payment_change',
                'payment_note',
                'paid_at',
            ]);
        });
    }
};
