<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use SoftDeletes;
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'member_id',
        'order_number',
        'dining_table_id',
        'table_label',
        'user_id',
        'customer_name',
        'customer_phone',
        'service_type',
        'order_source',
        'status',
        'total_amount',
        'points_earned',
        'points_redeemed',
        'discount_amount',
        'final_amount',
        'redeemed_points',
        'payment_method',
        'payment_status',
        'payment_amount',
        'payment_change',
        'payment_note',
        'paid_at',
        'payment_ref',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'integer',
        'points_earned' => 'integer',
        'points_redeemed' => 'integer',
        'discount_amount' => 'integer',
        'final_amount' => 'integer',
        'redeemed_points' => 'integer',
        'payment_amount' => 'integer',
        'payment_change' => 'integer',
        'payment_meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function diningTable(): BelongsTo
    {
        return $this->table();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(PosMember::class, 'member_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    protected static function boot()
    {
        parent::boot();

        // Global scope — filter by pos_tenant_slug
        static::addGlobalScope('tenant', function ($builder) {
            $user = auth()->user();
            if ($user && $user->currentOrganization) {
                $tenantSlug = $user->currentOrganization->pos_tenant_slug
                    ?? $user->currentOrganization->slug;
                $builder->where('tenant_id', $tenantSlug);
            }
        });

        // Auto-set tenant_id saat create
        static::creating(function ($order) {
            if (!$order->tenant_id) {
                $user = auth()->user();
                if ($user && $user->currentOrganization) {
                    $order->tenant_id = $user->currentOrganization->pos_tenant_slug
                        ?? $user->currentOrganization->slug;
                }
            }
            if (!$order->order_number) {
                $order->order_number = static::generateNextOrderNumber();
            }
        });

        static::deleting(function ($order) {
            // Cascade delete order items and their options
            $order->items()->each(function ($item) {
                $item->options()->delete();
            });
            $order->items()->delete();
        });
    }

    public static function generateNextOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $latest = static::withoutGlobalScope('tenant')
            ->where('order_number', 'like', "ORD-{$date}-%")
            ->orderBy('order_number', 'desc')
            ->first();

        $next = $latest ? (int) substr((string) $latest->order_number, -3) + 1 : 1;

        do {
            $candidate = sprintf('ORD-%s-%03d', $date, $next);
            $exists = static::withoutGlobalScope('tenant')
                ->where('order_number', $candidate)
                ->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }
}
