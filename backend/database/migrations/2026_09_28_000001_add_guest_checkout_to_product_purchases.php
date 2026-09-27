<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest checkout for digital products: buyers pay without logging in.
     * - product_purchases.guest_token_hash: sha256 of the status-page token (null = logged-in checkout)
     * - product_purchases.buyer_phone: optional phone entered at checkout
     * - product_purchases.access_email_sent_at: access email delivered (idempotency for webhook retries)
     * - users.pending_guest_credentials: account created by guest checkout, password not issued yet
     */
    public function up(): void
    {
        Schema::table('product_purchases', function (Blueprint $table) {
            $table->string('guest_token_hash', 64)->nullable()->unique()->after('checkout_url');
            $table->string('buyer_phone', 30)->nullable()->after('guest_token_hash');
            $table->timestamp('access_email_sent_at')->nullable()->after('paid_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pending_guest_credentials')->default(false)->after('role_before_suspension');
        });
    }

    public function down(): void
    {
        Schema::table('product_purchases', function (Blueprint $table) {
            $table->dropUnique(['guest_token_hash']);
            $table->dropColumn(['guest_token_hash', 'buyer_phone', 'access_email_sent_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_guest_credentials');
        });
    }
};
