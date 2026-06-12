<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_staff', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 120)->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('email', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('role', 30)->default('cashier')->index();
            $table->string('employment_status', 20)->default('active')->index();
            $table->json('permissions')->nullable();
            $table->integer('hourly_rate')->default(0);
            $table->date('joined_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employment_status']);
            $table->unique(['tenant_id', 'linked_user_id']);
        });

        Schema::create('pos_staff_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 120)->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 100)->default('Shift');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 20)->default('scheduled')->index();
            $table->unsignedSmallInteger('reminder_minutes')->default(30);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'start_at']);
            $table->index(['staff_id', 'status']);
        });

        Schema::create('pos_staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 120)->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('pos_staff_shifts')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('status', 20)->default('present')->index();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('check_in_method', 20)->nullable();
            $table->string('check_out_method', 20)->nullable();
            $table->string('location_label', 150)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'staff_id', 'attendance_date']);
            $table->index(['tenant_id', 'attendance_date']);
        });

        Schema::create('pos_staff_cash_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 120)->index();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('pos_staff_shifts')->nullOnDelete();
            $table->integer('opening_cash')->default(0);
            $table->integer('closing_cash')->nullable();
            $table->integer('expected_cash')->nullable();
            $table->integer('difference_cash')->nullable();
            $table->integer('total_cash_sales')->default(0);
            $table->integer('total_transactions')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->text('notes')->nullable();
            $table->json('activity_log')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['staff_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_staff_cash_logs');
        Schema::dropIfExists('pos_staff_attendances');
        Schema::dropIfExists('pos_staff_shifts');
        Schema::dropIfExists('pos_staff');
    }
};
