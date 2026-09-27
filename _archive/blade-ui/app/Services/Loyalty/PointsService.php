<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltySetting;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PointsService
{
    public function awardForCompletedOrder(Order $order): void
    {
        if (!$order->user_id) {
            return;
        }

        $setting = LoyaltySetting::current();
        if (!$setting || !$setting->enabled) {
            return;
        }

        $totalAmount = (int) ($order->total_amount ?? 0);
        $minSpend = (int) ($setting->min_spend_amount ?? 0);
        if ($totalAmount < $minSpend) {
            return;
        }

        $method = (string) ($setting->earn_method ?? 'per_1000');
        $points = 0;

        if ($method === 'per_min_spend') {
            // Award points only per full multiples of min spend.
            if ($minSpend <= 0) {
                return;
            }
            $per = (int) ($setting->points_per_min_spend ?? 0);
            if ($per <= 0) {
                return;
            }
            $points = (int) floor($totalAmount / $minSpend) * $per;
        } elseif ($method === 'per_unit') {
            $unit = (int) ($setting->points_unit_amount ?? 0);
            $per = (int) ($setting->points_per_unit ?? 0);
            if ($unit <= 0 || $per <= 0) {
                return;
            }
            $points = (int) floor($totalAmount / $unit) * $per;
        } elseif ($method === 'flat') {
            $points = (int) ($setting->flat_points_per_order ?? 0);
        } else {
            // Legacy: per Rp 1.000
            $pointsPer1000 = (int) ($setting->points_per_1000 ?? 0);
            if ($pointsPer1000 <= 0) {
                return;
            }
            $points = (int) floor($totalAmount / 1000) * $pointsPer1000;
        }

        if ($points <= 0) {
            return;
        }

        $maxPoints = $setting->max_points_per_order;
        if (is_int($maxPoints) && $maxPoints > 0) {
            $points = min($points, $maxPoints);
        }

        DB::transaction(function () use ($order, $points) {
            $exists = PointTransaction::query()
                ->where('user_id', $order->user_id)
                ->where('source_type', 'order')
                ->where('source_id', $order->id)
                ->exists();

            if ($exists) {
                return;
            }

            PointTransaction::query()->create([
                'user_id' => $order->user_id,
                'source_type' => 'order',
                'source_id' => $order->id,
                'points' => $points,
                'note' => 'Poin dari order '.$order->order_number,
            ]);

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($order->user_id);
            $user->points_balance = (int) ($user->points_balance ?? 0) + $points;
            $user->save();
        });
    }

    public function refundRedeemForCancelledOrder(Order $order): void
    {
        $redeemed = (int) ($order->redeemed_points ?? 0);
        if (!$order->user_id || $redeemed <= 0) {
            return;
        }

        DB::transaction(function () use ($order, $redeemed) {
            $exists = PointTransaction::query()
                ->where('user_id', $order->user_id)
                ->where('source_type', 'order_redeem_refund')
                ->where('source_id', $order->id)
                ->exists();

            if ($exists) {
                return;
            }

            PointTransaction::query()->create([
                'user_id' => $order->user_id,
                'source_type' => 'order_redeem_refund',
                'source_id' => $order->id,
                'points' => $redeemed,
                'note' => 'Refund poin (order dibatalkan) '.$order->order_number,
            ]);

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($order->user_id);
            $user->points_balance = (int) ($user->points_balance ?? 0) + $redeemed;
            $user->save();
        });
    }
}
