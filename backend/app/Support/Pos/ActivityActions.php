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
    public const ITEM_QTY_CHANGED = 'ITEM_QTY_CHANGED';
    public const ITEM_REMOVED = 'ITEM_REMOVED';
    public const DISCOUNT_APPLIED = 'DISCOUNT_APPLIED';
    public const DISCOUNT_REMOVED = 'DISCOUNT_REMOVED';
    public const PAYMENT_METHOD_CHANGED = 'PAYMENT_METHOD_CHANGED';
    public const DRAWER_NO_SALE = 'DRAWER_NO_SALE';
    public const ORDER_SPLIT = 'ORDER_SPLIT';
    public const TABLE_TRANSFERRED = 'TABLE_TRANSFERRED';
    public const BILL_MOVED = 'BILL_MOVED';
    public const BILL_MERGED = 'BILL_MERGED';

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
        self::ITEM_QTY_CHANGED => ['Jumlah item diubah', 'info'],
        self::ITEM_REMOVED => ['Item dihapus dari pesanan', 'warning'],
        self::DISCOUNT_APPLIED => ['Diskon manual diberikan', 'critical'],
        self::DISCOUNT_REMOVED => ['Diskon manual dihapus', 'info'],
        self::PAYMENT_METHOD_CHANGED => ['Metode bayar diganti', 'critical'],
        self::DRAWER_NO_SALE => ['Laci dibuka tanpa transaksi', 'warning'],
        self::ORDER_SPLIT => ['Bill dipisah', 'info'],
        self::TABLE_TRANSFERRED => ['Pesanan pindah meja', 'info'],
        self::BILL_MOVED => ['Tagihan pindah meja', 'warning'],
        self::BILL_MERGED => ['Tagihan meja digabung', 'warning'],
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
