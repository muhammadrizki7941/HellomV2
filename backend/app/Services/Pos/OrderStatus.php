<?php

namespace App\Services\Pos;

use App\Models\Order;

/**
 * Kitchen/service status of an order. Stored codes stay as they are in the database;
 * the business names are the Indonesian labels below.
 *
 *   new (menunggu_konfirmasi) → accepted (dikonfirmasi) → preparing (diproses)
 *     → prepared (siap) → completed (selesai);   any non-final → cancelled (dibatalkan)
 *
 * Only forward moves are allowed (steps may be skipped, e.g. a counter order can go
 * from dikonfirmasi straight to selesai). Nothing leaves selesai or dibatalkan.
 * Payment is tracked separately in payment_status and never changes this status.
 */
final class OrderStatus
{
    public const FLOW = [
        Order::STATUS_NEW,
        Order::STATUS_ACCEPTED,
        Order::STATUS_PREPARING,
        Order::STATUS_PREPARED,
        Order::STATUS_COMPLETED,
    ];

    public const CODES = [
        Order::STATUS_NEW => 'menunggu_konfirmasi',
        Order::STATUS_ACCEPTED => 'dikonfirmasi',
        Order::STATUS_PREPARING => 'diproses',
        Order::STATUS_PREPARED => 'siap',
        Order::STATUS_COMPLETED => 'selesai',
        Order::STATUS_CANCELLED => 'dibatalkan',
    ];

    public const LABELS = [
        Order::STATUS_NEW => 'Menunggu konfirmasi',
        Order::STATUS_ACCEPTED => 'Dikonfirmasi',
        Order::STATUS_PREPARING => 'Diproses',
        Order::STATUS_PREPARED => 'Siap',
        Order::STATUS_COMPLETED => 'Selesai',
        Order::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Accepts the stored code or the Indonesian code ("dikonfirmasi"). */
    public static function fromInput(string $value): ?string
    {
        if (array_key_exists($value, self::CODES)) {
            return $value;
        }
        $stored = array_search($value, self::CODES, true);

        return $stored === false ? null : (string) $stored;
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to || self::isFinal($from)) {
            return false;
        }
        if ($to === Order::STATUS_CANCELLED) {
            return true;
        }
        $fromRank = array_search($from, self::FLOW, true);
        $toRank = array_search($to, self::FLOW, true);

        return $fromRank !== false && $toRank !== false && $toRank > $fromRank;
    }

    /** @return list<string> */
    public static function allowedNext(string $from): array
    {
        return array_values(array_filter(
            [...self::FLOW, Order::STATUS_CANCELLED],
            fn (string $to) => self::canTransition($from, $to)
        ));
    }

    /** @return array{code:string, label:string, allowed_next:list<string>} */
    public static function describe(string $status): array
    {
        return [
            'code' => self::CODES[$status] ?? $status,
            'label' => self::LABELS[$status] ?? $status,
            'allowed_next' => self::allowedNext($status),
        ];
    }
}
