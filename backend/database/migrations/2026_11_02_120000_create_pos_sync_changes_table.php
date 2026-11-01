<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sync_changes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('operation', 20)->default('deleted');
            $table->timestamps();
            $table->index(['tenant_id', 'outlet_id', 'created_at'], 'pos_sync_changes_scope_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_changes');
    }
};
