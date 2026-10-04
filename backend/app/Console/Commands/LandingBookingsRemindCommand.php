<?php

namespace App\Console\Commands;

use App\Services\Landing\BookingService;
use Illuminate\Console\Command;

/** Hellom Page rentals: email buyer + seller the day before a confirmed booking (each booking once). */
class LandingBookingsRemindCommand extends Command
{
    protected $signature = 'landing:bookings-remind';

    protected $description = 'Kirim pengingat H-1 untuk booking sewa yang sudah lunas';

    public function handle(BookingService $bookings): int
    {
        $sent = $bookings->remindDue();
        $this->info("Pengingat dikirim: {$sent}");

        return self::SUCCESS;
    }
}
