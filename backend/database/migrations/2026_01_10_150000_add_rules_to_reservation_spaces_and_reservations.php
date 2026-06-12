<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_spaces', function (Blueprint $table) {
            $table->boolean('rent_enabled')->default(true)->after('rent_price');
            $table->unsignedInteger('min_menu_total')->default(0)->after('rent_enabled');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('menu_commitment_total')->default(0)->after('items_total');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('menu_commitment_total');
        });

        Schema::table('reservation_spaces', function (Blueprint $table) {
            $table->dropColumn(['rent_enabled', 'min_menu_total']);
        });
    }
};
