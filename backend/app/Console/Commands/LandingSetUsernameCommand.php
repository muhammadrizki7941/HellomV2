<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Landing\LandingShop;
use Illuminate\Console\Command;

/**
 * Set a shop's Hellom Page username from the console (e.g. shops whose org slug became a
 * reserved word). Same rules as the editor; the old address keeps redirecting.
 */
class LandingSetUsernameCommand extends Command
{
    protected $signature = 'landing:set-username {organization : organization id} {username}';

    protected $description = 'Set the public Hellom Page username of a shop';

    public function handle(LandingShop $shop): int
    {
        $organization = Organization::query()->find((int) $this->argument('organization'));
        if (!$organization) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }
        $username = strtolower(trim((string) $this->argument('username')));
        if ($problem = $shop->usernameProblem($username, (int) $organization->id)) {
            $this->error($problem);

            return self::FAILURE;
        }
        $old = $organization->landingUsername();
        $shop->changeUsername($organization, $username);
        $this->info("{$organization->name}: {$old} → {$username} ({$shop->publicUrl($organization->fresh())})");

        return self::SUCCESS;
    }
}
