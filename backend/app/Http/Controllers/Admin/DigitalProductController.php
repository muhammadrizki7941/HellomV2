<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\Hellom\BaseApiController;
use App\Models\DigitalProduct;
use App\Models\DigitalProductDoc;
use App\Models\DigitalProductFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DigitalProductController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = DigitalProduct::query();

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $category = $request->query('category');
        if ($category) {
            $query->where('category', $category);
        }

        if ($request->has('is_published')) {
            $query->where('is_published', filter_var($request->query('is_published'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $items = $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->ok([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ], 'Digital products loaded');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:source_code,application,extension,ebook,template,course,other'],
            'type' => ['required', 'in:free,paid,subscription_locked'],
            'price' => ['required_if:type,paid', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'tech_stack' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_flagship' => ['boolean'],
            'flagship_app' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $slug = $this->uniqueSlug($validated['name']);

        $product = DigitalProduct::query()->create([
            ...$validated,
            'slug' => $slug,
            'price' => (int) ($validated['price'] ?? 0),
            'currency' => 'IDR',
        ]);

        $this->adminAudit($request, 'admin.digital_product.created', 'digital_product', (int) $product->id, ['name' => $product->name, 'price' => (int) $product->price]);

        return $this->ok($product, 'Digital product created', 201);
    }

    public function show(string $id): JsonResponse
    {
        $product = DigitalProduct::query()
            ->with(['files', 'docs'])
            ->withCount('purchases')
            ->findOrFail($id);

        return $this->ok($product, 'Digital product detail');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:source_code,application,extension,ebook,template,course,other'],
            'type' => ['required', 'in:free,paid,subscription_locked'],
            'price' => ['required_if:type,paid', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'tech_stack' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_flagship' => ['boolean'],
            'flagship_app' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $slug = $product->slug;
        if ($product->name !== $validated['name']) {
            $slug = $this->uniqueSlug($validated['name'], $product->id);
        }

        $product->update([
            ...$validated,
            'slug' => $slug,
            'price' => (int) ($validated['price'] ?? 0),
        ]);
        $this->adminAudit($request, 'admin.digital_product.updated', 'digital_product', (int) $product->id, ['name' => $product->name, 'price' => (int) $product->price, 'is_published' => (bool) $product->is_published]);

        return $this->ok($product->fresh(), 'Digital product updated');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);
        $product->delete(); // soft delete: purchases and buyer access stay intact
        $this->adminAudit($request, 'admin.digital_product.deleted', 'digital_product', (int) $product->id, ['name' => $product->name]);

        return $this->ok(true, 'Digital product deleted');
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);
        $product->update(['is_published' => true]);
        $this->adminAudit($request, 'admin.digital_product.published', 'digital_product', (int) $product->id, ['name' => $product->name]);

        return $this->ok($product->fresh(), 'Product published');
    }

    public function unpublish(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);
        $product->update(['is_published' => false]);
        $this->adminAudit($request, 'admin.digital_product.unpublished', 'digital_product', (int) $product->id, ['name' => $product->name]);

        return $this->ok($product->fresh(), 'Product unpublished');
    }

    public function uploadThumbnail(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);

        $validated = $request->validate([
            'thumbnail' => ['required', 'image', 'max:2048'],
        ]);

        $path = $validated['thumbnail']->store('products/thumbnails', 'public');
        $product->update([
            'thumbnail_url' => Storage::disk('public')->url($path),
        ]);

        return $this->ok($product->fresh(), 'Thumbnail uploaded');
    }

    /**
     * Banner for the public /aplikasi showcase (flagship apps).
     * variant=desktop (wide, ~1920×800) or mobile (portrait, ~1080×1350).
     */
    public function uploadBanner(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);

        $validated = $request->validate([
            'variant' => ['required', 'in:desktop,mobile'],
            'banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $column = $validated['variant'] === 'mobile' ? 'banner_mobile_url' : 'banner_url';
        $this->deletePublicUrl($product->{$column});
        $path = $validated['banner']->store('products/banners', 'public');
        $product->update([$column => Storage::disk('public')->url($path)]);

        return $this->ok($product->fresh(), 'Banner uploaded');
    }

    public function deleteBanner(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);
        $validated = $request->validate(['variant' => ['required', 'in:desktop,mobile']]);

        $column = $validated['variant'] === 'mobile' ? 'banner_mobile_url' : 'banner_url';
        $this->deletePublicUrl($product->{$column});
        $product->update([$column => null]);

        return $this->ok($product->fresh(), 'Banner removed');
    }

    public function uploadFile(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'file_type' => ['required', 'in:zip,pdf,mp4,exe,apk,other'],
            'version' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'product_file' => ['required', 'file', 'max:102400'],
        ]);

        $file = $validated['product_file'];
        $name = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("products/{$product->id}", $name, 'local');

        $record = DigitalProductFile::query()->create([
            'product_id' => $product->id,
            'label' => $validated['label'],
            'file_type' => $validated['file_type'],
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'version' => $validated['version'] ?? null,
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return $this->ok($record, 'File uploaded', 201);
    }

    public function uploadDoc(Request $request, string $id): JsonResponse
    {
        $product = DigitalProduct::query()->findOrFail($id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'doc_type' => ['required', 'in:text,pdf,video,link'],
            'content' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'external_url' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'doc_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $doc = new DigitalProductDoc([
            'product_id' => $product->id,
            'title' => $validated['title'],
            'doc_type' => $validated['doc_type'],
            'content' => $validated['doc_type'] === 'text' ? ($validated['content'] ?? null) : null,
            'video_url' => $validated['doc_type'] === 'video' ? ($validated['video_url'] ?? null) : null,
            'external_url' => $validated['doc_type'] === 'link' ? ($validated['external_url'] ?? null) : null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        if ($validated['doc_type'] === 'pdf' && $request->hasFile('doc_pdf')) {
            $file = $request->file('doc_pdf');
            $name = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
            $doc->file_path = $file->storeAs("products/{$product->id}/docs", $name, 'local');
        }

        $doc->save();

        return $this->ok($doc, 'Documentation uploaded', 201);
    }

    public function deleteFile(Request $request, string $fileId): JsonResponse
    {
        $file = DigitalProductFile::query()->findOrFail($fileId);
        Storage::disk('local')->delete($file->file_path);
        $file->delete();
        $this->adminAudit($request, 'admin.digital_product.file_deleted', 'digital_product', (int) $file->product_id, ['file' => $file->label]);

        return $this->ok(true, 'File deleted');
    }

    public function deleteDoc(string $docId): JsonResponse
    {
        $doc = DigitalProductDoc::query()->findOrFail($docId);
        if ($doc->file_path) {
            $this->deleteDocFile($doc->file_path);
        }
        $doc->delete();

        return $this->ok(true, 'Documentation deleted');
    }

    public function previewDoc(string $docId): Response
    {
        $doc = DigitalProductDoc::query()->findOrFail($docId);
        abort_unless($doc->doc_type === 'pdf' && $doc->file_path, 404);

        [$disk, $path] = $this->resolveDocDiskAndPath($doc->file_path);
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $baseSlug = $slug;
        $i = 2;

        while (DigitalProduct::query()
            ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        return $slug;
    }

    /** Remove a file we stored on the public disk (ignores external URLs). */
    private function deletePublicUrl(?string $url): void
    {
        // Public disk URLs are /media/... today (/storage/... for older uploads).
        $path = parse_url((string) $url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#/(?:media|storage)/(products/banners/.+)$#', $path, $match)) {
            return;
        }
        Storage::disk('public')->delete($match[1]);
    }

    private function deleteDocFile(string $path): void
    {
        [$disk, $resolvedPath] = $this->resolveDocDiskAndPath($path);
        $disk->delete($resolvedPath);
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
