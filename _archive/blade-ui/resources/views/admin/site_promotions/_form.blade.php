@php
    /** @var \App\Models\SitePromotion|null $promo */
@endphp

<div class="grid gap-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-semibold">Judul</label>
            <input name="title" value="{{ old('title', $promo?->title) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" required />
        </div>
        <div>
            <label class="text-sm font-semibold">Slug (opsional)</label>
            <input name="slug" value="{{ old('slug', $promo?->slug) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" placeholder="promo-spesial" />
            <div class="mt-1 text-xs text-gray-500">Kalau kosong, otomatis dari judul.</div>
        </div>
    </div>

    <div>
        <label class="text-sm font-semibold">Deskripsi (opsional)</label>
        <textarea name="description" rows="4" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3">{{ old('description', $promo?->description) }}</textarea>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-semibold">Link Tujuan (opsional)</label>
            <input name="link_url" value="{{ old('link_url', $promo?->link_url) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" placeholder="https://... atau /reservasi" />
            <div class="mt-1 text-xs text-gray-500">Boleh link internal (mulai dengan /) atau link luar.</div>
        </div>

        <div>
            <label class="text-sm font-semibold">Thumbnail (opsional)</label>
            <input type="file" name="thumbnail" accept="image/*" class="mt-2 w-full" />
            @if($promo?->thumbnailUrl())
                <img src="{{ $promo->thumbnailUrl() }}" class="mt-3 h-16 rounded-xl border border-gray-200 object-cover" alt="thumb" onerror="window.__imgRetry && window.__imgRetry(this)" />
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div>
            <label class="text-sm font-semibold">Mulai (opsional)</label>
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $promo?->starts_at?->format('Y-m-d\\TH:i')) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
        </div>
        <div>
            <label class="text-sm font-semibold">Selesai (opsional)</label>
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $promo?->ends_at?->format('Y-m-d\\TH:i')) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
        </div>
        <div>
            <label class="text-sm font-semibold">Urutan</label>
            <input type="number" name="sort_order" value="{{ old('sort_order', (int)($promo?->sort_order ?? 0)) }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" min="0" />
        </div>
        <div class="flex items-end">
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $promo?->is_active ?? true) ? 'checked' : '' }} />
                <span class="text-sm font-semibold">Aktif</span>
            </label>
        </div>
    </div>
</div>
