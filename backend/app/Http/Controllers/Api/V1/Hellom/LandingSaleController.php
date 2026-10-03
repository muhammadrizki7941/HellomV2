<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Jobs\ReconcileLandingOrder;
use App\Models\LandingPageOrder;
use App\Services\Landing\OrderAccessService;
use App\Services\Landing\PaymentStarter;
use App\Services\SellerFinance\LandingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\Response;

class LandingSaleController extends BaseApiController
{
    /**
     * Public: stream the dynamic QRIS image for an order as a downloadable PNG.
     * Proxied through our server so the buyer can save it (avoids cross-origin
     * download issues with the gateway-hosted image).
     */
    public function qr(Request $request, string $reference): Response|JsonResponse
    {
        $order = LandingPageOrder::query()->where('reference_id', $reference)->first();
        if (!$order instanceof LandingPageOrder) {
            return $this->fail('Pesanan tidak ditemukan', ['code' => 'ORDER_NOT_FOUND'], 404);
        }

        $disposition = $request->boolean('download')
            ? 'attachment; filename="qris-' . $reference . '.svg"'
            : 'inline';

        // iPaymu usually sends the QRIS code itself (QrString/PaymentNo): draw it here,
        // no request to iPaymu needed.
        $qrString = (string) data_get($order->metadata, 'qr_string', '');
        if ($qrString !== '') {
            $svg = QrCode::format('svg')->size(560)->margin(1)->errorCorrection('M')->generate($qrString);

            return response((string) $svg, 200, [
                'Content-Type' => 'image/svg+xml',
                'Content-Disposition' => $disposition,
                'Cache-Control' => 'no-store, private',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            ]);
        }

        $qrUrl = (string) data_get($order->metadata, 'qr_image_url', '');
        if ($qrUrl === '') {
            return $this->fail('QR tidak tersedia untuk pesanan ini', ['code' => 'QR_NOT_AVAILABLE'], 404);
        }

        try {
            $response = Http::timeout(15)->get($qrUrl);
            if (!$response->successful()) {
                return $this->fail('Gagal mengambil gambar QR', ['code' => 'QR_FETCH_FAILED'], 502);
            }
        } catch (\Throwable $e) {
            return $this->fail('Gagal mengambil gambar QR', ['code' => 'QR_FETCH_FAILED'], 502);
        }

        $contentType = (string) ($response->header('Content-Type') ?: 'image/png');
        $disposition = $request->boolean('download')
            ? 'attachment; filename="qris-' . $reference . '.png"'
            : 'inline';

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-store, private',
        ]);
    }


    /**
     * Public: a buyer fetches their paid order by download token to access the
     * digital product / PDF. Only paid orders expose the file link.
     */
    public function download(Request $request, string $token): JsonResponse
    {
        $order = LandingPageOrder::query()
            ->where('download_token', $token)
            ->whereIn('status', [LandingPageOrder::STATUS_PAID, LandingPageOrder::STATUS_FULFILLED])
            ->first();

        if (!$order instanceof LandingPageOrder) {
            return $this->fail('Pesanan tidak ditemukan atau belum dibayar', ['code' => 'ORDER_NOT_FOUND'], 404);
        }

        return $this->ok([
            'product_name' => (string) $order->product_name,
            'product_kind' => (string) $order->product_kind,
            'amount' => (int) $order->amount,
            'buyer_name' => $order->buyer_name,
            'paid_at' => $order->paid_at,
            'file_url' => $order->file_url,
            'has_file' => (bool) $order->file_url,
        ], 'Order detail');
    }

    /** Public: lightweight status poll by reference_id (for the return page). */
    public function status(Request $request, string $reference): JsonResponse
    {
        $order = LandingPageOrder::query()->where('reference_id', $reference)->first();
        if (!$order instanceof LandingPageOrder) {
            return $this->fail('Pesanan tidak ditemukan', ['code' => 'ORDER_NOT_FOUND'], 404);
        }

        return $this->ok([
            'status' => (string) $order->status,
            'status_label' => LandingPageOrder::LABELS[(string) $order->status] ?? (string) $order->status,
            'product_name' => (string) $order->product_name,
            'amount' => (int) $order->amount,
            'buyer_email_masked' => $this->maskEmail((string) $order->buyer_email),
            'expires_at' => optional($order->expires_at)->toIso8601String(),
            'paid_at' => optional($order->paid_at)->toIso8601String(),
            'has_file' => app(OrderAccessService::class)->isDigital($order),
            'download_token' => $order->isPaid() ? $order->download_token : null,
            // Paid: the buyer continues on the access page (limits, latest link, status).
            'access_path' => $order->isPaid() && $order->download_token ? '/akses/' . $order->download_token : null,
            // Still unpaid: the QR / VA number again, so a reload does not lose them.
            'payment' => $order->status === LandingPageOrder::STATUS_PENDING ? PaymentStarter::instructions($order) : null,
        ], 'Order status');
    }

    /**
     * Public: the buyer returned from the gateway (iPaymu appends ?trx_id=…). The id is
     * stored as a hint only; a background check with the gateway decides. This endpoint
     * can never mark an order paid.
     */
    public function returned(Request $request, string $reference, LandingPaymentService $payments): JsonResponse
    {
        $validated = $request->validate(['trx_id' => ['nullable', 'string', 'max:120']]);
        $order = LandingPageOrder::query()->where('reference_id', $reference)->first();
        if (!$order instanceof LandingPageOrder) {
            return $this->fail('Pesanan tidak ditemukan', ['code' => 'ORDER_NOT_FOUND'], 404);
        }

        if (!empty($validated['trx_id'])) {
            $payments->recordReturnHint($order, (string) $validated['trx_id']);
        }
        if ($order->status === LandingPageOrder::STATUS_PENDING) {
            ReconcileLandingOrder::dispatch((int) $order->id)->afterResponse();
        }

        return $this->ok(['status' => (string) $order->status], 'Status pesanan sedang dicek');
    }

    private function maskEmail(string $email): ?string
    {
        if (!str_contains($email, '@')) {
            return null;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 2) . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }
}
