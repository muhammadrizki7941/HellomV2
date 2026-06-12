<?php

namespace Database\Seeders;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class HellomAdminSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->updateOrCreate(
            ['slug' => 'hellom-super-admin'],
            [
                'name' => 'Hellom Super Admin',
                'default_locale' => 'id',
                'status' => 'active',
            ]
        );

        $admin = User::query()->updateOrCreate(
            ['email' => ' '],
            [
                'name' => 'Hellom Super Admin',
                'phone' => '+6281111111111',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'points_balance' => 0,
            ]
        );

        // Super admin is global, not tied to any organization
        // No attachment to organization or current_organization_id

        $memberA = User::query()->updateOrCreate(
            ['email' => 'member1@hellom.local'],
            [
                'name' => 'Member Satu',
                'phone' => '+6281111111112',
                'password' => Hash::make('member123'),
                'role' => 'tenant_admin',
                'points_balance' => 0,
            ]
        );

        $memberB = User::query()->updateOrCreate(
            ['email' => 'member2@hellom.local'],
            [
                'name' => 'Member Dua',
                'phone' => '+6281111111113',
                'password' => Hash::make('member123'),
                'role' => 'tenant_admin',
                'points_balance' => 0,
            ]
        );

        $adminOps = User::query()->updateOrCreate(
            ['email' => 'opsadmin@hellom.local'],
            [
                'name' => 'Ops Admin',
                'phone' => '+6281111111114',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'points_balance' => 0,
            ]
        );

        $this->attachToOrganization($organization, $memberA, 'owner');
        $this->attachToOrganization($organization, $memberB, 'owner');
        $this->attachToOrganization($organization, $adminOps, 'admin');

        $this->seedDefaultEntitlements($organization);

        $this->command->info('Hellom admin seeder completed.');
        $this->command->info('Login: superadmin@hellom.local / admin123');
        $this->command->info('Demo members: member1@hellom.local / member123, member2@hellom.local / member123');
        $this->command->info('Demo org admin: opsadmin@hellom.local / admin123');
        $this->command->info('Organization: hellom-super-admin');
    }

    private function attachToOrganization(Organization $organization, User $user, string $role): void
    {
        DB::table('organization_user')->updateOrInsert(
            [
                'organization_id' => (int) $organization->id,
                'user_id' => (int) $user->id,
            ],
            [
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if ((int) ($user->current_organization_id ?? 0) !== (int) $organization->id) {
            $user->forceFill([
                'current_organization_id' => $organization->id,
            ])->save();
        }
    }

    private function seedDefaultEntitlements(Organization $organization): void
    {
        $landingAppId = AppCatalog::query()->where('slug', 'landing_builder')->value('id');
        $posAppId = AppCatalog::query()->where('slug', 'pos')->value('id');
        $freePlanId = Plan::query()->where('slug', 'free')->value('id');
        $posPlanId = Plan::query()->where('slug', 'pos_starter')->value('id');

        if ($landingAppId) {
            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'app_id' => $landingAppId,
                ],
                [
                    'plan_id' => $freePlanId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }

        if ($posAppId) {
            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'app_id' => $posAppId,
                ],
                [
                    'plan_id' => $posPlanId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }
    }
}
