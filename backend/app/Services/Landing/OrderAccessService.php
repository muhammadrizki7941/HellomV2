<?php

namespace App\Services\Landing;

use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The buyer's access page (/akses/{token}, token = landing_page_orders.download_token,
 * created when the payment is booked). The Drive/link URL is read from the product at the
 * moment of opening, so a seller who replaces the link reaches every earlier buyer.
 * Limits (opens, days, downloads) are copied onto the order at checkout.
 */
final class OrderAccessService
{
    public const DOWNLOAD_URL_MINUTES = 10;

    public function find(string $token): ?LandingPageOrder
    {
        if (strlen($token) < 32 || strlen($token) > 64) {
            return null;
        }

        return LandingPageOrder::query()
            ->where('download_token', $token)
            ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])
            ->first();
    }

    /** @return array<string, mixed> */
    public function payload(LandingPageOrder $order): array
    {
        $product = $order->product_id ? $order->product : null;
        $organization = Organization::query()->find($order->organization_id);
        $kind = (string) $order->product_kind;
        $expiresAt = $order->accessExpiresAt();

        return [
            'reference' => $order->reference_id,
            'status' => $order->status,
            'status_label' => LandingPageOrder::LABELS[$order->status] ?? $order->status,
            'product_name' => $order->product_name,
            'product_type' => $kind,
            'product_image_url' => $product?->imageUrl(),
            'quantity' => (int) $order->quantity,
            'amount' => (int) $order->amount,
            'buyer_name' => $order->buyer_name,
            'paid_at' => optional($order->paid_at)->toIso8601String(),
            'seller' => [
                'name' => $organization?->name,
                'slug' => $organization?->slug,
                'phone' => $organization?->phone,
            ],
            'delivery_note' => $product?->delivery_note,
            'access' => $this->isDigital($order) ? [
                'kind' => $kind === LandingProduct::TYPE_FILE ? 'download' : 'link',
                'expires_at' => $expiresAt?->toIso8601String(),
                'expired' => $expiresAt !== null && $expiresAt->isPast(),
                'opens_used' => (int) $order->access_open_count,
                'opens_max' => $order->access_max_opens,
                'downloads_used' => (int) $order->download_count,
                'downloads_max' => $order->download_limit,
                'file_name' => $kind === LandingProduct::TYPE_FILE ? $product?->file_name : null,
                'file_size' => $kind === LandingProduct::TYPE_FILE ? $product?->file_size : null,
                'available' => $this->blockReason($order) === null,
                'blocked_reason' => $this->blockReason($order),
            ] : null,
            'shipping' => $kind === LandingProduct::TYPE_PHYSICAL ? [
                'address' => $order->shipping_address,
                'shipped_at' => optional($order->shipped_at)->toIso8601String(),
                'courier' => $order->shipping_courier,
                'tracking_number' => $order->tracking_number,
            ] : null,
            'custom_fields' => $order->custom_fields ?? [],
        ];
    }

    /**
     * Count one opening and return where to go: the live Drive/link URL, or a short-lived
     * signed download URL for file products.
     */
    public function open(LandingPageOrder $order): string
    {
        if ($reason = $this->blockReason($order)) {
            throw ValidationException::withMessages(['access' => $reason]);
        }
        $product = $order->product_id ? $order->product : null;

        if ((string) $order->product_kind === LandingProduct::TYPE_FILE) {
            if (!$product || !$product->file_path) {
                throw ValidationException::withMessages(['access' => 'File belum tersedia. Hubungi penjual.']);
            }

            return URL::temporarySignedRoute('api.v1.hellom.public.landing.access.download', now()->addMinutes(self::DOWNLOAD_URL_MINUTES), ['token' => $order->download_token]);
        }

        $target = $product ? (string) $product->delivery_url : (string) $order->file_url; // legacy block orders kept the link on the order
        if ($target === '') {
            throw ValidationException::withMessages(['access' => 'Link produk belum tersedia. Hubungi penjual.']);
        }

        // Atomic: two tabs cannot both use the last allowed opening.
        $updated = DB::table('landing_page_orders')
            ->where('id', $order->id)
            ->when($order->access_max_opens !== null, fn ($q) => $q->where('access_open_count', '<', (int) $order->access_max_opens))
            ->update(['access_open_count' => DB::raw('access_open_count + 1'), 'access_last_opened_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['access' => 'Batas buka akses sudah habis. Hubungi penjual kalau butuh bantuan.']);
        }

        return $target;
    }

    /** Count a download (signed URL already checked by the route). Returns [path, name] on the private disk. */
    public function takeDownload(LandingPageOrder $order): array
    {
        if ($reason = $this->blockReason($order)) {
            throw ValidationException::withMessages(['access' => $reason]);
        }
        $product = $order->product;
        if (!$product || !$product->file_path) {
            throw ValidationException::withMessages(['access' => 'File belum tersedia. Hubungi penjual.']);
        }
        $updated = DB::table('landing_page_orders')
            ->where('id', $order->id)
            ->when($order->download_limit !== null, fn ($q) => $q->where('download_count', '<', (int) $order->download_limit))
            ->update(['download_count' => DB::raw('download_count + 1'), 'access_last_opened_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['access' => 'Batas unduhan sudah habis. Hubungi penjual kalau butuh bantuan.']);
        }

        return [(string) $product->file_path, (string) ($product->file_name ?: 'produk')];
    }

    public function blockReason(LandingPageOrder $order): ?string
    {
        if (!$order->isPaid()) {
            return 'Pesanan belum lunas.';
        }
        if (!$this->isDigital($order)) {
            return null;
        }
        $expiresAt = $order->accessExpiresAt();
        if ($expiresAt !== null && $expiresAt->isPast()) {
            return 'Masa akses produk sudah berakhir.';
        }
        if ((string) $order->product_kind === LandingProduct::TYPE_FILE) {
            return $order->download_limit !== null && $order->download_count >= $order->download_limit
                ? 'Batas unduhan sudah habis. Hubungi penjual kalau butuh bantuan.' : null;
        }

        return $order->access_max_opens !== null && $order->access_open_count >= $order->access_max_opens
            ? 'Batas buka akses sudah habis. Hubungi penjual kalau butuh bantuan.' : null;
    }

    /** At most 3 buyer emails per order per 10 minutes (access page, "cek pesanan", seller dashboard). */
    public function allowResend(LandingPageOrder $order): bool
    {
        $key = 'landing-resend:' . $order->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return false;
        }
        RateLimiter::hit($key, 600);
        $order->increment('email_resend_count');

        return true;
    }

    public function isDigital(LandingPageOrder $order): bool
    {
        $kind = (string) $order->product_kind;

        return in_array($kind, LandingProduct::DIGITAL_TYPES, true) || ($order->product_id === null && (string) $order->file_url !== '');
    }
}
