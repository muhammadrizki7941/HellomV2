<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * owner_notifications.type was an enum of 3 values (new_user, new_transaction, expiry_reminder);
 * new super admin events (landing_sale, withdrawal, refund, kyc, wallet_topup, subscription,
 * sla_warning, platform_notice) need a plain string. Existing rows keep their values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE owner_notifications MODIFY type VARCHAR(40) NOT NULL DEFAULT 'platform_notice'");
            DB::statement('CREATE INDEX owner_notifications_read_created_idx ON owner_notifications (is_read, created_at)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('owner_notifications')) {
            DB::statement('DROP INDEX owner_notifications_read_created_idx ON owner_notifications');
            // Rows with new types are folded back into the old enum values before narrowing.
            DB::statement("UPDATE owner_notifications SET type = 'new_transaction' WHERE type NOT IN ('new_user','new_transaction','expiry_reminder')");
            DB::statement("ALTER TABLE owner_notifications MODIFY type ENUM('new_user','new_transaction','expiry_reminder') NOT NULL");
        }
    }
};
