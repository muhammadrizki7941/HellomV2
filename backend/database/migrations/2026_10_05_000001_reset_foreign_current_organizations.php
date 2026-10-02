<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users with users.role "admin" (every self-registered owner) could switch into any
 * organization. Point everyone except platform roles back to an organization they
 * are a member of. The old values are kept in a backup table so down() restores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_organization_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('old_organization_id')->nullable();
            $table->unsignedBigInteger('new_organization_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('users')
            ->whereNotNull('current_organization_id')
            ->whereNotIn('role', ['super_admin', 'tenant_admin'])
            ->whereNotExists(function ($query) {
                $query->from('organization_user')
                    ->whereColumn('organization_user.user_id', 'users.id')
                    ->whereColumn('organization_user.organization_id', 'users.current_organization_id');
            })
            ->orderBy('id')
            ->select('id', 'current_organization_id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $newOrganizationId = DB::table('organization_user')
                        ->where('user_id', $user->id)
                        ->orderByRaw("role = 'owner' desc")
                        ->orderBy('organization_id')
                        ->value('organization_id');

                    DB::table('current_organization_resets')->insert([
                        'user_id' => $user->id,
                        'old_organization_id' => $user->current_organization_id,
                        'new_organization_id' => $newOrganizationId,
                        'created_at' => now(),
                    ]);
                    DB::table('users')->where('id', $user->id)->update(['current_organization_id' => $newOrganizationId]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('current_organization_resets')) {
            return;
        }

        DB::table('current_organization_resets')->orderBy('id')->each(function ($reset) {
            DB::table('users')->where('id', $reset->user_id)->update(['current_organization_id' => $reset->old_organization_id]);
        });

        Schema::dropIfExists('current_organization_resets');
    }
};
