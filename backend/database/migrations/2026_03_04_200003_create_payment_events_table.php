<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 120);
            $table->string('event_type', 120);
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 20)->default('received');
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'payevt_provider_event_uidx');
            $table->index(['provider', 'event_type'], 'payevt_provider_type_idx');
            $table->index(['organization_id', 'created_at'], 'payevt_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
