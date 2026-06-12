<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
            $table->json('features')->nullable()->after('is_active');
            $table->json('billing_cycles')->nullable()->after('features');
            $table->integer('duration_days')->nullable()->after('billing_cycles');
            $table->boolean('is_visible')->default(true)->after('duration_days');
            $table->integer('sort_order')->default(0)->after('is_visible');
            
            // Remove old type column and replace with new enum
            $table->dropColumn('type');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->enum('type', ['free', 'subscription', 'one_time', 'lifetime'])->default('free')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'features',
                'billing_cycles',
                'duration_days',
                'is_visible',
                'sort_order',
            ]);
            $table->dropColumn('type');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('type', 20)->default('free')->after('name');
        });
    }
};