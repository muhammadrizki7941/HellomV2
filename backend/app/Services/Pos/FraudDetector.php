<?php

namespace App\Services\Pos;

use App\Models\Order;
use App\Models\PosFraudFlag;
use App\Models\PosMember;
use App\Models\PosStaff;
use App\Models\PosStaffShift;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;

/**
 * Signals for the owner, never blocks a sale. Runs after a member order is paid.
 *
 *  - member_repeat_same_cashier: one cashier puts the same member on REPEAT_THRESHOLD or
 *    more paid orders within one shift (or the last 8 hours when no shift is scheduled).
 *  - member_phone_is_staff: the member's phone belongs to a staff member of the outlet group.
 *
 * One open flag per rule/member/cashier per day, so a busy day doesn't flood the list.
 */
final class FraudDetector
{
    public const REPEAT_THRESHOLD = 3;

    public function inspectPaidOrder(Order $order, ?int $cashierUserId): void
    {
        if (!$order->member_id || !$order->outlet_id) {
            return;
        }
        $member = PosMember::query()->find($order->member_id);
        if (!$member || !$member->organization_id) {
            return;
        }
        $cashierUserId ??= $order->user_id;

        if ($cashierUserId) {
            [$from, $to] = $this->shiftWindow($cashierUserId, (int) $member->organization_id);
            $count = Order::withoutGlobalScope('tenant')
                ->where('member_id', $member->id)
                ->where('payment_status', Order::PAYMENT_PAID)
                ->whereBetween('paid_at', [$from, $to])
                ->where(fn ($q) => $q->where('user_id', $cashierUserId)->orWhere('payment_meta->paid_by', $cashierUserId))
                ->count();
            if ($count >= self::REPEAT_THRESHOLD) {
                $this->flag('member_repeat_same_cashier', $order, $member, $cashierUserId, [
                    'orders_in_window' => $count, 'window_from' => $from->toIso8601String(), 'window_to' => $to->toIso8601String(),
                ]);
            }
        }

        $phone = $member->phone_normalized;
        if ($phone) {
            $staffUserPhones = User::query()
                ->whereIn('id', fn ($q) => $q->select('user_id')->from('organization_user')->where('organization_id', $member->organization_id))
                ->whereNotNull('phone')->pluck('phone', 'id');
            $staffPhones = PosStaff::query()->where('organization_id', $member->organization_id)->whereNotNull('phone')->pluck('phone', 'id');

            $match = $staffUserPhones->filter(fn ($p) => PhoneNumber::normalize($p) === $phone)->keys()->first();
            $staffMatch = $staffPhones->filter(fn ($p) => PhoneNumber::normalize($p) === $phone)->keys()->first();
            if ($match || $staffMatch) {
                $this->flag('member_phone_is_staff', $order, $member, $cashierUserId, array_filter([
                    'staff_user_id' => $match, 'pos_staff_id' => $staffMatch,
                ]));
            }
        }
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} */
    private function shiftWindow(int $userId, int $organizationId): array
    {
        $staffIds = PosStaff::query()->where('organization_id', $organizationId)->where('linked_user_id', $userId)->pluck('id');
        $shift = $staffIds->isEmpty() ? null : PosStaffShift::query()
            ->whereIn('staff_id', $staffIds)
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now()->subHour())
            ->orderByDesc('start_at')
            ->first();

        return $shift
            ? [$shift->start_at->copy(), now()]
            : [now()->subHours(8), now()];
    }

    private function flag(string $rule, Order $order, PosMember $member, ?int $userId, array $details): void
    {
        $exists = PosFraudFlag::query()
            ->where('rule', $rule)
            ->where('member_id', $member->id)
            ->where('user_id', $userId)
            ->where('status', 'open')
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
        if ($exists) {
            return;
        }

        PosFraudFlag::query()->create([
            'organization_id' => $member->organization_id,
            'outlet_id' => $order->outlet_id,
            'rule' => $rule,
            'member_id' => $member->id,
            'user_id' => $userId,
            'order_id' => $order->id,
            'details' => $details,
            'status' => 'open',
        ]);
    }
}
