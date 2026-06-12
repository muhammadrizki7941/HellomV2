<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_space_id')->nullable()->constrained('reservation_spaces')->nullOnDelete();
            $table->string('space_name');

            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();

            $table->dateTime('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('guests_count')->nullable();

            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();

            $table->unsignedInteger('rent_price');
            $table->unsignedInteger('items_total');
            $table->unsignedInteger('total_price');

            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
