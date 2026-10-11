<?php

namespace App\Services\Pos\Audit;

use App\Models\PosActivityLog;
use App\Support\Pos\ActivityActions as A;
use Illuminate\Support\Carbon;

/**
 * Fraud alert summary per cashier from the audit trail: void / refund / reprint / manual discount /
 * item reduction ratios compared with the shop's own average for the same period, cash shortages at
 * closing, drawer openings without a sale, payment-method changes, wrong PINs.
 * A cashier is flagged when a ratio is well above the shop average (2×, with a minimum floor
 * and a minimum count so one void on a quiet day is not an alarm).
 */
final class AnomalyReport
{
    /** metric => [min count, min rate, label] */
    private const RULES = [
        'voids' => [2, 0.05, 'Rasio pembatalan (void) tinggi'],
        'refunds' => [2, 0.05, 'Rasio refund tinggi'],
        'reprints' => [3, 0.10, 'Sering cetak ulang struk'],
        'discounts' => [2, 0.05, 'Sering memberi diskon manual'],
        'item_reductions' => [3, 0.10, 'Sering mengurangi/menghapus item pesanan'],
    ];

    /** Drawer opened without a sale this many times per active day → flagged. */
    public const NO_SALE_PER_DAY = 3;

    public const CASH_SHORT_CRITICAL = 50000;

    /** @return array{from: string, to: string, days: int, average: array<string, float>, cashiers: list<array<string, mixed>>} */
    public function build(int $organizationId, Carbon $from, Carbon $to, ?int $outletId = null): array
    {
        $logs = PosActivityLog::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereIn('action', [A::PAYMENT_RECORDED, A::TABLE_BILL_PAID, A::ORDER_VOID, A::ORDER_REFUND, A::RECEIPT_REPRINTED, A::CASH_CLOSED, A::PIN_FAILED,
                A::DISCOUNT_APPLIED, A::ITEM_REMOVED, A::ITEM_QTY_CHANGED, A::PAYMENT_METHOD_CHANGED, A::DRAWER_NO_SALE])
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->orderBy('id')
            ->get(['action', 'actor_user_id', 'actor_staff_id', 'actor_name', 'actor_role', 'after_state', 'occurred_at']);

        $people = [];
        foreach ($logs as $log) {
            $key = $log->actor_staff_id ? 's' . $log->actor_staff_id : 'u' . ($log->actor_user_id ?? 0);
            $p = &$people[$key];
            $p ??= ['name' => $log->actor_name ?: 'Tanpa nama', 'role' => $log->actor_role, 'transactions' => 0, 'voids' => 0, 'refunds' => 0,
                'reprints' => 0, 'cash_closes' => 0, 'cash_short' => 0, 'cash_over' => 0, 'pin_failed' => 0,
                'discounts' => 0, 'discount_total' => 0, 'item_reductions' => 0, 'method_changes' => 0, 'no_sales' => 0, 'active_days' => []];
            $p['active_days'][substr((string) $log->occurred_at, 0, 10)] = true;
            $after = (array) $log->after_state;
            switch ($log->action) {
                case A::PAYMENT_RECORDED:
                    $p['transactions']++;
                    break;
                case A::TABLE_BILL_PAID:
                    $p['transactions'] += max(1, count((array) ($after['order_ids'] ?? [])));
                    break;
                case A::ORDER_VOID:
                    $p['voids']++;
                    $p['transactions']++;
                    break;
                case A::ORDER_REFUND:
                    $p['refunds']++;
                    break;
                case A::RECEIPT_REPRINTED:
                    $p['reprints']++;
                    break;
                case A::CASH_CLOSED:
                    $p['cash_closes']++;
                    $diff = (int) ($after['difference_cash'] ?? 0);
                    $diff < 0 ? $p['cash_short'] += -$diff : $p['cash_over'] += $diff;
                    break;
                case A::PIN_FAILED:
                    $p['pin_failed']++;
                    break;
                case A::DISCOUNT_APPLIED:
                    $p['discounts']++;
                    $p['discount_total'] += (int) ($after['manual_discount_amount'] ?? 0);
                    break;
                case A::ITEM_REMOVED:
                    $p['item_reductions']++;
                    break;
                case A::ITEM_QTY_CHANGED:
                    $changed = (array) ($after['changed_item'] ?? []);
                    if ((int) ($changed['to'] ?? 0) < (int) ($changed['from'] ?? 0)) {
                        $p['item_reductions']++;
                    }
                    break;
                case A::PAYMENT_METHOD_CHANGED:
                    $p['method_changes']++;
                    break;
                case A::DRAWER_NO_SALE:
                    $p['no_sales']++;
                    break;
            }
            unset($p);
        }

        $totalTx = max(1, array_sum(array_column($people, 'transactions')));
        $average = [];
        foreach (array_keys(self::RULES) as $metric) {
            $average[$metric] = round(array_sum(array_column($people, $metric)) / $totalTx, 4);
        }

        $cashiers = [];
        foreach ($people as $p) {
            $tx = max(1, $p['transactions']);
            $flags = [];
            foreach (self::RULES as $metric => [$minCount, $minRate, $label]) {
                $rate = $p[$metric] / $tx;
                $threshold = max($average[$metric] * 2, $minRate);
                if ($p[$metric] >= $minCount && $rate > $threshold) {
                    $flags[] = ['type' => $metric, 'severity' => $rate > $threshold * 2 ? 'critical' : 'warning',
                        'message' => sprintf('%s: %d dari %d transaksi (%.0f%%, rata-rata toko %.0f%%)', $label, $p[$metric], $p['transactions'], $rate * 100, $average[$metric] * 100)];
                }
            }
            if ($p['cash_short'] > 0) {
                $flags[] = ['type' => 'cash_short', 'severity' => $p['cash_short'] >= self::CASH_SHORT_CRITICAL ? 'critical' : 'warning',
                    'message' => 'Kas kurang total Rp ' . number_format($p['cash_short'], 0, ',', '.') . ' saat tutup kas'];
            }
            $activeDays = max(1, count($p['active_days']));
            if ($p['no_sales'] >= self::NO_SALE_PER_DAY && $p['no_sales'] / $activeDays >= self::NO_SALE_PER_DAY) {
                $flags[] = ['type' => 'no_sales', 'severity' => $p['no_sales'] / $activeDays >= self::NO_SALE_PER_DAY * 2 ? 'critical' : 'warning',
                    'message' => sprintf('Laci dibuka tanpa transaksi %d kali (%.1f per hari)', $p['no_sales'], $p['no_sales'] / $activeDays)];
            }
            if ($p['method_changes'] > 0) {
                $flags[] = ['type' => 'method_changes', 'severity' => $p['method_changes'] >= 3 ? 'critical' : 'warning',
                    'message' => "Mengganti metode bayar {$p['method_changes']} kali"];
            }
            if ($p['pin_failed'] >= 3) {
                $flags[] = ['type' => 'pin_failed', 'severity' => 'warning', 'message' => "PIN pemilik salah {$p['pin_failed']} kali"];
            }
            $days = max(1, count($p['active_days']));
            unset($p['active_days']);
            $cashiers[] = $p + [
                'active_days' => $days,
                'per_day' => ['transactions' => round($p['transactions'] / $days, 1), 'voids' => round($p['voids'] / $days, 1), 'reprints' => round($p['reprints'] / $days, 1), 'no_sales' => round($p['no_sales'] / $days, 1)],
                'rates' => array_map(fn ($m) => round($p[$m] / $tx, 4), array_combine(array_keys(self::RULES), array_keys(self::RULES))),
                'flags' => $flags,
            ];
        }
        usort($cashiers, fn ($a, $b) => [count($b['flags']), $b['voids'] + $b['refunds']] <=> [count($a['flags']), $a['voids'] + $a['refunds']]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1,
            'average' => $average,
            'cashiers' => $cashiers,
        ];
    }
}
