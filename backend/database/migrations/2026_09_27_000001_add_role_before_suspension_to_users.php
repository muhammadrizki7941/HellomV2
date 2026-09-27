<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suspension sets users.role = 'suspended'; keep the previous role so
     * reactivation restores it instead of downgrading everyone to "member".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role_before_suspension', 50)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_before_suspension');
        });
    }
};
