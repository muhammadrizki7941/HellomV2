<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * POS anti-fraud audit trail (App\Services\Pos\Audit\*):
 * - pos_owner_pins: one owner PIN per organization (hash only), wrong-try counter + lock.
 * - pos_owner_pin_resets: emailed reset links (SHA-256 token, single use).
 * - pos_activity_logs: append-only, hash-chained per organization. No foreign keys on purpose:
 *   deleting an outlet/user/organization must never cascade into the audit trail.
 *   MySQL triggers refuse UPDATE/DELETE (the model refuses too).
 * - pos_activity_log_heads: last hash per organization (locked while appending).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_owner_pins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique();
            $table->unsignedBigInteger('set_by_user_id')->nullable();
            $table->string('pin_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('pin_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_owner_pin_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('tenant_id', 100)->nullable();
            $table->dateTime('occurred_at', 3);
            $table->string('action', 40);
            $table->string('severity', 10)->default('info'); // info | warning | critical
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('target_type', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label', 120)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('approver_user_id')->nullable();
            $table->string('approver_name', 120)->nullable();
            $table->string('approval_method', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('session_ref', 64)->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'occurred_at'], 'pal_org_time_idx');
            $table->index(['organization_id', 'action', 'occurred_at'], 'pal_org_action_idx');
            $table->index(['organization_id', 'actor_user_id', 'occurred_at'], 'pal_org_actor_idx');
            $table->index(['organization_id', 'target_type', 'target_id'], 'pal_org_target_idx');
        });

        Schema::create('pos_activity_log_heads', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->char('last_hash', 64);
            $table->timestamp('updated_at')->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            // Needs TRIGGER privilege (and log_bin_trust_function_creators on some binlog setups).
            // Without it the app-level guard in PosActivityLog still applies; never block deploy.
            try {
                DB::unprepared("CREATE TRIGGER pos_activity_logs_no_update BEFORE UPDATE ON pos_activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pos_activity_logs is append-only'");
                DB::unprepared("CREATE TRIGGER pos_activity_logs_no_delete BEFORE DELETE ON pos_activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pos_activity_logs is append-only'");
            } catch (\Throwable $e) {
                Log::warning('pos_activity_logs: append-only triggers not created', ['error' => $e->getMessage()]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pos_activity_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS pos_activity_logs_no_delete');
        }
        Schema::dropIfExists('pos_activity_log_heads');
        Schema::dropIfExists('pos_activity_logs');
        Schema::dropIfExists('pos_owner_pin_resets');
        Schema::dropIfExists('pos_owner_pins');
    }
};
