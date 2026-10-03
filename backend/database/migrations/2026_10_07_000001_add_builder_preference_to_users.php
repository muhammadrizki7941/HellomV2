<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hellom Page editor onboarding (link-in-bio, Fase 1): what the seller used before
 * (lynk | linktree | orderhero | none) picks the editor preset; the tour is shown once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('builder_preference', 20)->nullable()->after('role');
            $table->timestamp('builder_tour_done_at')->nullable()->after('builder_preference');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['builder_preference', 'builder_tour_done_at']);
        });
    }
};
