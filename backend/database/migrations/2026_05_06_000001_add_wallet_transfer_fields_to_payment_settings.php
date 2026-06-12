<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->boolean('gopay_enabled')->default(false)->after('qris_dynamic_enabled');
            $table->string('gopay_account_name')->nullable()->after('gopay_enabled');
            $table->string('gopay_account_number')->nullable()->after('gopay_account_name');
            $table->string('gopay_deeplink_template')->nullable()->after('gopay_account_number');

            $table->boolean('dana_enabled')->default(false)->after('gopay_deeplink_template');
            $table->string('dana_account_name')->nullable()->after('dana_enabled');
            $table->string('dana_account_number')->nullable()->after('dana_account_name');
            $table->string('dana_deeplink_template')->nullable()->after('dana_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'gopay_enabled',
                'gopay_account_name',
                'gopay_account_number',
                'gopay_deeplink_template',
                'dana_enabled',
                'dana_account_name',
                'dana_account_number',
                'dana_deeplink_template',
            ]);
        });
    }
};
