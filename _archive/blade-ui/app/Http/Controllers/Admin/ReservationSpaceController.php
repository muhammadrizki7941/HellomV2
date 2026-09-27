<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ReservationSpace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReservationSpaceController extends Controller
{
    public function index()
    {
        $spaces = ReservationSpace::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.reservation_spaces.index', [
            'spaces' => $spaces,
        ]);
    }

    public function create()
    {
        return view('admin.reservation_spaces.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rent_price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'rent_enabled' => ['nullable', 'boolean'],
            'min_menu_total' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:4096'],
            'image_caption' => ['nullable', 'string', 'max:160'],
            'image_sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $i = 2;
        while (ReservationSpace::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        $space = ReservationSpace::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'location' => $validated['location'] ?: null,
            'capacity' => isset($validated['capacity']) ? (int) $validated['capacity'] : null,
            'description' => $validated['description'] ?: null,
            'rent_price' => (int) $validated['rent_price'],
            'rent_enabled' => (bool) ($validated['rent_enabled'] ?? true),
            'min_menu_total' => (int) ($validated['min_menu_total'] ?? 0),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        if ($request->hasFile('images')) {
            $caption = $validated['image_caption'] ?? null;
            $baseSortOrder = (int) ($validated['image_sort_order'] ?? 0);

            foreach (array_values($request->file('images') ?? []) as $idx => $file) {
                if (!$file) {
                    continue;
                }

                $path = $file->store('reservation_spaces', 'public');

                $space->images()->create([
                    'image_path' => $path,
                    'caption' => $caption,
                    'sort_order' => $baseSortOrder + $idx,
                ]);
            }
        }

        return redirect()->route('admin.reservation-spaces.index')->with('success', 'Reservation space berhasil dibuat. Foto galeri tersimpan.');
    }

    public function show(ReservationSpace $reservation_space)
    {
        return redirect()->route('admin.reservation-spaces.edit', $reservation_space);
    }

    public function edit(ReservationSpace $reservation_space)
    {
        $space = $reservation_space->load(['images', 'items.product']);

        $products = Product::query()
            ->where('is_available', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price']);

        return view('admin.reservation_spaces.edit', [
            'space' => $space,
            'products' => $products,
        ]);
    }

    public function update(Request $request, ReservationSpace $reservation_space)
    {
        $space = $reservation_space;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rent_price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'rent_enabled' => ['nullable', 'boolean'],
            'min_menu_total' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $i = 2;
        while (ReservationSpace::query()->where('slug', $slug)->where('id', '!=', $space->id)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        $space->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'location' => $validated['location'] ?: null,
            'capacity' => isset($validated['capacity']) ? (int) $validated['capacity'] : null,
            'description' => $validated['description'] ?: null,
            'rent_price' => (int) $validated['rent_price'],
            'rent_enabled' => (bool) ($validated['rent_enabled'] ?? false),
            'min_menu_total' => (int) ($validated['min_menu_total'] ?? 0),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('admin.reservation-spaces.edit', $space);
    }

    public function destroy(ReservationSpace $reservation_space)
    {
        $space = $reservation_space->load(['images']);

        foreach ($space->images as $img) {
            try {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($img->image_path);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $space->delete();

        return redirect()->route('admin.reservation-spaces.index');
    }
}
