<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old Hellom Page usernames keep working: hellomspace.com/{old} redirects (301) to the
 * shop's current username, so links already shared on social media stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_username_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 40)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_username_redirects');
    }
};
