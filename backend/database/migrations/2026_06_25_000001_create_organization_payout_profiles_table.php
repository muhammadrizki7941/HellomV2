<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_payout_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // KYC identity (KTP)
            $table->string('full_name', 150)->nullable();
            $table->string('nik', 32)->nullable(); // KTP number
            $table->string('ktp_image_disk', 30)->nullable();
            $table->string('ktp_image_path', 255)->nullable(); // stored on a private disk (PII)
            // Bank account
            $table->string('bank_code', 30)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_name', 120)->nullable();
            // Review lifecycle
            $table->string('status', 20)->default('unverified'); // unverified|pending|verified|rejected
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status'], 'opp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_payout_profiles');
    }
};
