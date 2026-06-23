<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Allow inviting a user directly as a POS cashier.
        DB::statement("ALTER TABLE organization_team_invitations MODIFY role ENUM('admin','member','cashier') NOT NULL DEFAULT 'member'");

        // Link the invitation to the PosStaff record (and therefore its outlet) so
        // that accepting/registering can bind PosStaff.linked_user_id automatically.
        Schema::table('organization_team_invitations', function (Blueprint $table): void {
            $table->foreignId('pos_staff_id')
                ->nullable()
                ->after('role')
                ->constrained('pos_staff')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_team_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_staff_id');
        });

        DB::statement("ALTER TABLE organization_team_invitations MODIFY role ENUM('admin','member') NOT NULL DEFAULT 'member'");
    }
};
