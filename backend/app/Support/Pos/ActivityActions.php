<?php

namespace App\Support\Pos;

/**
 * Actions written to the POS audit trail (pos_activity_logs). key => [label, default severity].
 * Add a key here (and in frontend ACTION_LABELS, PosAudit.tsx) for every new logged action.
 */
final class ActivityActions
{
    public const ORDER_VOID = 'ORDER_VOID';
    public const ORDER_REFUND = 'ORDER_REFUND';
    public const PAYMENT_RECORDED = 'PAYMENT_RECORDED';
    public const TABLE_BILL_PAID = 'TABLE_BILL_PAID';
    public const RECEIPT_PRINTED = 'RECEIPT_PRINTED';
    public const RECEIPT_REPRINTED = 'RECEIPT_REPRINTED';
    public const CASH_OPENED = 'CASH_OPENED';
    public const CASH_CLOSED = 'CASH_CLOSED';
    public const PRODUCT_CREATED = 'PRODUCT_CREATED';
    public const PRODUCT_UPDATED = 'PRODUCT_UPDATED';
    public const PRODUCT_DELETED = 'PRODUCT_DELETED';
    public const STAFF_ACCESS_CHANGED = 'STAFF_ACCESS_CHANGED';
    public const PIN_SET = 'PIN_SET';
    public const PIN_CHANGED = 'PIN_CHANGED';
    public const PIN_RESET_REQUESTED = 'PIN_RESET_REQUESTED';
    public const PIN_RESET = 'PIN_RESET';
    public const PIN_FAILED = 'PIN_FAILED';
    public const PIN_LOCKED = 'PIN_LOCKED';
    public const AUDIT_UNLOCKED = 'AUDIT_UNLOCKED';

    public const CATALOG = [
        self::ORDER_VOID => ['Pesanan dibatalkan (void)', 'critical'],
        self::ORDER_REFUND => ['Refund pesanan', 'critical'],
        self::PAYMENT_RECORDED => ['Pembayaran diterima', 'info'],
        self::TABLE_BILL_PAID => ['Tagihan meja dibayar', 'info'],
        self::RECEIPT_PRINTED => ['Struk dicetak', 'info'],
        self::RECEIPT_REPRINTED => ['Struk dicetak ulang', 'warning'],
        self::CASH_OPENED => ['Kas dibuka', 'info'],
        self::CASH_CLOSED => ['Kas ditutup', 'info'],
        self::PRODUCT_CREATED => ['Produk ditambahkan', 'info'],
        self::PRODUCT_UPDATED => ['Produk diubah', 'info'],
        self::PRODUCT_DELETED => ['Produk dihapus', 'warning'],
        self::STAFF_ACCESS_CHANGED => ['Hak akses staf diubah', 'warning'],
        self::PIN_SET => ['PIN pemilik dibuat', 'info'],
        self::PIN_CHANGED => ['PIN pemilik diganti', 'warning'],
        self::PIN_RESET_REQUESTED => ['Reset PIN diminta', 'warning'],
        self::PIN_RESET => ['PIN pemilik direset lewat email', 'warning'],
        self::PIN_FAILED => ['PIN salah', 'warning'],
        self::PIN_LOCKED => ['PIN terkunci (terlalu banyak salah)', 'critical'],
        self::AUDIT_UNLOCKED => ['Log audit dibuka', 'info'],
    ];

    public static function label(string $action): string
    {
        return self::CATALOG[$action][0] ?? $action;
    }

    public static function severity(string $action): string
    {
        return self::CATALOG[$action][1] ?? 'info';
    }
}
