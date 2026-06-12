<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\LandingAboutSetting;
use App\Models\LandingArticle;
use App\Models\LandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LandingContentController extends BaseApiController
{
    public function publicContent(): JsonResponse
    {
        $about = LandingAboutSetting::query()->where('is_active', true)->latest('id')->first();
        $services = LandingService::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
        $articles = LandingArticle::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return $this->ok([
            'about' => $about,
            'products' => $this->productSection($about),
            'services' => $services,
            'articles' => $articles,
        ], 'Landing content');
    }

    public function adminContent(): JsonResponse
    {
        $about = LandingAboutSetting::query()->latest('id')->first();

        return $this->ok([
            'about' => $about,
            'products' => $this->productSection($about),
            'services' => LandingService::query()->orderBy('sort_order')->orderByDesc('id')->get(),
            'articles' => LandingArticle::query()->orderByDesc('published_at')->orderByDesc('id')->get(),
        ], 'Landing content');
    }

    public function updateAbout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'years_experience' => ['nullable', 'integer', 'min:0'],
            'projects_completed' => ['nullable', 'integer', 'min:0'],
            'happy_clients' => ['nullable', 'integer', 'min:0'],
            'support_label' => ['nullable', 'string', 'max:50'],
            'products_label' => ['nullable', 'string', 'max:80'],
            'products_heading' => ['nullable', 'string', 'max:255'],
            'products_description' => ['nullable', 'string'],
            'products_cta_label' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ]);

        $about = LandingAboutSetting::query()->latest('id')->first();
        if (!$about) {
            $about = new LandingAboutSetting();
        }
        $about->fill($validated)->save();

        return $this->ok($about->fresh(), 'About updated');
    }

    public function storeService(Request $request): JsonResponse
    {
        $validated = $this->validateService($request);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['title']);

        return $this->ok(LandingService::query()->create($validated), 'Service created', 201);
    }

    public function updateService(Request $request, int $id): JsonResponse
    {
        $service = LandingService::query()->find($id);
        if (!$service) {
            return $this->fail('Service not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $this->validateService($request, $id);
        if (array_key_exists('slug', $validated) && !$validated['slug']) {
            $validated['slug'] = Str::slug((string) ($validated['title'] ?? $service->title));
        }
        $service->update($validated);

        return $this->ok($service->fresh(), 'Service updated');
    }

    public function destroyService(int $id): JsonResponse
    {
        LandingService::query()->whereKey($id)->delete();
        return $this->ok(null, 'Service deleted');
    }

    public function storeArticle(Request $request): JsonResponse
    {
        $validated = $this->validateArticle($request);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['title']);

        return $this->ok(LandingArticle::query()->create($validated), 'Article created', 201);
    }

    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $article = LandingArticle::query()->find($id);
        if (!$article) {
            return $this->fail('Article not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $this->validateArticle($request, $id);
        if (array_key_exists('slug', $validated) && !$validated['slug']) {
            $validated['slug'] = Str::slug((string) ($validated['title'] ?? $article->title));
        }
        $article->update($validated);

        return $this->ok($article->fresh(), 'Article updated');
    }

    public function destroyArticle(int $id): JsonResponse
    {
        LandingArticle::query()->whereKey($id)->delete();
        return $this->ok(null, 'Article deleted');
    }

    private function validateService(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:landing_services,slug,' . ($id ?? 'NULL') . ',id'],
            'icon' => ['nullable', 'string', 'max:100'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'long_description' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array{label:string,heading:string,description:string,cta_label:string}
     */
    private function productSection(?LandingAboutSetting $about): array
    {
        return [
            'label' => (string) ($about?->products_label ?: 'Products'),
            'heading' => (string) ($about?->products_heading ?: 'Produk digital premium untuk hasil maksimal.'),
            'description' => (string) ($about?->products_description ?: 'Pilih produk digital yang bisa langsung dipakai atau dibeli. POS, Landing Page Builder, template, ekstensi, dan produk lain akan tampil otomatis dari katalog backend.'),
            'cta_label' => (string) ($about?->products_cta_label ?: 'Lihat semua produk'),
        ];
    }

    private function validateArticle(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:landing_articles,slug,' . ($id ?? 'NULL') . ',id'],
            'thumbnail' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'published_at' => ['nullable', 'date'],
            'read_time' => ['nullable', 'integer', 'min:1'],
            'is_featured' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
