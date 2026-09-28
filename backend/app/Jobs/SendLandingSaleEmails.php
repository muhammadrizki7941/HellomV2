<?php

namespace App\Jobs;

use App\Models\LandingPageOrder;
use App\Services\Hellom\LandingSaleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Buyer receipt + seller notification after a sale is booked (queued, off the webhook request). */
class SendLandingSaleEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(LandingSaleService $sales): void
    {
        $order = LandingPageOrder::query()->find($this->orderId);
        if ($order && $order->isPaid()) {
            $sales->sendSaleEmails($order);
        }
    }
}
