<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2B — members belong to the ORGANIZATION (all its outlets), phone numbers are
 * normalised (628…), and points move to an append-only ledger.
 *
 * Additive and non-destructive:
 * - pos_members: organization_id, phone_normalized (the original `phone` is kept),
 *   merged_into_id / merged_at for manual merges. No member is merged automatically;
 *   duplicates after normalisation are only reported (php artisan pos:members:duplicates).
 * - member_point_transactions: new ledger. Every member with a balance gets one opening
 *   row (type adjust, "Migrasi saldo awal") equal to redeemable_points, so
 *   SUM(ledger) = SUM(redeemable_points) right after this migration.
 * - pos_loyalty_settings: redemption value, limits and expiry.
 * - pos_fraud_flags: signals for later review (Fase 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_members', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('outlet_id')->constrained('organizations')->nullOnDelete();
            $table->string('phone_normalized', 20)->nullable()->after('phone');
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('last_order_at');
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');
            $table->index(['organization_id', 'phone_normalized'], 'pos_members_org_phone_index');
        });

        // organization_id: from the outlet whose slug the member carries, else from the org itself.
        DB::statement('UPDATE pos_members m JOIN outlets o ON o.tenant_slug = m.tenant_id SET m.organization_id = o.organization_id WHERE m.organization_id IS NULL');
        DB::statement('UPDATE pos_members m JOIN organizations g ON g.pos_tenant_slug = m.tenant_id SET m.organization_id = g.id WHERE m.organization_id IS NULL');
        DB::statement('UPDATE pos_members m JOIN organizations g ON g.slug = m.tenant_id SET m.organization_id = g.id WHERE m.organization_id IS NULL');

        DB::table('pos_members')->whereNotNull('phone')->orderBy('id')->each(function ($member) {
            DB::table('pos_members')->where('id', $member->id)->update(['phone_normalized' => self::normalizePhone((string) $member->phone)]);
        });

        Schema::create('member_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('member_id')->constrained('pos_members')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 16); // earn | redeem | adjust | expire | reversal
            $table->integer('points'); // signed: + adds, − deducts
            $table->integer('balance_after');
            $table->integer('remaining_points')->nullable(); // positive lots still unspent (FIFO for redeem/expire)
            $table->timestamp('expires_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'created_at']);
            $table->index(['member_id', 'expires_at']);
        });

        $now = now();
        DB::table('pos_members')->where('redeemable_points', '!=', 0)->orderBy('id')->each(function ($member) use ($now) {
            DB::table('member_point_transactions')->insert([
                'organization_id' => $member->organization_id,
                'member_id' => $member->id,
                'outlet_id' => $member->outlet_id,
                'type' => 'adjust',
                'points' => (int) $member->redeemable_points,
                'balance_after' => (int) $member->redeemable_points,
                'remaining_points' => max(0, (int) $member->redeemable_points),
                'reason' => 'Migrasi saldo awal',
                'idempotency_key' => 'opening:member:' . $member->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        Schema::table('pos_loyalty_settings', function (Blueprint $table) {
            $table->unsignedInteger('redeem_value_per_point')->default(0)->after('max_points_per_order'); // Rp per point; 0 = redemption off
            $table->unsignedInteger('min_redeem_points')->default(0)->after('redeem_value_per_point');
            $table->unsignedInteger('max_redeem_points_per_order')->nullable()->after('min_redeem_points');
            $table->unsignedSmallInteger('points_expire_months')->nullable()->after('max_redeem_points_per_order');
        });

        Schema::create('pos_fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('rule', 60);
            $table->foreignId('member_id')->nullable()->constrained('pos_members')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open'); // open | reviewed | dismissed
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_fraud_flags');
        Schema::table('pos_loyalty_settings', function (Blueprint $table) {
            $table->dropColumn(['redeem_value_per_point', 'min_redeem_points', 'max_redeem_points_per_order', 'points_expire_months']);
        });
        Schema::dropIfExists('member_point_transactions');
        Schema::table('pos_members', function (Blueprint $table) {
            $table->dropIndex('pos_members_org_phone_index');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['phone_normalized', 'merged_into_id', 'merged_at']);
        });
    }

    /** Same rules as App\Support\PhoneNumber::normalize (kept inline so the migration never changes). */
    private static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '62')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }
        if (str_starts_with($digits, '8')) {
            return '62' . $digits;
        }

        return $digits;
    }
};
