<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_redemptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('member_id')->constrained('pos_members');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->foreignId('reward_rule_id')->constrained('pos_reward_rules');
            $table->integer('points_used')->default(0);
            $table->integer('discount_amount')->default(0);
            $table->enum('status', ['pending', 'applied', 'cancelled'])
                ->default('applied');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_redemptions');
    }
};