<?php

namespace App\Listeners\Hpp;

use App\Events\Pos\OrderPaid;
use App\Jobs\BackflushPosOrder;

/**
 * POS → Kalkulator HPP: queue the ingredient backflush once an order is paid. Only queues a job;
 * never touches the payment itself (a failure here cannot undo a sale).
 */
final class BackflushOnOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        BackflushPosOrder::dispatch((int) $event->order->id);
    }
}
