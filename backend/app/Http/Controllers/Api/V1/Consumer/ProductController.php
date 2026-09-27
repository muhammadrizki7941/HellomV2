<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\DigitalProduct;
use App\Models\DigitalProductDoc;
use App\Models\DigitalProductFile;
use App\Models\ProductPurchase;
use App\Models\User;
use App\Services\DigitalProducts\ProductCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $products = DigitalProduct::query()
            ->published()
            ->with(['files:id,product_id,label,file_type,version,is_primary'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get();

        $purchases = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        $data = $products->map(function (DigitalProduct $product) use ($purchases) {
            $purchase = $purchases->get($product->id);
            return [
                ...$product->toArray(),
                'is_purchased' => $purchase?->hasAccess() ?? false,
                'purchase' => $purchase,
            ];
        });

        return $this->ok($data, 'Consumer products');
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $product = DigitalProduct::query()
            ->published()
            ->where('slug', $slug)
            ->with(['files', 'docs'])
            ->firstOrFail();

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        $product->files->each->makeHidden(['file_path']);

        if (!$purchase || !$purchase->hasAccess()) {
            $product->setRelation('docs', collect());
        } else {
            $product->docs->each->makeHidden(['file_path']);
        }

        return $this->ok([
            'product' => $product,
            'purchase' => $purchase,
            'is_purchased' => $purchase?->hasAccess() ?? false,
        ], 'Consumer product detail');
    }

    public function purchase(Request $request, string $id, ProductCheckoutService $checkout): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $validated = $request->validate([
            'payment_flow' => ['nullable', 'in:manual,gateway'],
            'manual_payment_method' => ['nullable', 'string', 'max:50'],
            'gateway_channel' => ['nullable', 'string', 'max:30'],
        ]);

        $product = DigitalProduct::query()->published()->findOrFail($id);

        $result = $checkout->start($user, $product, $validated);
        if (!$result['ok']) {
            return $this->fail($result['message'], ['code' => $result['code']], $result['status']);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function download(Request $request, string $id, string $fileId): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $id)
            ->first();

        if (!$purchase || !$purchase->hasAccess()) {
            return $this->fail('Akses ditolak', ['code' => 'FORBIDDEN'], 403);
        }

        $file = DigitalProductFile::query()
            ->where('id', $fileId)
            ->where('product_id', $id)
            ->firstOrFail();

        $disk = Storage::disk('local');
        if (!$disk->exists($file->file_path)) {
            return $this->fail('File tidak ditemukan', ['code' => 'FILE_NOT_FOUND'], 404);
        }

        $purchase->forceFill([
            'download_count' => $purchase->download_count + 1,
            'last_downloaded_at' => now(),
        ])->save();

        $purchase->product?->increment('total_downloads');

        // Stream the file directly through the API route. We deliberately avoid
        // the local disk's route-based temporaryUrl(): production nginx serves
        // every /storage/* request as a static file from storage/app/public, so
        // a signed /storage/local/... URL never reaches PHP and 404s. The /api/*
        // route is routed to PHP, so streaming here is the reliable path.
        return $disk->download($file->file_path, $this->buildDownloadName($file));
    }

    private function buildDownloadName(DigitalProductFile $file): string
    {
        $extension = pathinfo($file->file_path, PATHINFO_EXTENSION);
        $base = Str::slug((string) ($file->label ?: 'download')) ?: 'download';

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }

    public function myPurchases(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $purchases = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->with('product')
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($purchases, 'My purchases');
    }

    public function purchaseStatus(Request $request, string $id, ProductCheckoutService $checkout): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $id)
            ->with('product')
            ->first();

        if (!$purchase) {
            return $this->ok([
                'payment_status' => null,
                'is_purchased' => false,
            ], 'Belum ada transaksi');
        }

        // Webhook is the source of truth, but actively confirm pending iPaymu
        // charges so the dashboard unlocks instantly when the webhook lags.
        if ($purchase->payment_status === 'pending'
            && $purchase->payment_gateway === 'ipaymu'
            && (string) $purchase->gateway_ref !== ''
        ) {
            $checkout->syncIpaymuPurchaseStatus($purchase);
        }

        return $this->ok([
            'purchase_id' => $purchase->id,
            'payment_status' => $purchase->payment_status,
            'payment_gateway' => $purchase->payment_gateway,
            'payment_method' => $purchase->payment_method,
            'payment_instructions' => $purchase->payment_instructions,
            'is_purchased' => $purchase->hasAccess(),
        ], 'Status pembelian');
    }

    public function cancelPurchase(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $id)
            ->first();

        if (!$purchase) {
            return $this->fail('Tidak ada transaksi untuk dibatalkan.', ['code' => 'PURCHASE_NOT_FOUND'], 404);
        }

        // Paid purchases are final — no cancellation and no refund.
        if ($purchase->payment_status === 'paid' || $purchase->hasAccess()) {
            return $this->fail(
                'Pembayaran sudah lunas sehingga tidak dapat dibatalkan dan tidak bisa di-refund.',
                ['code' => 'PURCHASE_ALREADY_PAID'],
                422
            );
        }

        if ($purchase->payment_status !== 'pending') {
            return $this->fail('Transaksi ini tidak sedang menunggu pembayaran.', ['code' => 'PURCHASE_NOT_PENDING'], 422);
        }

        $purchase->forceFill([
            'payment_status' => 'cancelled',
            'payment_instructions' => null,
            'checkout_url' => null,
            'paid_at' => null,
        ])->save();

        return $this->ok([
            'purchase_id' => $purchase->id,
            'payment_status' => $purchase->payment_status,
        ], 'Pembelian dibatalkan');
    }

    public function previewDoc(Request $request, string $id, string $docId): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(401);
        }

        $purchase = ProductPurchase::query()
            ->where('user_id', $user->id)
            ->where('product_id', $id)
            ->first();

        abort_unless($purchase && $purchase->hasAccess(), 403);

        $doc = DigitalProductDoc::query()
            ->where('id', $docId)
            ->where('product_id', $id)
            ->firstOrFail();

        abort_unless($doc->doc_type === 'pdf' && $doc->file_path, 404);

        [$disk, $path] = $this->resolveDocDiskAndPath($doc->file_path);
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }

    /**
     * @return array{0:\Illuminate\Contracts\Filesystem\Filesystem,1:string}
     */
    private function resolveDocDiskAndPath(string $path): array
    {
        $normalized = ltrim($path, '/');

        if (Storage::disk('local')->exists($normalized)) {
            return [Storage::disk('local'), $normalized];
        }

        if (Str::startsWith($normalized, 'storage/')) {
            $normalized = ltrim(Str::after($normalized, 'storage/'), '/');
        }

        return [Storage::disk('public'), $normalized];
    }
}
