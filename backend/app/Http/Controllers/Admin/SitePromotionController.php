<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SitePromotion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class SitePromotionController extends Controller
{
    public function index()
    {
        $promos = SitePromotion::query()
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.site_promotions.index', [
            'promos' => $promos,
        ]);
    }

    public function create()
    {
        return view('admin.site_promotions.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateForm($request);

        $slug = Str::slug($validated['slug'] ?: $validated['title']);
        if ($slug === '') $slug = 'promo';

        // Ensure unique slug.
        $base = $slug;
        $i = 1;
        while (SitePromotion::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        $data = [
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?: null,
            'link_url' => $validated['link_url'] ?: null,
            'starts_at' => $validated['starts_at'] ?: null,
            'ends_at' => $validated['ends_at'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = $request->file('thumbnail')->store('promotions', 'public');
        }

        SitePromotion::query()->create($data);

        return redirect()->route('admin.site-promotions.index')->with('success', 'Promo/Event dibuat.');
    }

    public function edit(SitePromotion $site_promotion)
    {
        return view('admin.site_promotions.edit', [
            'promo' => $site_promotion,
        ]);
    }

    public function update(Request $request, SitePromotion $site_promotion)
    {

        $validated = $this->validateForm($request, $site_promotion);

        $slug = Str::slug($validated['slug'] ?: $validated['title']);
        if ($slug === '') $slug = $site_promotion->slug ?: 'promo';

        $data = [
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?: null,
            'link_url' => $validated['link_url'] ?: null,
            'starts_at' => $validated['starts_at'] ?: null,
            'ends_at' => $validated['ends_at'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];

        if ($request->hasFile('thumbnail')) {
            $path = $request->file('thumbnail')->store('promotions', 'public');
            if ($site_promotion->thumbnail_path) {
                Storage::disk('public')->delete($site_promotion->thumbnail_path);
            }
            $data['thumbnail_path'] = $path;
        }

        $site_promotion->update($data);

        return redirect()->route('admin.site-promotions.index')->with('success', 'Promo/Event diperbarui.');
    }

    public function destroy(SitePromotion $site_promotion)
    {
        if ($site_promotion->thumbnail_path) {
            Storage::disk('public')->delete($site_promotion->thumbnail_path);
        }
        $site_promotion->delete();

        return redirect()->route('admin.site-promotions.index')->with('success', 'Promo/Event dihapus.');
    }

    private function validateForm(Request $request, ?SitePromotion $promo = null): array
    {
        $id = $promo?->id;

        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', Rule::unique('site_promotions', 'slug')->ignore($id)],
            'description' => ['nullable', 'string', 'max:4000'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }
}
