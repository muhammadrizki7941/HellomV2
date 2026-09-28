<?php

namespace App\Jobs;

use App\Models\LandingPageOrder;
use App\Services\SellerFinance\LandingPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Ask the gateway about one pending order (buyer just returned from the payment page). */
class ReconcileLandingOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(LandingPaymentService $payments): void
    {
        $order = LandingPageOrder::query()->find($this->orderId);
        if ($order && $order->status === LandingPageOrder::STATUS_PENDING) {
            try {
                $payments->reconcileOrder($order, 'return');
            } catch (\Throwable $e) {
                report($e); // the scheduled reconcile will try again
            }
        }
    }
}
