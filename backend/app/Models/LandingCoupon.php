<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Discount code of one seller. used_count is reserved at checkout and released on expiry/failure. */
class LandingCoupon extends Model
{
    use SoftDeletes;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'organization_id', 'code', 'type', 'value', 'max_discount', 'min_purchase', 'max_uses', 'product_ids',
        'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_discount' => 'integer',
            'min_purchase' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'product_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($code)) ?? '');
    }

    /** Why the coupon cannot be used, or null when it can. */
    public function unusableReason(int $productId, int $subtotal): ?string
    {
        if (!$this->is_active || $this->trashed()) {
            return 'Kode kupon tidak aktif.';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Kupon belum bisa dipakai.';
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'Kupon sudah berakhir.';
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Kuota kupon sudah habis.';
        }
        if (is_array($this->product_ids) && $this->product_ids !== [] && !in_array($productId, array_map('intval', $this->product_ids), true)) {
            return 'Kupon tidak berlaku untuk produk ini.';
        }
        if ($subtotal < (int) $this->min_purchase) {
            return 'Minimal belanja Rp ' . number_format((int) $this->min_purchase, 0, ',', '.') . ' untuk kupon ini.';
        }

        return null;
    }

    /** Discount in rupiah for a subtotal (never more than the subtotal). */
    public function discountFor(int $subtotal): int
    {
        $discount = $this->type === self::TYPE_PERCENT
            ? intdiv($subtotal * max(0, min(100, (int) $this->value)), 100)
            : (int) $this->value;
        if ($this->type === self::TYPE_PERCENT && $this->max_discount) {
            $discount = min($discount, (int) $this->max_discount);
        }

        return max(0, min($subtotal, $discount));
    }
}
