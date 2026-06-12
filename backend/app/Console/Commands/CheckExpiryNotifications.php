<?php

namespace App\Console\Commands;

use App\Models\OwnerNotification;
use App\Models\Subscription;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckExpiryNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:check-expiry {--dry-run : Show what would be created without creating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check subscriptions expiring in 7, 3, 1 days and create notifications if not exists';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $now = Carbon::now();
        $notificationService = app(NotificationService::class);

        $daysToCheck = [7, 3, 1];
        $created = 0;

        foreach ($daysToCheck as $days) {
            $expiryDate = $now->copy()->addDays($days)->endOfDay();
            $startDate = $expiryDate->copy()->startOfDay();

            $subscriptions = Subscription::query()
                ->where('status', 'active') // asumsi status active
                ->whereBetween('ends_at', [$startDate, $expiryDate])
                ->with(['organization', 'app', 'plan'])
                ->get();

            foreach ($subscriptions as $subscription) {
                // Check if notification already exists for this subscription and days
                $exists = OwnerNotification::where('type', 'expiry_reminder')
                    ->where('notifiable_type', Subscription::class)
                    ->where('notifiable_id', $subscription->id)
                    ->whereJsonContains('data->days_left', $days)
                    ->whereDate('created_at', $now->toDateString()) // same day
                    ->exists();

                if ($exists) {
                    continue;
                }

                if ($isDryRun) {
                    $this->info("DRY-RUN: Would create expiry notification for subscription {$subscription->id} ({$days} days left)");
                    continue;
                }

                $notificationService->createExpiryReminderNotif($subscription, $days);
                $created++;
            }
        }

        if ($isDryRun) {
            $this->info("Dry run completed. Would create {$created} notifications.");
        } else {
            $this->info("Created {$created} expiry notifications.");
        }

        return 0;
    }
}
