<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Platform finance ledger - tracks all platform revenue and expenses
        Schema::create('platform_finance_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index(); // 'revenue', 'expense', 'adjustment'
            $table->string('category', 100)->index(); // 'subscription', 'addon', 'refund', 'payout_fee', etc.
            $table->string('reference_type', 50)->nullable(); // 'subscription', 'invoice', 'payout', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('currency', 10)->default('IDR');
            $table->bigInteger('amount')->index(); // positive for credit, negative for debit
            $table->bigInteger('balance_before')->default(0); // balance before this transaction
            $table->bigInteger('balance_after')->default(0); // balance after this transaction
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('effective_at')->useCurrent(); // when the transaction actually occurred
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['type', 'effective_at']);
        });

        // Platform payouts - owner/platform withdrawals
        Schema::create('platform_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->unique(); // Xendit payout ID
            $table->string('status', 50)->default('pending')->index(); // pending, processing, paid, failed, cancelled
            $table->string('currency', 10)->default('IDR');
            $table->bigInteger('amount')->index();
            $table->bigInteger('fee')->default(0); // Xendit fee
            $table->bigInteger('net_amount')->index(); // amount - fee
            $table->string('bank_code')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('account_number_masked')->nullable(); // for display
            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['external_id']);
        });

        // Xendit balance snapshots - track available balance over time
        Schema::create('xendit_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('available_balance')->index();
            $table->bigInteger('pending_balance')->default(0)->index();
            $table->string('currency', 10)->default('IDR');
            $table->json('raw_response')->nullable(); // full API response
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamps();

            $table->index(['captured_at']);
        });

        // User wallet ledgers - separate from organization wallets
        Schema::create('user_wallet_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50)->index(); // 'deposit', 'withdrawal', 'payment', 'refund'
            $table->string('reference_type', 50)->nullable(); // 'topup', 'checkout', 'refund', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->bigInteger('amount')->index(); // positive for credit, negative for debit
            $table->bigInteger('balance_before')->default(0);
            $table->bigInteger('balance_after')->default(0);
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('effective_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['type', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_wallet_ledgers');
        Schema::dropIfExists('xendit_balance_snapshots');
        Schema::dropIfExists('platform_payouts');
        Schema::dropIfExists('platform_finance_ledgers');
    }
};
