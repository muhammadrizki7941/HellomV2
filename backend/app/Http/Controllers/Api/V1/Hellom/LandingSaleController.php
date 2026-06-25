<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\LandingPageOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
            ->where('status', LandingPageOrder::STATUS_PAID)
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
            'product_name' => (string) $order->product_name,
            'download_token' => $order->status === LandingPageOrder::STATUS_PAID ? $order->download_token : null,
        ], 'Order status');
    }
}
