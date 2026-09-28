<?php

namespace App\Services\Pos;

use App\Models\MemberPointTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosLoyaltySetting;
use App\Models\PosMember;
use App\Models\PosRedemption;
use App\Services\Pos\Verification\PointRedemptionVerifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Member points on an append-only ledger (member_point_transactions).
 *
 * - Balance = SUM(points) of the member's ledger rows; pos_members.redeemable_points is a
 *   cache updated in the same transaction (rebuild with pos:points:reconcile).
 * - Every change locks the member row (SELECT … FOR UPDATE), so two outlets redeeming at
 *   the same moment can never push the balance below zero.
 * - Positive rows are "lots" with remaining_points; redemption, reversal and expiry use
 *   the oldest lots first (FIFO).
 * - Each order-related row has an idempotency key, so retries never double-count.
 * Settings are per organization (all outlets share one member base and one rule set).
 */
final class LoyaltyService
{
    public function __construct(private readonly PointRedemptionVerifier $verifier)
    {
    }

    public static function organizationSlug(Organization $organization): string
    {
        return (string) ($organization->pos_tenant_slug ?: $organization->slug);
    }

    public function settingsFor(Organization $organization): PosLoyaltySetting
    {
        return PosLoyaltySetting::currentForTenant(self::organizationSlug($organization));
    }

    public function balance(PosMember $member): int
    {
        return (int) MemberPointTransaction::query()->where('member_id', $member->id)->sum('points');
    }

    public function pointsForSpend(PosLoyaltySetting $settings, int $spend): int
    {
        if (!$settings->enabled || $spend < (int) $settings->min_spend_amount) {
            return 0;
        }
        $points = intdiv(max(0, $spend), max(1, (int) $settings->points_per_amount));
        if ($settings->max_points_per_order !== null) {
            $points = min($points, (int) $settings->max_points_per_order);
        }

        return max(0, $points);
    }

    /**
     * Check a redemption request and return its rupiah value. $member must already be
     * locked by the caller's transaction. Throws PricingException with a clear reason.
     *
     * @param array<string,mixed> $verification e.g. ['confirm_member_name' => '…']
     */
    public function validateRedemption(PosMember $member, PosLoyaltySetting $settings, int $points, int $maxDiscount, int $alsoReserved, array $verification, bool $verify = true): int
    {
        if ($points <= 0) {
            return 0;
        }
        $value = (int) ($settings->redeem_value_per_point ?? 0);
        if (!$settings->enabled || $value <= 0) {
            throw new PricingException('Penukaran poin belum diaktifkan untuk toko ini.', [], 'POINTS_REDEEM_DISABLED');
        }
        if ($points < (int) $settings->min_redeem_points) {
            throw new PricingException("Minimal penukaran {$settings->min_redeem_points} poin.", [], 'POINTS_BELOW_MIN');
        }
        if ($settings->max_redeem_points_per_order !== null && $points > (int) $settings->max_redeem_points_per_order) {
            throw new PricingException("Maksimal {$settings->max_redeem_points_per_order} poin per transaksi.", [], 'POINTS_ABOVE_MAX');
        }
        $balance = $this->balance($member) - $alsoReserved;
        if ($points > $balance) {
            throw new PricingException("Saldo poin tidak cukup (tersedia {$balance} poin).", [], 'POINTS_INSUFFICIENT');
        }
        $discount = $points * $value;
        if ($discount > $maxDiscount) {
            $maxPoints = intdiv($maxDiscount, $value);
            throw new PricingException("Poin melebihi total belanja. Maksimal {$maxPoints} poin untuk pesanan ini.", [], 'POINTS_ABOVE_TOTAL');
        }

        if ($verify) {
            $this->verifier->verify($member, $verification);
        }

        return $discount;
    }

    /** Deduct points used on an order (inside the order transaction; member already locked). */
    public function redeemForOrder(PosMember $member, Order $order, int $points, string $reason, ?int $userId, string $key): ?MemberPointTransaction
    {
        if ($points <= 0) {
            return null;
        }
        if (MemberPointTransaction::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }
        $this->consumeLots($member, $points);

        return $this->append($member, [
            'type' => MemberPointTransaction::TYPE_REDEEM,
            'points' => -$points,
            'order_id' => $order->id,
            'outlet_id' => $order->outlet_id,
            'reason' => $reason,
            'user_id' => $userId,
            'idempotency_key' => $key,
        ]);
    }

    /** Award points for a PAID order (OrderPaid listener). Safe to call more than once. */
    public function earnForOrder(Order $order, ?int $userId = null): ?MemberPointTransaction
    {
        if (!$order->member_id || !$order->isPaid()) {
            return null;
        }

        return DB::transaction(function () use ($order, $userId) {
            /** @var Order $fresh */
            $fresh = Order::withoutGlobalScope('tenant')->lockForUpdate()->find($order->id);
            if (!$fresh) {
                return null;
            }
            $meta = (array) ($fresh->payment_meta ?? []);
            if (!empty($meta['loyalty_applied'])) {
                return null;
            }
            $member = PosMember::query()->lockForUpdate()->find($fresh->member_id);
            $organization = $fresh->outlet?->organization ?? ($member?->organization_id ? Organization::query()->find($member->organization_id) : null);
            if (!$member || !$organization) {
                return null;
            }

            $settings = $this->settingsFor($organization);
            $spend = max(0, (int) $fresh->final_amount);
            $points = $this->pointsForSpend($settings, $spend);

            // A "bonus poin" reward applied to this order adds to what is earned.
            $bonus = (int) (PosRedemption::query()->where('order_id', $fresh->id)->with('rewardRule')->get()
                ->filter(fn ($r) => $r->rewardRule?->reward_type === 'bonus_points')
                ->sum(fn ($r) => (int) $r->rewardRule->reward_value));
            $points += max(0, $bonus);

            $row = null;
            if ($points > 0) {
                $row = $this->append($member, [
                    'type' => MemberPointTransaction::TYPE_EARN,
                    'points' => $points,
                    'remaining_points' => $points,
                    'expires_at' => $this->expiryFrom($settings, now()),
                    'order_id' => $fresh->id,
                    'outlet_id' => $fresh->outlet_id,
                    'reason' => "Poin dari pesanan {$fresh->order_number}",
                    'user_id' => $userId,
                    'idempotency_key' => 'earn:order:' . $fresh->id,
                    'metadata' => $bonus > 0 ? ['bonus_points' => $bonus] : null,
                ]);
                $member->total_points = (int) $member->total_points + $points;
            }

            $member->total_orders = (int) $member->total_orders + 1;
            $member->total_spent = (int) $member->total_spent + $spend;
            $member->last_order_at = now();
            $member->save();

            $meta['loyalty_applied'] = true;
            $meta['loyalty_spend'] = $spend;
            $fresh->forceFill(['points_earned' => $points, 'payment_meta' => $meta])->save();

            return $row;
        });
    }

    /**
     * Undo loyalty for a voided/refunded order: take back earned points (never below
     * zero) and return points the order had used. Safe to call more than once.
     */
    public function reverseForOrder(Order $order, string $reason, ?int $userId = null): void
    {
        if (!$order->member_id) {
            return;
        }

        DB::transaction(function () use ($order, $reason, $userId) {
            $fresh = Order::withoutGlobalScope('tenant')->lockForUpdate()->find($order->id);
            $member = PosMember::query()->lockForUpdate()->find($order->member_id);
            if (!$fresh || !$member) {
                return;
            }
            $organization = $fresh->outlet?->organization ?? Organization::query()->find($member->organization_id);
            $settings = $organization ? $this->settingsFor($organization) : null;

            // 1) Earned points go back (only what is still there; the shortfall is recorded).
            $earn = MemberPointTransaction::query()->where('idempotency_key', 'earn:order:' . $fresh->id)->first();
            $earnKey = 'reversal:earn:order:' . $fresh->id;
            if ($earn && !MemberPointTransaction::query()->where('idempotency_key', $earnKey)->exists()) {
                $take = min((int) $earn->points, max(0, $this->balance($member)));
                if ($take > 0) {
                    $this->consumeLots($member, $take, preferLotId: $earn->id);
                }
                $this->append($member, [
                    'type' => MemberPointTransaction::TYPE_REVERSAL,
                    'points' => -$take,
                    'order_id' => $fresh->id,
                    'outlet_id' => $fresh->outlet_id,
                    'reverses_id' => $earn->id,
                    'reason' => $reason,
                    'user_id' => $userId,
                    'idempotency_key' => $earnKey,
                    'metadata' => $take < (int) $earn->points ? ['shortfall' => (int) $earn->points - $take] : null,
                ]);
                $member->total_points = max(0, (int) $member->total_points - $take);
            }

            // Member stats counted at payment are undone too.
            $meta = (array) ($fresh->payment_meta ?? []);
            if (!empty($meta['loyalty_applied']) && empty($meta['loyalty_reversed'])) {
                $member->total_orders = max(0, (int) $member->total_orders - 1);
                $member->total_spent = max(0, (int) $member->total_spent - (int) ($meta['loyalty_spend'] ?? 0));
                $meta['loyalty_reversed'] = true;
                $fresh->forceFill(['payment_meta' => $meta])->save();
            }

            // 2) Points the order used are given back as a fresh lot.
            foreach (MemberPointTransaction::query()->where('order_id', $fresh->id)->where('type', MemberPointTransaction::TYPE_REDEEM)->get() as $redeem) {
                $key = 'reversal:redeem:' . $redeem->id;
                if (MemberPointTransaction::query()->where('idempotency_key', $key)->exists()) {
                    continue;
                }
                $back = abs((int) $redeem->points);
                $this->append($member, [
                    'type' => MemberPointTransaction::TYPE_REVERSAL,
                    'points' => $back,
                    'remaining_points' => $back,
                    'expires_at' => $settings ? $this->expiryFrom($settings, now()) : null,
                    'order_id' => $fresh->id,
                    'outlet_id' => $fresh->outlet_id,
                    'reverses_id' => $redeem->id,
                    'reason' => $reason,
                    'user_id' => $userId,
                    'idempotency_key' => $key,
                ]);
            }

            $member->save();
        });
    }

    /** Manual adjustment (owner/supervisor only — checked by the controller). */
    public function adjust(PosMember $member, int $points, string $reason, ?int $userId, ?int $outletId = null): MemberPointTransaction
    {
        if ($points === 0) {
            throw new PricingException('Jumlah poin tidak boleh 0.', [], 'POINTS_ZERO');
        }

        return DB::transaction(function () use ($member, $points, $reason, $userId, $outletId) {
            $locked = PosMember::query()->lockForUpdate()->findOrFail($member->id);
            if ($points < 0) {
                if (abs($points) > $this->balance($locked)) {
                    throw new PricingException('Saldo poin tidak cukup untuk dikurangi sebanyak itu.', [], 'POINTS_INSUFFICIENT');
                }
                $this->consumeLots($locked, abs($points));
            }
            $organization = Organization::query()->find($locked->organization_id);

            $row = $this->append($locked, [
                'type' => MemberPointTransaction::TYPE_ADJUST,
                'points' => $points,
                'remaining_points' => $points > 0 ? $points : null,
                'expires_at' => $points > 0 && $organization ? $this->expiryFrom($this->settingsFor($organization), now()) : null,
                'outlet_id' => $outletId,
                'reason' => $reason,
                'user_id' => $userId,
            ]);
            $locked->save();

            return $row;
        });
    }

    /** Expire lots past their date (scheduled daily). Returns the number of expire rows written. */
    public function expireDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;
        $memberIds = MemberPointTransaction::query()
            ->where('remaining_points', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->distinct()
            ->pluck('member_id');

        foreach ($memberIds as $memberId) {
            $count += DB::transaction(function () use ($memberId, $now) {
                $member = PosMember::query()->lockForUpdate()->find($memberId);
                if (!$member) {
                    return 0;
                }
                $written = 0;
                $lots = MemberPointTransaction::query()
                    ->where('member_id', $memberId)
                    ->where('remaining_points', '>', 0)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $now)
                    ->lockForUpdate()
                    ->get();
                foreach ($lots as $lot) {
                    $amount = (int) $lot->remaining_points;
                    $lot->forceFill(['remaining_points' => 0])->save();
                    $this->append($member, [
                        'type' => MemberPointTransaction::TYPE_EXPIRE,
                        'points' => -$amount,
                        'outlet_id' => $lot->outlet_id,
                        'reverses_id' => $lot->id,
                        'reason' => 'Poin kedaluwarsa',
                        'idempotency_key' => 'expire:lot:' . $lot->id,
                    ]);
                    $written++;
                }
                $member->save();

                return $written;
            });
        }

        return $count;
    }

    /** @return array{member_id:int, cached:int, ledger:int, ok:bool} */
    public function reconcile(PosMember $member, bool $fix = false): array
    {
        $ledger = $this->balance($member);
        $cached = (int) $member->redeemable_points;
        if ($fix && $ledger !== $cached) {
            $member->forceFill(['redeemable_points' => $ledger])->save();
        }

        return ['member_id' => $member->id, 'cached' => $cached, 'ledger' => $ledger, 'ok' => $ledger === $cached];
    }

    private function expiryFrom(PosLoyaltySetting $settings, CarbonInterface $from): ?CarbonInterface
    {
        $months = $settings->points_expire_months;

        return $months ? $from->copy()->addMonthsNoOverflow((int) $months) : null;
    }

    /** Write a ledger row and refresh the cached balance (member must be locked). */
    private function append(PosMember $member, array $attributes): MemberPointTransaction
    {
        $balance = $this->balance($member) + (int) $attributes['points'];
        $row = MemberPointTransaction::query()->create(array_merge([
            'organization_id' => $member->organization_id,
            'member_id' => $member->id,
            'balance_after' => $balance,
        ], $attributes));
        $member->redeemable_points = $balance;

        return $row;
    }

    /** Take $points from the member's open lots, oldest (or the preferred lot) first. */
    private function consumeLots(PosMember $member, int $points, ?int $preferLotId = null): void
    {
        $lots = MemberPointTransaction::query()
            ->where('member_id', $member->id)
            ->where('remaining_points', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw($preferLotId ? 'id = ' . (int) $preferLotId . ' DESC' : '1')
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $left = $points;
        foreach ($lots as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int) $lot->remaining_points);
            $lot->forceFill(['remaining_points' => (int) $lot->remaining_points - $take])->save();
            $left -= $take;
        }
    }
}
