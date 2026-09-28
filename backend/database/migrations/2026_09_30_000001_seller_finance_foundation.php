<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — money for landing-page sales (docs/AUDIT_LANDING_BUILDER.md).
 * Additive only; existing rows are kept and backfilled where needed.
 *
 *  - seller_balances            cache of a seller's sales balance (pending/available/processing/withdrawn)
 *  - seller_balance_ledger      append-only ledger; the balance is the sum of its rows per bucket
 *  - seller_withdrawals         withdrawals from the sales balance (the old wallet withdrawals stay as they are)
 *  - landing_order_items        product snapshot per order
 *  - payment_webhook_logs       every raw webhook, append-only
 *  - landing_page_orders        fee/payment/lifecycle columns; seller FK no longer cascades on delete
 *  - organization_payout_profiles  destination type + bank-change timestamp (24h hold)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_balances', function (Blueprint $table): void {
            $table->foreignId('organization_id')->primary()->constrained('organizations')->restrictOnDelete();
            $table->bigInteger('pending')->default(0);      // TERTAHAN: sold, still on hold
            $table->bigInteger('available')->default(0);    // TERSEDIA: can be withdrawn
            $table->bigInteger('processing')->default(0);   // requested withdrawals not yet paid out
            $table->bigInteger('withdrawn')->default(0);    // DITARIK: paid out
            $table->unsignedSmallInteger('hold_days_override')->nullable();
            $table->boolean('is_frozen')->default(false);   // super admin can hold a problematic seller
            $table->string('frozen_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('seller_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('requested'); // requested|processing|paid|failed|cancelled
            $table->bigInteger('amount');                         // taken from the balance
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('net_amount');                     // transferred to the seller
            $table->string('destination_type', 10)->default('bank'); // bank|ewallet
            $table->string('bank_code', 30);
            $table->string('bank_name', 80)->nullable();
            $table->string('account_number', 50);
            $table->string('account_name', 120);
            $table->string('mode', 10)->default('manual');         // manual|auto
            $table->string('provider', 30)->nullable();
            $table->string('reference', 40)->unique();
            $table->string('provider_ref', 120)->nullable();
            $table->string('proof_path', 255)->nullable();         // manual transfer proof (private disk)
            $table->string('failure_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('sla_warned_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('seller_balance_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            // sale | platform_fee | gateway_fee | release | withdrawal | withdrawal_reversal | refund | adjustment | opening
            $table->string('type', 30);
            $table->string('bucket', 12);                          // pending|available
            $table->bigInteger('amount');                          // signed rupiah
            $table->bigInteger('pending_after');
            $table->bigInteger('available_after');
            $table->foreignId('order_id')->nullable()->constrained('landing_page_orders')->restrictOnDelete();
            $table->foreignId('withdrawal_id')->nullable()->constrained('seller_withdrawals')->restrictOnDelete();
            $table->string('group_key', 80)->nullable();           // rows written together (e.g. a release pair)
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('available_at')->nullable();         // sale rows: when the hold ends
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'id']);
            $table->index(['type', 'available_at']);
        });

        Schema::create('landing_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('landing_page_orders')->restrictOnDelete();
            $table->string('block_id', 64)->nullable();
            $table->string('product_kind', 20);
            $table->string('product_name', 200);
            $table->bigInteger('unit_price');
            $table->unsignedInteger('qty')->default(1);
            $table->bigInteger('line_total');
            $table->json('snapshot')->nullable();                  // product fields at purchase time (no delivery link)
            $table->timestamps();
        });

        Schema::create('payment_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 160)->nullable();
            $table->string('reference', 120)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('outcome', 40)->nullable();             // processed|duplicate|rejected|ignored|error
            $table->text('error')->nullable();
            $table->json('headers')->nullable();
            $table->longText('payload')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->index(['provider', 'event_id']);
            $table->index('reference');
            $table->index('received_at');
        });

        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->bigInteger('gateway_fee_amount')->default(0)->after('commission_amount');
            $table->bigInteger('paid_amount')->nullable()->after('net_amount');
            $table->string('payment_method', 40)->nullable()->after('provider');
            $table->string('payment_channel', 60)->nullable()->after('payment_method');
            $table->string('gateway_trx_id', 120)->nullable()->after('gateway_ref');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->timestamp('failed_at')->nullable()->after('expired_at');
            $table->timestamp('fulfilled_at')->nullable()->after('failed_at');
            $table->timestamp('refunded_at')->nullable()->after('fulfilled_at');
            $table->timestamp('ledger_posted_at')->nullable()->after('refunded_at');
            $table->index(['status', 'expires_at'], 'lpo_status_expires_idx');
        });

        // Sales history must survive an organization being deleted.
        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::table('organization_payout_profiles', function (Blueprint $table): void {
            $table->string('destination_type', 10)->default('bank')->after('bank_name');
            $table->timestamp('bank_changed_at')->nullable()->after('reviewed_at');
        });

        // Pending orders created before this change get the default 24h expiry from creation.
        DB::table('landing_page_orders')
            ->where('status', 'pending')
            ->whereNull('expires_at')
            ->update(['expires_at' => DB::raw('DATE_ADD(created_at, INTERVAL 24 HOUR)')]);

        // One item row per existing order (orders were single-product until now).
        DB::table('landing_page_orders')->orderBy('id')->chunkById(500, function ($orders): void {
            $rows = [];
            foreach ($orders as $order) {
                $rows[] = [
                    'order_id' => $order->id,
                    'block_id' => $order->block_id,
                    'product_kind' => $order->product_kind,
                    'product_name' => $order->product_name,
                    'unit_price' => $order->amount,
                    'qty' => 1,
                    'line_total' => $order->amount,
                    'snapshot' => json_encode(['backfilled' => true]),
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ];
            }
            if ($rows !== []) {
                DB::table('landing_order_items')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_payout_profiles', function (Blueprint $table): void {
            $table->dropColumn(['destination_type', 'bank_changed_at']);
        });

        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->dropIndex('lpo_status_expires_idx');
            $table->dropColumn([
                'gateway_fee_amount', 'paid_amount', 'payment_method', 'payment_channel', 'gateway_trx_id',
                'expires_at', 'expired_at', 'failed_at', 'fulfilled_at', 'refunded_at', 'ledger_posted_at',
            ]);
        });

        Schema::dropIfExists('payment_webhook_logs');
        Schema::dropIfExists('landing_order_items');
        Schema::dropIfExists('seller_balance_ledger');
        Schema::dropIfExists('seller_withdrawals');
        Schema::dropIfExists('seller_balances');
    }
};
