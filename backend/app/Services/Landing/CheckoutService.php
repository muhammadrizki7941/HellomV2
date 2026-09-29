<?php

namespace App\Services\Landing;

use App\Models\LandingCoupon;
use App\Models\LandingOrderItem;
use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\Organization;
use App\Services\SellerFinance\FeeCalculator;
use App\Services\SellerFinance\FinanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Buyer checkout for landing products (no login). Prices always come from the database.
 *
 * Stock and coupon quota are reserved when the order is created (row locks) and given
 * back when it expires or fails; a late verified payment takes them again.
 */
final class CheckoutService
{
    public const MIN_TOTAL = 10000; // smallest amount the gateways accept

    public function __construct(
        private readonly FeeCalculator $fees,
        private readonly FinanceSettings $settings,
    ) {
    }

    /**
     * Price breakdown for the checkout page (no reservation).
     *
     * @return array{subtotal:int, discount:int, shipping:int, total:int, coupon: ?array, coupon_error: ?string}
     */
    public function quote(LandingProduct $product, int $quantity, ?string $couponCode): array
    {
        $quantity = $this->clampQuantity($product, $quantity);
        $subtotal = (int) $product->price * $quantity;
        $shipping = $this->shippingFor($product);
        $coupon = null;
        $couponError = null;
        $discount = 0;

        $code = LandingCoupon::normalizeCode((string) $couponCode);
        if ($code !== '') {
            $found = LandingCoupon::query()->where('organization_id', $product->organization_id)->where('code', $code)->first();
            $couponError = $found ? $found->unusableReason((int) $product->id, $subtotal) : 'Kode kupon tidak ditemukan.';
            if ($found && $couponError === null) {
                $discount = $found->discountFor($subtotal);
                $coupon = ['code' => $found->code, 'type' => $found->type, 'value' => (int) $found->value];
            }
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'total' => $subtotal - $discount + $shipping,
            'coupon' => $coupon,
            'coupon_error' => $couponError,
        ];
    }

    /** @return array<string, mixed> */
    public function rules(LandingProduct $product): array
    {
        $phoneRequired = $product->require_phone || $product->type === LandingProduct::TYPE_PHYSICAL;
        $rules = [
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'payment_method' => ['nullable', 'in:qris,other'],
            'buyer_name' => ['required', 'string', 'min:2', 'max:150'],
            'buyer_email' => ['required', 'email:rfc', 'max:150'],
            'buyer_phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s-]{8,20}$/'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:2000'],
            // Ad attribution captured on the public page (UTM, click ids, referrer).
            'attribution' => ['nullable', 'array'],
            'attribution.*' => ['nullable', 'max:200'],
        ];
        if ($product->type === LandingProduct::TYPE_PHYSICAL) {
            $rules += [
                'shipping.recipient_name' => ['required', 'string', 'max:150'],
                'shipping.phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s-]{8,20}$/'],
                'shipping.address' => ['required', 'string', 'min:10', 'max:500'],
                'shipping.city' => ['required', 'string', 'max:100'],
                'shipping.province' => ['nullable', 'string', 'max:100'],
                'shipping.postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
                'shipping.notes' => ['nullable', 'string', 'max:300'],
            ];
        }

        return $rules;
    }

    /** Create the pending order and reserve stock/coupon. Throws ValidationException (422). */
    public function createOrder(LandingProduct $product, array $input): LandingPageOrder
    {
        $organization = Organization::query()->find($product->organization_id);
        if (!$organization || $organization->landing_suspended_at !== null) {
            throw ValidationException::withMessages(['product' => 'Toko ini sedang tidak menerima pesanan.']);
        }
        $customFields = $this->customFieldValues($product, (array) ($input['fields'] ?? []));
        $quantity = $this->clampQuantity($product, (int) ($input['quantity'] ?? 1));
        $expiryHours = max(1, (int) $this->settings->get($product->type === LandingProduct::TYPE_PHYSICAL ? 'physical_order_expiry_hours' : 'order_expiry_hours'));

        return DB::transaction(function () use ($product, $input, $quantity, $customFields, $expiryHours): LandingPageOrder {
            $locked = LandingProduct::query()->lockForUpdate()->find($product->id);
            if (!$locked || !$locked->isPurchasable($quantity)) {
                throw ValidationException::withMessages(['product' => $locked && $locked->stock !== null && $locked->stock < $quantity
                    ? ($locked->stock > 0 ? 'Stok tinggal ' . $locked->stock . '.' : 'Stok habis.')
                    : 'Produk ini sedang tidak dijual.']);
            }

            $subtotal = (int) $locked->price * $quantity;
            $discount = 0;
            $coupon = null;
            $code = LandingCoupon::normalizeCode((string) ($input['coupon_code'] ?? ''));
            if ($code !== '') {
                $coupon = LandingCoupon::query()->where('organization_id', $locked->organization_id)->where('code', $code)->lockForUpdate()->first();
                $reason = $coupon ? $coupon->unusableReason((int) $locked->id, $subtotal) : 'Kode kupon tidak ditemukan.';
                if ($reason !== null) {
                    throw ValidationException::withMessages(['coupon_code' => $reason]);
                }
                $discount = $coupon->discountFor($subtotal);
            }
            $shipping = $this->shippingFor($locked);
            $total = $subtotal - $discount + $shipping;
            if ($total < self::MIN_TOTAL) {
                throw ValidationException::withMessages(['coupon_code' => 'Total bayar minimal Rp ' . number_format(self::MIN_TOTAL, 0, ',', '.') . '.']);
            }

            // Reserve.
            if ($locked->stock !== null) {
                $locked->decrement('stock', $quantity);
            }
            $coupon?->increment('used_count');

            $estimate = $this->fees->split($total, null);
            $attribution = $this->attribution($input['attribution'] ?? null);
            $productName = Str::limit((string) $locked->name, 200, '');
            $order = LandingPageOrder::query()->create([
                'organization_id' => (int) $locked->organization_id,
                'product_id' => (int) $locked->id,
                'product_kind' => (string) $locked->type,
                'product_name' => $productName,
                'quantity' => $quantity,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'shipping_amount' => $shipping,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'amount' => $total,
                'commission_amount' => $estimate['platform_fee'],
                'net_amount' => $estimate['seller_net'],
                'buyer_name' => trim((string) $input['buyer_name']),
                'buyer_email' => strtolower(trim((string) $input['buyer_email'])),
                'buyer_phone' => isset($input['buyer_phone']) ? trim((string) $input['buyer_phone']) ?: null : null,
                'shipping_address' => $locked->type === LandingProduct::TYPE_PHYSICAL ? array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) ($input['shipping'] ?? [])) : null,
                'custom_fields' => $customFields ?: null,
                'access_max_opens' => $locked->access_max_opens,
                'access_days' => $locked->access_days,
                'download_limit' => $locked->download_limit,
                'status' => LandingPageOrder::STATUS_PENDING,
                'reference_id' => 'lps_' . Str::upper(Str::random(18)),
                'expires_at' => now()->addHours($expiryHours),
                'metadata' => ['fee_estimate' => $estimate],
                'attribution' => $attribution,
                'source' => LandingStats::sourceLabel($attribution['utm_source'] ?? $attribution['referrer'] ?? ''),
            ]);
            $order->forceFill(['inventory_reserved_at' => now()])->save();

            LandingOrderItem::query()->create([
                'order_id' => $order->id,
                'product_kind' => (string) $locked->type,
                'product_name' => $productName,
                'unit_price' => (int) $locked->price,
                'qty' => $quantity,
                'line_total' => $subtotal,
                // Public fields only: delivery link/note/file never go into the snapshot.
                'snapshot' => $locked->publicPayload() + ['product_db_id' => $locked->id],
            ]);

            return $order;
        }, 3);
    }

    /** Give stock and coupon quota back (order expired/failed). Call inside the order's transaction. */
    public function releaseInventory(LandingPageOrder $order): void
    {
        if (!$order->product_id || !$order->inventory_reserved_at || $order->inventory_released_at) {
            return;
        }
        $product = LandingProduct::withTrashed()->lockForUpdate()->find($order->product_id);
        if ($product && $product->stock !== null) {
            $product->increment('stock', max(1, (int) $order->quantity));
        }
        if ($order->coupon_id) {
            LandingCoupon::withTrashed()->whereKey($order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }
        $order->forceFill(['inventory_released_at' => now()])->save();
    }

    /** A verified payment arrived: count the sale; take stock again if it had been released. Inside the transaction. */
    public function commitSale(LandingPageOrder $order): void
    {
        if (!$order->product_id) {
            return;
        }
        $product = LandingProduct::withTrashed()->lockForUpdate()->find($order->product_id);
        if (!$product) {
            return;
        }
        $quantity = max(1, (int) $order->quantity);
        if ($order->inventory_released_at) {
            if ($product->stock !== null) {
                if ($product->stock < $quantity) {
                    // Paid after expiry while the last items were sold: keep the payment, tell the seller.
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $meta['oversold'] = true;
                    $order->forceFill(['metadata' => $meta]);
                    Log::warning('Landing order paid after its stock was sold', ['order' => $order->id]);
                }
                $product->stock = max(0, $product->stock - $quantity);
            }
            if ($order->coupon_id) {
                LandingCoupon::withTrashed()->whereKey($order->coupon_id)->increment('used_count');
            }
            $order->forceFill(['inventory_released_at' => null]);
        }
        $product->sold_count = (int) $product->sold_count + $quantity;
        $product->save();
    }

    /** Known attribution keys only, short strings. */
    private function attribution(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid', 'referrer', 'landing'];
        $out = [];
        foreach ($keys as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && trim((string) $input[$key]) !== '') {
                $out[$key] = Str::limit(trim((string) $input[$key]), 150, '');
            }
        }

        return $out ?: null;
    }

    private function clampQuantity(LandingProduct $product, int $quantity): int
    {
        return $product->type === LandingProduct::TYPE_PHYSICAL ? max(1, min(20, $quantity)) : 1;
    }

    private function shippingFor(LandingProduct $product): int
    {
        return $product->type === LandingProduct::TYPE_PHYSICAL && $product->shipping_mode === 'flat' ? (int) $product->shipping_fee : 0;
    }

    /** @return list<array{label:string, value:string}> */
    private function customFieldValues(LandingProduct $product, array $values): array
    {
        $out = [];
        $errors = [];
        foreach ($product->checkoutFields() as $field) {
            $value = trim((string) ($values[$field['id']] ?? ''));
            if ($value === '' && !empty($field['required'])) {
                $errors['fields.' . $field['id']] = $field['label'] . ' wajib diisi.';
                continue;
            }
            if ($value !== '' && $field['type'] === 'number' && !is_numeric($value)) {
                $errors['fields.' . $field['id']] = $field['label'] . ' harus angka.';
                continue;
            }
            if ($value !== '' && $field['type'] === 'select' && !in_array($value, $field['options'] ?? [], true)) {
                $errors['fields.' . $field['id']] = 'Pilih salah satu opsi ' . $field['label'] . '.';
                continue;
            }
            if ($value !== '') {
                $out[] = ['label' => (string) $field['label'], 'value' => Str::limit($value, 2000, '')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
