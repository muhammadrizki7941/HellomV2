<?php

namespace App\Jobs;

use App\Models\LandingPageOrder;
use App\Models\LandingProduct;
use App\Models\LandingTrackingSetting;
use App\Services\Landing\LandingShop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side Meta Conversions API "Purchase" for a paid Hellom Page order, when the seller
 * set a pixel id + CAPI token. event_id = purchase_{reference}, the same id the browser pixel
 * uses on the thank-you page, so Meta counts the purchase once. Personal data is sent
 * SHA-256 hashed only (Meta requirement).
 */
class SendMetaPurchaseEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(): void
    {
        $order = LandingPageOrder::query()->find($this->orderId);
        if (!$order || !$order->isPaid() || $order->capi_sent_at) {
            return;
        }
        $settings = LandingTrackingSetting::query()->find($order->organization_id);
        $pixel = $settings?->publicIds()['meta_pixel_id'] ?? null;
        $token = (string) ($settings?->meta_capi_token ?? '');
        if (!$pixel || $token === '') {
            return;
        }

        $hash = fn (?string $v) => $v ? hash('sha256', $v) : null;
        $phone = preg_replace('/\D/', '', (string) $order->buyer_phone);
        $phone = $phone !== '' && str_starts_with($phone, '0') ? '62' . substr($phone, 1) : $phone;
        $attribution = is_array($order->attribution) ? $order->attribution : [];
        $organization = $order->organization;
        $event = [
            'event_name' => 'Purchase',
            'event_time' => optional($order->paid_at)->timestamp ?? time(),
            'event_id' => 'purchase_' . $order->reference_id,
            'action_source' => 'website',
            'event_source_url' => $organization ? app(LandingShop::class)->publicUrl($organization) : null,
            'user_data' => array_filter([
                'em' => [$hash(strtolower(trim((string) $order->buyer_email)))],
                'ph' => $phone !== '' ? [$hash($phone)] : null,
                'fn' => $order->buyer_name ? [$hash(strtolower(trim(explode(' ', (string) $order->buyer_name)[0])))] : null,
                'fbc' => !empty($attribution['fbclid']) ? 'fb.1.' . ((optional($order->created_at)->timestamp ?? time()) * 1000) . '.' . $attribution['fbclid'] : null,
                'external_id' => [$hash((string) $order->buyer_email)],
            ]),
            'custom_data' => [
                'value' => (int) $order->amount,
                'currency' => 'IDR',
                'content_type' => 'product',
                'content_ids' => array_values(array_filter([LandingProduct::withTrashed()->whereKey($order->product_id)->value('public_id')])),
                'content_name' => (string) $order->product_name,
                'num_items' => (int) $order->quantity,
            ],
        ];
        $body = ['data' => [$event]];
        if ($settings->meta_test_event_code) {
            $body['test_event_code'] = $settings->meta_test_event_code;
        }

        $response = Http::timeout(15)->asJson()->post("https://graph.facebook.com/v19.0/{$pixel}/events?access_token=" . urlencode($token), $body);
        if (!$response->successful()) {
            Log::warning('Meta CAPI purchase failed', ['order' => $order->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            if ($response->serverError()) {
                $this->release($this->backoff);
            }

            return;
        }
        $order->forceFill(['capi_sent_at' => now()])->save();
    }
}
