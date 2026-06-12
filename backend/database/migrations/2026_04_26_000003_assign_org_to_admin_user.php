<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $user = \App\Models\User::where('email', 'admin@resto.com')->first();
        if ($user && !$user->current_organization_id) {
            $org = \App\Models\Organization::first();
            if ($org) {
                $user->update(['current_organization_id' => $org->id]);
                $org->users()->attach($user->id, ['role' => 'owner']);
            }
        }
    }

    public function down(): void
    {
        // No down needed
    }
};