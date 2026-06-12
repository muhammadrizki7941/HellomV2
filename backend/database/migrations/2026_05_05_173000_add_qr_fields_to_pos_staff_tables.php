<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_staff', function (Blueprint $table) {
            $table->string('attendance_qr_token', 80)->nullable()->after('notes');
            $table->timestamp('attendance_qr_token_rotated_at')->nullable()->after('attendance_qr_token');

            $table->unique(['tenant_id', 'attendance_qr_token'], 'pos_staff_tenant_qr_unique');
        });

        Schema::table('pos_staff_attendances', function (Blueprint $table) {
            $table->string('check_in_location_label', 150)->nullable()->after('check_in_method');
            $table->decimal('check_in_latitude', 10, 7)->nullable()->after('check_in_location_label');
            $table->decimal('check_in_longitude', 10, 7)->nullable()->after('check_in_latitude');
            $table->foreignId('check_in_scanned_by_user_id')->nullable()->after('check_in_longitude')->constrained('users')->nullOnDelete();
            $table->string('check_out_location_label', 150)->nullable()->after('check_out_method');
            $table->decimal('check_out_latitude', 10, 7)->nullable()->after('check_out_location_label');
            $table->decimal('check_out_longitude', 10, 7)->nullable()->after('check_out_latitude');
            $table->foreignId('check_out_scanned_by_user_id')->nullable()->after('check_out_longitude')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_staff_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('check_out_scanned_by_user_id');
            $table->dropColumn([
                'check_out_location_label',
                'check_out_latitude',
                'check_out_longitude',
            ]);

            $table->dropConstrainedForeignId('check_in_scanned_by_user_id');
            $table->dropColumn([
                'check_in_location_label',
                'check_in_latitude',
                'check_in_longitude',
            ]);
        });

        Schema::table('pos_staff', function (Blueprint $table) {
            $table->dropUnique('pos_staff_tenant_qr_unique');
            $table->dropColumn([
                'attendance_qr_token',
                'attendance_qr_token_rotated_at',
            ]);
        });
    }
};
