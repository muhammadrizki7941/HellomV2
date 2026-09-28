<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\DigitalProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductController extends BaseApiController
{
    public function index(): JsonResponse
    {
        // Regular catalog (/produk). Flagship SaaS apps are listed by flagship() instead.
        $products = DigitalProduct::query()
            ->published()
            ->flagship(false)
            ->with(['files:id,product_id,label,file_type,version,is_primary'])
            ->select([
                'id',
                'slug',
                'name',
                'tagline',
                'category',
                'type',
                'price',
                'thumbnail_url',
                'tech_stack',
                'tags',
                'is_featured',
                'total_purchases',
                'sort_order',
            ])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get();

        return $this->ok($products, 'Public products');
    }

    /** Flagship SaaS apps for the /aplikasi showcase, with their banners. */
    public function flagship(): JsonResponse
    {
        $apps = DigitalProduct::query()
            ->published()
            ->flagship()
            ->select([
                'id',
                'slug',
                'name',
                'tagline',
                'description',
                'category',
                'type',
                'price',
                'thumbnail_url',
                'banner_url',
                'banner_mobile_url',
                'flagship_app',
                'tags',
                'sort_order',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->ok($apps, 'Flagship apps');
    }

    public function show(string $slug): JsonResponse
    {
        $product = DigitalProduct::query()
            ->published()
            ->where('slug', $slug)
            ->with(['files', 'docs'])
            ->firstOrFail();

        $this->sanitizeProduct($product);

        return $this->ok($product, 'Public product detail');
    }

    public function categories(): JsonResponse
    {
        $categories = DigitalProduct::query()
            ->published()
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        return $this->ok($categories, 'Product categories');
    }

    private function sanitizeProduct(DigitalProduct $product): void
    {
        $product->files->each->makeHidden(['file_path']);
        $product->docs->each->makeHidden(['file_path']);
    }
}
