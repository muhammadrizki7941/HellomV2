<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->integer('total_points')->default(0);
            $table->integer('total_orders')->default(0);
            $table->integer('total_spent')->default(0);
            $table->integer('redeemable_points')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            // Unique phone & email per tenant
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_members');
    }
};