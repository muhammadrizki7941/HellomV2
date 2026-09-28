<?php

namespace App\Listeners\Pos;

use App\Events\Pos\OrderConfirmed;
use App\Events\Pos\OrderCreated;
use App\Events\Pos\OrderEvent;
use App\Events\Pos\OrderPaid;
use App\Events\Pos\OrderRefunded;
use App\Events\Pos\OrderStatusChanged;
use App\Events\Pos\OrderVoided;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Services\Pos\FraudDetector;
use App\Services\Pos\LoyaltyService;
use App\Services\Pos\OrderStatus;
use App\Services\Realtime\RealtimeClient;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Everything that must only happen once the order is committed: socket push, member
 * points, audit trail and fraud signals. Each step is isolated — a failing socket or
 * fraud check never undoes a payment; points are idempotent and can be re-checked
 * with pos:points:reconcile.
 */
final class OrderSideEffectsSubscriber
{
    /** Run only after the surrounding DB transaction commits. */
    public bool $afterCommit = true;

    public function __construct(
        private readonly RealtimeClient $realtime,
        private readonly LoyaltyService $loyalty,
        private readonly FraudDetector $fraud,
    ) {
    }

    public static function outletRoom(string $tenantSlug, int $outletId): string
    {
        return "tenant:{$tenantSlug}:outlet:{$outletId}";
    }

    public static function tableRoom(int $tableId): string
    {
        return "table:{$tableId}";
    }

    public function onAny(OrderEvent $event): void
    {
        $this->safely('broadcast', $event, fn () => $this->broadcast($event));
        $this->safely('audit', $event, fn () => $this->audit($event));
    }

    public function onPaid(OrderPaid $event): void
    {
        $this->onAny($event);
        $this->safely('points', $event, fn () => $this->loyalty->earnForOrder($event->order, $event->userId));
        $this->safely('fraud', $event, fn () => $this->fraud->inspectPaidOrder($event->order, $event->userId));
    }

    public function onVoided(OrderVoided $event): void
    {
        $this->onAny($event);
        $reason = 'Pesanan ' . $event->order->order_number . ' dibatalkan';
        $this->safely('points', $event, fn () => $this->loyalty->reverseForOrder($event->order, $reason, $event->userId));
    }

    public function onRefunded(OrderRefunded $event): void
    {
        $this->onAny($event);
        $reason = 'Refund pesanan ' . $event->order->order_number;
        $this->safely('points', $event, fn () => $this->loyalty->reverseForOrder($event->order, $reason, $event->userId));
    }

    private function broadcast(OrderEvent $event): void
    {
        $order = $event->order;
        $payload = [
            'event' => $event->name(),
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_label' => OrderStatus::LABELS[$order->status] ?? $order->status,
                'payment_status' => $order->payment_status,
                'order_source' => $order->order_source,
                'table_label' => $order->table_label,
                'dining_table_id' => $order->dining_table_id,
                'table_bill_id' => $order->table_bill_id,
                'final_amount' => (int) $order->final_amount,
                'updated_at' => optional($order->updated_at)->toIso8601String(),
            ],
        ];

        if ($order->outlet_id) {
            $this->realtime->emitToRoom(self::outletRoom((string) $order->tenant_id, (int) $order->outlet_id), 'pos.order', $payload);
        }
        if ($order->dining_table_id) {
            $this->realtime->emitToRoom(self::tableRoom((int) $order->dining_table_id), 'customer.order', $payload);
        }
    }

    private function audit(OrderEvent $event): void
    {
        $order = $event->order;
        $organizationId = $order->outlet_id ? Outlet::query()->whereKey($order->outlet_id)->value('organization_id') : null;
        $request = app()->runningInConsole() ? null : request();

        AuditLog::record(
            'pos.' . $event->name(),
            $event->userId,
            $organizationId ? (int) $organizationId : null,
            'order',
            (int) $order->id,
            null,
            [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'final_amount' => (int) $order->final_amount,
            ],
            $event->context ?: null,
            $request?->ip(),
            $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        );
    }

    private function safely(string $step, OrderEvent $event, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::error("POS order side effect failed: {$step}", [
                'event' => $event->name(),
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCreated::class => 'onAny',
            OrderConfirmed::class => 'onAny',
            OrderStatusChanged::class => 'onAny',
            OrderPaid::class => 'onPaid',
            OrderVoided::class => 'onVoided',
            OrderRefunded::class => 'onRefunded',
        ];
    }
}
