<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\ShowcaseClient;
use App\Models\ShowcasePortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShowcaseController extends BaseApiController
{
    // ─── Public (no auth required) ───

    public function publicPortfolios(): JsonResponse
    {
        $items = ShowcasePortfolio::query()
            ->where('is_published', true)
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->ok(['items' => $items], 'Showcase portfolios');
    }

    public function publicClients(): JsonResponse
    {
        $items = ShowcaseClient::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $this->ok(['items' => $items], 'Showcase clients');
    }

    // ─── Admin CRUD: Portfolios ───

    public function indexPortfolios(): JsonResponse
    {
        $items = ShowcasePortfolio::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return $this->ok(['items' => $items], 'All portfolios');
    }

    public function storePortfolio(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:showcase_portfolios,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'full_description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'thumbnail_url' => ['nullable', 'string', 'max:500'],
            'gallery_images' => ['nullable', 'array'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'project_year' => ['nullable', 'string', 'max:20'],
            'project_url' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'tech_stack' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
        ]);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['title']);

        $portfolio = ShowcasePortfolio::query()->create($validated);

        return $this->ok($portfolio, 'Portfolio created', 201);
    }

    public function updatePortfolio(Request $request, int $id): JsonResponse
    {
        $portfolio = ShowcasePortfolio::query()->find($id);
        if (!$portfolio) {
            return $this->fail('Portfolio not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:showcase_portfolios,slug,' . $id . ',id'],
            'description' => ['nullable', 'string', 'max:500'],
            'full_description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'thumbnail_url' => ['nullable', 'string', 'max:500'],
            'gallery_images' => ['nullable', 'array'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'project_year' => ['nullable', 'string', 'max:20'],
            'project_url' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'tech_stack' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
        ]);
        if (array_key_exists('slug', $validated) && !$validated['slug']) {
            $validated['slug'] = Str::slug((string) ($validated['title'] ?? $portfolio->title));
        }

        $portfolio->update($validated);

        return $this->ok($portfolio->fresh(), 'Portfolio updated');
    }

    public function destroyPortfolio(int $id): JsonResponse
    {
        $portfolio = ShowcasePortfolio::query()->find($id);
        if (!$portfolio) {
            return $this->fail('Portfolio not found', ['code' => 'NOT_FOUND'], 404);
        }

        $portfolio->delete();

        return $this->ok(null, 'Portfolio deleted');
    }

    // ─── Admin CRUD: Clients ───

    public function indexClients(): JsonResponse
    {
        $items = ShowcaseClient::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return $this->ok(['items' => $items], 'All clients');
    }

    public function storeClient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'logo_url' => ['required', 'string', 'max:500'],
            'website_url' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['boolean'],
        ]);

        $client = ShowcaseClient::query()->create($validated);

        return $this->ok($client, 'Client created', 201);
    }

    public function updateClient(Request $request, int $id): JsonResponse
    {
        $client = ShowcaseClient::query()->find($id);
        if (!$client) {
            return $this->fail('Client not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'logo_url' => ['sometimes', 'string', 'max:500'],
            'website_url' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['boolean'],
        ]);

        $client->update($validated);

        return $this->ok($client->fresh(), 'Client updated');
    }

    public function destroyClient(int $id): JsonResponse
    {
        $client = ShowcaseClient::query()->find($id);
        if (!$client) {
            return $this->fail('Client not found', ['code' => 'NOT_FOUND'], 404);
        }

        $client->delete();

        return $this->ok(null, 'Client deleted');
    }

    // ─── Admin: Upload video/image to storage ───

    public function uploadMedia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:mp4,webm,mov,jpg,jpeg,png,webp,gif,svg'],
        ]);

        $file = $validated['file'];
        $folder = 'showcase';
        $storedPath = $file->store($folder, 'public');

        $publicBase = '/' . trim((string) config('filesystems.disks.public.url', '/media'), '/');
        $url = $publicBase . '/' . ltrim((string) $storedPath, '/');

        return $this->ok([
            'url' => $url,
            'path' => $storedPath,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
        ], 'Media uploaded', 201);
    }
}
