<?php

namespace App\Models;

use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A product sold on a seller's Hellom Page. Prices are BIGINT rupiah.
 *
 * Delivery data (delivery_url, delivery_note, file_path) is secret until an order is paid:
 * it is stored encrypted / on the private disk and never part of publicPayload().
 */
class LandingProduct extends Model
{
    use SoftDeletes;

    public const TYPE_DRIVE = 'drive';
    public const TYPE_FILE = 'file';
    public const TYPE_LINK = 'link';
    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_SERVICE = 'service';

    public const TYPES = [self::TYPE_DRIVE, self::TYPE_FILE, self::TYPE_LINK, self::TYPE_PHYSICAL, self::TYPE_SERVICE];
    public const DIGITAL_TYPES = [self::TYPE_DRIVE, self::TYPE_FILE, self::TYPE_LINK];

    public const TYPE_LABELS = [
        self::TYPE_DRIVE => 'Digital (Google Drive)',
        self::TYPE_FILE => 'Digital (upload file)',
        self::TYPE_LINK => 'Link / akses',
        self::TYPE_PHYSICAL => 'Produk fisik',
        self::TYPE_SERVICE => 'Jasa / booking',
    ];

    protected $fillable = [
        'organization_id', 'public_id', 'type', 'name', 'description', 'image_path', 'price', 'compare_at_price',
        'stock', 'is_active', 'require_phone', 'checkout_fields', 'delivery_mode', 'delivery_url', 'delivery_note',
        'access_max_opens', 'access_days', 'download_limit', 'shipping_mode', 'shipping_fee', 'weight_grams', 'sort_order',
    ];

    protected $hidden = ['delivery_url', 'delivery_note', 'file_path'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'stock' => 'integer',
            'sold_count' => 'integer',
            'is_active' => 'boolean',
            'require_phone' => 'boolean',
            'checkout_fields' => 'array',
            'delivery_url' => 'encrypted',
            'delivery_note' => 'encrypted',
            'file_size' => 'integer',
            'access_max_opens' => 'integer',
            'access_days' => 'integer',
            'download_limit' => 'integer',
            'shipping_fee' => 'integer',
            'weight_grams' => 'integer',
            'sort_order' => 'integer',
            'admin_disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LandingProduct $product): void {
            $product->public_id ??= Str::lower(Str::random(12));
        });
        static::saving(function (LandingProduct $product): void {
            if (is_string($product->description) && $product->isDirty('description')) {
                $product->description = SafeHtml::clean($product->description);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isDigital(): bool
    {
        return in_array($this->type, self::DIGITAL_TYPES, true);
    }

    /** Can be bought right now (seller + super admin switches, stock, something to deliver). */
    public function isPurchasable(int $quantity = 1): bool
    {
        return $this->is_active && $this->admin_disabled_at === null && !$this->trashed()
            && ($this->stock === null || $this->stock >= $quantity) && $this->isDeliverable();
    }

    /** Digital products need their link or file before they can be sold. */
    public function isDeliverable(): bool
    {
        return match ($this->type) {
            self::TYPE_DRIVE, self::TYPE_LINK => (string) $this->delivery_url !== '',
            self::TYPE_FILE => (string) $this->file_path !== '',
            default => true,
        };
    }

    public function imageUrl(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        return '/' . trim((string) config('filesystems.disks.public.url', '/media'), '/') . '/' . ltrim($this->image_path, '/');
    }

    /** Buyer-facing checkout fields; a service always asks what the buyer needs. */
    public function checkoutFields(): array
    {
        $fields = is_array($this->checkout_fields) ? array_values($this->checkout_fields) : [];
        if ($this->type === self::TYPE_SERVICE && $fields === []) {
            $fields[] = ['id' => 'kebutuhan', 'label' => 'Ceritakan kebutuhan kamu', 'type' => 'textarea', 'required' => true, 'options' => []];
        }

        return $fields;
    }

    /**
     * Everything an anonymous visitor may see. No delivery link, note or file path.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'type_label' => self::TYPE_LABELS[$this->type] ?? $this->type,
            'name' => $this->name,
            'description' => $this->description ? SafeHtml::clean($this->description) : null,
            'image_url' => $this->imageUrl(),
            'price' => (int) $this->price,
            'compare_at_price' => $this->compare_at_price && $this->compare_at_price > $this->price ? (int) $this->compare_at_price : null,
            'in_stock' => $this->stock === null || $this->stock > 0,
            'stock_left' => $this->stock !== null && $this->stock <= 10 ? max(0, (int) $this->stock) : null,
            'available' => $this->isPurchasable(),
            'require_phone' => (bool) $this->require_phone,
            'checkout_fields' => $this->checkoutFields(),
            'shipping' => $this->type === self::TYPE_PHYSICAL ? [
                'mode' => $this->shipping_mode ?: 'free',
                'fee' => $this->shipping_mode === 'flat' ? (int) $this->shipping_fee : 0,
            ] : null,
            'max_quantity' => $this->type === self::TYPE_PHYSICAL ? max(1, min(20, $this->stock ?? 20)) : 1,
            'file' => $this->type === self::TYPE_FILE && $this->file_name ? [
                'extension' => strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION)),
                'size' => (int) $this->file_size,
            ] : null,
        ];
    }
}
