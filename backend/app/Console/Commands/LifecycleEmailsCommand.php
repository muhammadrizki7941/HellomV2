<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\LifecycleEmails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly, once per account (users.lifecycle_emails):
 *  - verification reminder: signed up 24–72 h ago, email still unverified
 *  - day-2 tips: shop owner signed up 2–7 days ago, Hellom Page not published yet
 * Only recent sign-ups (the windows), so turning this on never mails old accounts.
 * Guest checkout accounts (pending_guest_credentials) and suspended users are skipped.
 */
class LifecycleEmailsCommand extends Command
{
    protected $signature = 'users:lifecycle-emails {--dry-run}';

    protected $description = 'Pengingat verifikasi email (24 jam) dan tips hari ke-2 untuk pendaftar baru';

    public function handle(LifecycleEmails $emails): int
    {
        $dry = (bool) $this->option('dry-run');
        $reminded = 0;
        $tipped = 0;

        $this->eligible()
            ->whereNull('email_verified_at')
            ->whereBetween('created_at', [now()->subHours(72), now()->subHours(24)])
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($emails, $dry, &$reminded) {
                foreach ($users as $user) {
                    if (LifecycleEmails::wasSent($user, 'verify_reminder')) {
                        continue;
                    }
                    $dry ? $this->line("would remind #{$user->id} {$user->email}") : $emails->verificationReminder($user);
                    $reminded++;
                }
            });

        $this->eligible()
            ->where('role', 'admin') // shop owners who signed up themselves
            ->whereBetween('created_at', [now()->subDays(7), now()->subHours(48)])
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($emails, $dry, &$tipped) {
                foreach ($users as $user) {
                    if (LifecycleEmails::wasSent($user, 'tips_day2') || $this->hasPublishedPage((int) $user->id)) {
                        continue;
                    }
                    $dry ? $this->line("would send tips to #{$user->id} {$user->email}") : $emails->dayTwoTips($user);
                    $tipped++;
                }
            });

        $this->info(($dry ? 'DRY RUN ' : '') . "verification reminders: {$reminded}, day-2 tips: {$tipped}");

        return self::SUCCESS;
    }

    private function eligible()
    {
        return User::query()
            ->whereIn('role', ['admin', 'member', 'tenant_admin'])
            ->where(fn ($q) => $q->whereNull('pending_guest_credentials')->orWhere('pending_guest_credentials', false))
            ->whereNotNull('email');
    }

    private function hasPublishedPage(int $userId): bool
    {
        return DB::table('organization_user')
            ->join('organization_landing_pages', 'organization_landing_pages.organization_id', '=', 'organization_user.organization_id')
            ->where('organization_user.user_id', $userId)
            ->whereIn('organization_user.role', ['owner', 'admin'])
            ->whereNotNull('organization_landing_pages.published_version_id')
            ->exists();
    }
}
