<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('organization_wallets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reference_type', 80)->nullable();
            $table->string('reference_id', 120)->nullable();
            $table->string('external_ref', 120)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'owt_org_created_idx');
            $table->index(['organization_id', 'type'], 'owt_org_type_idx');
            $table->index(['reference_type', 'reference_id'], 'owt_ref_idx');
            $table->index(['external_ref'], 'owt_ext_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_wallet_transactions');
    }
};
