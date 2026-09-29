<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\LandingProduct;
use App\Services\Landing\ProductService;
use App\Support\DriveLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Seller products of Hellom Page (owner/admin of the current organization).
 * Boolean fields may arrive from FormData, so they are read with FILTER_VALIDATE_BOOLEAN.
 */
class SellerProductController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function __construct(private readonly ProductService $products)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $items = LandingProduct::query()->where('organization_id', $organization->id)
            ->orderBy('sort_order')->orderByDesc('id')->get()
            ->map(fn (LandingProduct $p) => $this->products->sellerPayload($p))->values();

        return $this->ok(['items' => $items, 'limits' => ['max_file_mb' => ProductService::MAX_FILE_KB / 1024, 'file_extensions' => ProductService::FILE_EXTENSIONS]], 'Produk');
    }

    public function show(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);

        return $error ?? $this->ok($this->products->sellerPayload($product), 'Produk');
    }

    public function store(Request $request): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $this->normalizeBooleans($request);
        $data = $request->validate($this->products->rules(), [], $this->attributes());
        $product = $this->products->save($organization, $this->booleans($request, $data));

        return $this->ok($this->products->sellerPayload($product), 'Produk dibuat', 201);
    }

    public function update(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);
        if ($error) {
            return $error;
        }
        $this->normalizeBooleans($request);
        $data = $request->validate($this->products->rules(), [], $this->attributes());
        $product = $this->products->save($product->organization, $this->booleans($request, $data), $product);

        return $this->ok($this->products->sellerPayload($product), 'Produk disimpan');
    }

    public function toggle(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);
        if ($error) {
            return $error;
        }
        $product->forceFill(['is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)])->save();

        return $this->ok($this->products->sellerPayload($product), $product->is_active ? 'Produk dijual lagi' : 'Produk disembunyikan');
    }

    public function destroy(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);
        if ($error) {
            return $error;
        }
        // Soft delete: orders keep pointing at it; buyers keep their access.
        $product->forceFill(['is_active' => false])->save();
        $product->delete();

        return $this->ok(['deleted' => true], 'Produk dihapus');
    }

    public function uploadImage(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);
        if ($error) {
            return $error;
        }
        $request->validate(['image' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp']], [], ['image' => 'gambar']);
        try {
            $product = $this->products->storeImage($product, $request->file('image'));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), ['code' => 'IMAGE_UNREADABLE'], 422);
        }

        return $this->ok($this->products->sellerPayload($product), 'Gambar disimpan');
    }

    public function uploadFile(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);
        if ($error) {
            return $error;
        }
        if ($product->type !== LandingProduct::TYPE_FILE) {
            return $this->fail('Upload file hanya untuk produk tipe "upload file"', ['code' => 'PRODUCT_NOT_FILE'], 422);
        }
        $request->validate(['file' => ['required', 'file', 'max:' . ProductService::MAX_FILE_KB]], ['file.max' => 'Ukuran file maksimal 10 MB.'], ['file' => 'file']);
        $product = $this->products->storeFile($product, $request->file('file'));

        return $this->ok($this->products->sellerPayload($product), 'File produk disimpan');
    }

    public function deleteFile(Request $request, int $productId): JsonResponse
    {
        [$product, $error] = $this->findProduct($request, $productId);
        if ($error) {
            return $error;
        }
        $this->products->deleteFile($product);

        return $this->ok($this->products->sellerPayload($product->fresh()), 'File dihapus');
    }

    /** Live check while the seller types a Drive link. */
    public function checkDriveLink(Request $request): JsonResponse
    {
        [, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate(['url' => ['required', 'string', 'max:2000']]);
        $parsed = DriveLink::parse($validated['url']);

        return $this->ok(['valid' => $parsed !== null, 'kind' => $parsed['kind'] ?? null], $parsed ? 'Link Google Drive valid' : 'Bukan link Google Drive/Docs');
    }

    /** @return array{0: ?LandingProduct, 1: ?JsonResponse} */
    private function findProduct(Request $request, int $productId): array
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return [null, $error];
        }
        $product = LandingProduct::query()->where('organization_id', $organization->id)->with('organization')->find($productId);

        return $product ? [$product, null] : [null, $this->fail('Produk tidak ditemukan', ['code' => 'PRODUCT_NOT_FOUND'], 404)];
    }

    /** FormData sends "true"/"false"/"1"/"0" strings; normalise before the boolean rule runs. */
    private function booleans(Request $request, array $data): array
    {
        foreach (['is_active', 'require_phone'] as $key) {
            if ($request->has($key)) {
                $data[$key] = filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $data;
    }

    private function normalizeBooleans(Request $request): void
    {
        foreach (['is_active', 'require_phone'] as $key) {
            if ($request->has($key) && is_string($request->input($key))) {
                $request->merge([$key => filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN)]);
            }
        }
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => 'nama produk', 'price' => 'harga', 'compare_at_price' => 'harga coret', 'stock' => 'stok',
            'delivery_url' => 'link produk', 'access_max_opens' => 'batas buka', 'access_days' => 'masa berlaku akses',
            'download_limit' => 'batas unduh', 'shipping_fee' => 'ongkir',
        ];
    }
}
