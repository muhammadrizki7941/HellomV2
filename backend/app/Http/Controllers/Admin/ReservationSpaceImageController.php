<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReservationSpace;
use App\Models\ReservationSpaceImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReservationSpaceImageController extends Controller
{
    public function store(Request $request, ReservationSpace $space)
    {
        $validated = $request->validate([
            'image' => ['nullable', 'required_without:images', 'image', 'max:4096'],
            'images' => ['nullable', 'required_without:image', 'array'],
            'images.*' => ['image', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:160'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $baseSortOrder = (int) ($validated['sort_order'] ?? 0);
        $caption = $validated['caption'] ?: null;

        $files = [];
        if ($request->hasFile('images')) {
            $files = $request->file('images') ?? [];
        } elseif ($request->hasFile('image')) {
            $files = [$request->file('image')];
        }

        foreach (array_values($files) as $idx => $file) {
            if (!$file) {
                continue;
            }

            $path = $file->store('reservation_spaces', 'public');

            ReservationSpaceImage::query()->create([
                'reservation_space_id' => $space->id,
                'image_path' => $path,
                'caption' => $caption,
                'sort_order' => $baseSortOrder + $idx,
            ]);
        }

        return back();
    }

    public function destroy(ReservationSpace $space, ReservationSpaceImage $image)
    {
        if ((int) $image->reservation_space_id !== (int) $space->id) {
            abort(404);
        }

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return back();
    }
}
