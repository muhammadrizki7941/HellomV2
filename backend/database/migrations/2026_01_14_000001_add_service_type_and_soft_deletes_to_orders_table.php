<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'service_type')) {
                $table->string('service_type', 16)->default('dine_in')->after('customer_name');
            }

            if (!Schema::hasColumn('orders', 'deleted_at')) {
                $table->softDeletes();
            }

            $table->index(['service_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'service_type')) {
                $table->dropIndex(['service_type', 'created_at']);
                $table->dropColumn('service_type');
            }

            if (Schema::hasColumn('orders', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
