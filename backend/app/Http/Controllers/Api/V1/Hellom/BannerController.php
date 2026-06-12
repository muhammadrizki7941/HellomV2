<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BannerController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $items = Banner::query()
            ->orderBy('position')
            ->orderBy('order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Banner $banner) => $this->transformBanner($banner))
            ->values();

        return $this->ok(['items' => $items], 'Banner list');
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $position = $request->query('position');

        $query = Banner::query()
            ->active()
            ->orderBy('position')
            ->orderBy('order')
            ->orderByDesc('id');

        if (is_string($position) && $position !== '') {
            $query->where('position', $position);
        }

        $items = $query
            ->get()
            ->map(fn (Banner $banner) => $this->transformBanner($banner))
            ->values();

        return $this->ok(['items' => $items], 'Public banner list');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('banners', 'public');
        }

        unset($validated['remove_image']);

        $banner = Banner::query()->create($validated);

        return $this->ok($this->transformBanner($banner), 'Banner created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $banner = Banner::query()->findOrFail($id);
        $validated = $this->validatePayload($request, true);

        if (($validated['remove_image'] ?? false) && $banner->image) {
            Storage::disk('public')->delete($banner->image);
            $banner->image = null;
        }

        if ($request->hasFile('image')) {
            if ($banner->image) {
                Storage::disk('public')->delete($banner->image);
            }
            $banner->image = $request->file('image')->store('banners', 'public');
        }

        unset($validated['image'], $validated['remove_image']);

        $banner->fill($validated);
        $banner->save();

        return $this->ok($this->transformBanner($banner->fresh()), 'Banner updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $banner = Banner::query()->findOrFail($id);

        if ($banner->image) {
            Storage::disk('public')->delete($banner->image);
        }

        $banner->delete();

        return $this->ok(['deleted' => true, 'id' => $id], 'Banner deleted');
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'cta_text' => ['nullable', 'string', 'max:255'],
            'badge' => ['nullable', 'string', 'max:80'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'media_type' => ['nullable', Rule::in(['image', 'video'])],
            'video_url' => ['nullable', 'string', 'max:500'],
            'background_from' => ['nullable', 'string', 'max:20'],
            'background_to' => ['nullable', 'string', 'max:20'],
            'link' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'position' => ['nullable', Rule::in(['header', 'hero', 'sidebar'])],
            'order' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'remove_image' => ['nullable', 'boolean'],
        ]);
    }

    private function transformBanner(Banner $banner): array
    {
        return [
            'id' => (int) $banner->id,
            'title' => (string) $banner->title,
            'subtitle' => $banner->subtitle,
            'cta_text' => $banner->cta_text,
            'badge' => $banner->badge,
            'image' => $banner->image,
            'image_url' => $banner->imageUrl(),
            'media_type' => $banner->media_type,
            'video_url' => $banner->video_url,
            'background_from' => $banner->background_from,
            'background_to' => $banner->background_to,
            'link' => $banner->link,
            'is_active' => (bool) $banner->is_active,
            'position' => (string) $banner->position,
            'order' => (int) $banner->order,
            'starts_at' => optional($banner->starts_at)?->toIso8601String(),
            'ends_at' => optional($banner->ends_at)?->toIso8601String(),
            'created_at' => optional($banner->created_at)?->toIso8601String(),
            'updated_at' => optional($banner->updated_at)?->toIso8601String(),
        ];
    }
}
