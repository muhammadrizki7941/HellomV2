<?php

namespace App\Events\Pos;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for every order lifecycle event. All side effects — socket broadcast, stock,
 * member points, audit log, fraud signals — hang off these events (see
 * AppServiceProvider::registerPosOrderListeners), never off controllers.
 */
abstract class OrderEvent
{
    use Dispatchable;

    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly Order $order,
        public readonly ?int $userId = null,
        public readonly array $context = [],
    ) {
    }

    /** Short name used in socket payloads and audit logs, e.g. "order.paid". */
    public function name(): string
    {
        return match (static::class) {
            OrderCreated::class => 'order.created',
            OrderConfirmed::class => 'order.confirmed',
            OrderStatusChanged::class => 'order.status_changed',
            OrderPaid::class => 'order.paid',
            OrderVoided::class => 'order.voided',
            OrderRefunded::class => 'order.refunded',
            default => 'order.updated',
        };
    }
}
