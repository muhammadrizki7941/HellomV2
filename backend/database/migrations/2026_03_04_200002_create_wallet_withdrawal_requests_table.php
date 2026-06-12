<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('organization_wallets')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->bigInteger('amount');
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('net_amount');
            $table->string('bank_code', 30)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->string('provider', 40)->default('xendit');
            $table->string('external_ref', 120)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'wwr_org_status_idx');
            $table->index(['organization_id', 'created_at'], 'wwr_org_created_idx');
            $table->index(['external_ref'], 'wwr_ext_idx');
            $table->index(['provider_ref'], 'wwr_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawal_requests');
    }
};
