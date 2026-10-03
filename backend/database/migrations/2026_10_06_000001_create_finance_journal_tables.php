<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry finance journal (append-only). One entry per business event, its lines
 * sum to zero. Accounts are string codes: gateway:{provider}, bank:hellom,
 * seller:{org}:pending|available|processing, wallet:{org}, refund:payable,
 * revenue:*, expense:*, equity:opening. Added next to seller_balance_ledger
 * (which stays the source of truth for seller balances) and reconciled against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 120)->unique();          // idempotency: one entry per event
            $table->string('event_type', 40);                     // sale_paid, platform_fee, release, withdrawal_requested, …
            $table->string('source', 30);                         // landing_page | digital_product | subscription | wallet_topup | seller_finance
            $table->string('source_type', 40)->nullable();        // table of the source row
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('provider', 20)->nullable();           // ipaymu | xendit | doku | manual | wallet
            $table->unsignedBigInteger('organization_id')->nullable(); // seller / buyer organization (tenant filter)
            $table->bigInteger('amount')->default(0);             // headline amount for lists (gross)
            $table->timestamp('occurred_at');
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source', 'occurred_at']);
            $table->index(['provider', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('finance_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('finance_journal_entries')->cascadeOnDelete();
            $table->string('account', 80);                        // e.g. gateway:ipaymu, seller:12:available
            $table->string('account_type', 20);                   // asset | liability | revenue | expense | equity
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->bigInteger('amount');                         // signed rupiah: debit +, credit −
            $table->timestamp('occurred_at');

            $table->index(['account', 'occurred_at']);
            $table->index(['organization_id', 'account']);
            $table->index(['account_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_journal_lines');
        Schema::dropIfExists('finance_journal_entries');
    }
};
