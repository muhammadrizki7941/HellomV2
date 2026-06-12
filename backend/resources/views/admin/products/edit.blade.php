@php
    /** @var \App\Models\Product $product */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $packageCandidates */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Produk</h2>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
<form method="POST" action="{{ route('admin.products.update', $product) }}" enctype="multipart/form-data" class="grid gap-4" x-data="productEditPreview({
    name: @js(old('name', $product->name)),
    description: @js(old('description', $product->description)),
    price: @js(old('price', $product->price)),
    imageUrl: @js($product->imageUrl()),
})">
                        @csrf
                        @method('PUT')

                        <div>
                            <label class="text-sm font-medium">Kategori Utama</label>
                            <select name="category_id" class="mt-1 w-full rounded-xl border-gray-300" required>
                                @foreach($categories as $c)
                                    <option value="{{ $c->id }}" {{ $product->category_id === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                                @endforeach
                            </select>
                            @error('category_id')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror

                            <div class="mt-3">
                                <div class="text-sm font-medium">Kategori Tambahan (opsional)</div>
                                <div class="mt-2 grid sm:grid-cols-2 gap-2">
                                    @php
                                        $selectedCategoryIds = $product->categories?->pluck('id')->map(fn ($v) => (int) $v)->all() ?? [];
                                    @endphp
                                    @foreach($categories as $c)
                                        @php $checked = in_array((int) $c->id, $selectedCategoryIds, true); @endphp
                                        <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2">
                                            <input type="checkbox" name="category_ids[]" value="{{ $c->id }}" class="rounded border-gray-300" {{ $checked ? 'checked' : '' }} />
                                            <span class="text-sm">{{ $c->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('category_ids')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                @error('category_ids.*')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                <div class="text-xs text-gray-500 mt-2">Kategori utama diambil dari dropdown di atas. Produk akan tampil di semua kategori yang dicentang.</div>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Nama Produk</label>
                            <input name="name" x-model="name" value="{{ old('name', $product->name) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Deskripsi</label>
                            <textarea name="description" x-model="description" rows="3" class="mt-1 w-full rounded-xl border-gray-300">{{ old('description', $product->description) }}</textarea>
                            @error('description')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Harga (Rupiah)</label>
                            <input name="price" x-model.number="price" type="number" min="0" value="{{ old('price', $product->price) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                            @error('price')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Foto Produk</label>
                            @if($product->image_path)
                                <div class="mt-2">
                                    <img :src="imagePreview || imageUrl" class="h-32 w-48 object-cover rounded-xl border" alt="" onerror="window.__imgRetry && window.__imgRetry(this)" />
                                </div>
                                <label class="mt-2 inline-flex items-center gap-2">
                                    <input type="checkbox" name="remove_image" value="1" class="rounded border-gray-300" />
                                    <span class="text-sm">Remove current image</span>
                                </label>
                            @endif
                            <input name="image" type="file" accept="image/*" class="mt-2 w-full" @change="onImageChange($event)" />
                            @error('image')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="text-sm font-semibold text-gray-900">Preview Produk</div>
                            <div class="mt-3 flex gap-3">
                                <template x-if="imagePreview || imageUrl">
                                    <img :src="imagePreview || imageUrl" alt="Preview" class="h-20 w-20 rounded-xl border object-cover" />
                                </template>
                                <template x-if="!imagePreview && !imageUrl">
                                    <div class="h-20 w-20 rounded-xl border bg-white grid place-items-center text-xs text-gray-400">No Image</div>
                                </template>
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 truncate" x-text="name || 'Nama produk belum diisi'"></div>
                                    <div class="text-xs text-gray-500 mt-1" x-text="description || 'Deskripsi produk akan tampil di sini'"></div>
                                    <div class="text-sm font-bold text-emerald-700 mt-2">Rp <span x-text="formatRp(price)"></span></div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Urutan Tampil</label>
                            <input name="sort_order" type="number" min="0" max="10000" value="{{ old('sort_order', $product->sort_order) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            @error('sort_order')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_available" value="1" {{ $product->is_available ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Produk Aktif</span>
                        </label>

                        <div class="mt-2 rounded-2xl border border-gray-200 p-4 grid gap-3">
                            <div class="text-sm font-semibold">Stok / Limit Order</div>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="track_stock" value="1" {{ $product->track_stock ? 'checked' : '' }} class="rounded border-gray-300" />
                                <span class="text-sm">Aktifkan stok (batasi jumlah pembelian)</span>
                            </label>
                            <div>
                                <label class="text-sm font-medium">Jumlah stok</label>
                                <input name="stock" type="number" min="0" value="{{ old('stock', $product->stock) }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: 20" />
                                @error('stock')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                <div class="text-xs text-gray-500 mt-1">Jika stok aktif, checkout akan ditolak saat stok tidak cukup.</div>
                            </div>
                        </div>

                        <div class="mt-2 rounded-2xl border border-gray-200 p-4 grid gap-3">
                            <div class="text-sm font-semibold">Produk Paket (Spesial/Event)</div>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="is_package" value="1" {{ $product->is_package ? 'checked' : '' }} class="rounded border-gray-300" />
                                <span class="text-sm">Ini adalah produk paket</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="show_as_banner" value="1" {{ $product->show_as_banner ? 'checked' : '' }} class="rounded border-gray-300" />
                                <span class="text-sm">Tampilkan sebagai banner di halaman customer</span>
                            </label>
                            @error('show_as_banner')<div class="text-sm text-red-600">{{ $message }}</div>@enderror

                            <div>
                                <label class="text-sm font-medium">Judul banner (opsional)</label>
                                <input name="banner_title" value="{{ old('banner_title', $product->banner_title) }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: Paket Hemat Weekend" />
                                @error('banner_title')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Subjudul banner (opsional)</label>
                                <input name="banner_subtitle" value="{{ old('banner_subtitle', $product->banner_subtitle) }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: Lebih murah sampai jam 17:00" />
                                @error('banner_subtitle')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div class="grid sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm font-medium">Mulai (opsional)</label>
                                    <input name="banner_starts_at" type="datetime-local" value="{{ old('banner_starts_at', $product->banner_starts_at?->format('Y-m-d\\TH:i')) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                    @error('banner_starts_at')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Selesai (opsional)</label>
                                    <input name="banner_ends_at" type="datetime-local" value="{{ old('banner_ends_at', $product->banner_ends_at?->format('Y-m-d\\TH:i')) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                    @error('banner_ends_at')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="text-xs text-gray-500">Slug otomatis: <span class="font-mono">{{ $product->slug }}</span></div>

                        <div class="flex items-center gap-3">
                            <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Simpan Perubahan</button>
                            <a href="{{ route('admin.products.index') }}" class="text-sm text-gray-600">Kembali</a>
                            <a href="{{ route('admin.products.options.index', $product) }}" class="ml-auto rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold">Kelola Add-on / Selera</a>
                        </div>
                    </form>

                    @if($product->is_package)
                        <div class="mt-6 rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Isi Paket</div>
                            <div class="text-xs text-gray-500 mt-1">Tentukan produk apa saja yang termasuk dalam paket ini.</div>

                            <form method="POST" action="{{ route('admin.products.package-items.store', $product) }}" class="mt-3 grid sm:grid-cols-3 gap-3">
                                @csrf
                                <div class="sm:col-span-2">
                                    <label class="text-sm font-medium">Pilih produk</label>
                                    <select name="item_product_id" class="mt-1 w-full rounded-xl border-gray-300" required>
                                        <option value="">-- pilih --</option>
                                        @foreach($packageCandidates as $cand)
                                            <option value="{{ $cand->id }}">{{ $cand->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('item_product_id')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Qty</label>
                                    <input name="qty" type="number" min="1" max="99" value="{{ old('qty', 1) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                                    @error('qty')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div class="sm:col-span-3">
                                    <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Tambah ke paket</button>
                                </div>
                            </form>

                            <div class="mt-4 grid gap-2">
                                @foreach($product->packageItems as $pi)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3">
                                        <div>
                                            <div class="text-sm font-semibold">{{ $pi->itemProduct?->name ?? 'Produk' }}</div>
                                            <div class="text-xs text-gray-500">Qty: {{ (int) $pi->qty }}</div>
                                        </div>
                                        <form method="POST" action="{{ route('admin.products.package-items.destroy', [$product, $pi]) }}" onsubmit="return confirm('Hapus item dari paket?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-sm font-semibold text-red-600">Hapus</button>
                                        </form>
                                    </div>
                                @endforeach

                                @if($product->packageItems->count() === 0)
                                    <div class="text-sm text-gray-500">Belum ada isi paket.</div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="mt-6 text-xs text-gray-500">Untuk menambah isi paket: centang “Ini adalah produk paket”, lalu klik Save dulu.</div>
                    @endif

                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="mt-6" onsubmit="return confirm('Delete product?')">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-semibold text-red-600">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    function productEditPreview(initial) {
        return {
            name: initial?.name || '',
            description: initial?.description || '',
            price: Number(initial?.price || 0),
            imageUrl: initial?.imageUrl || null,
            imagePreview: null,
            onImageChange(event) {
                const file = event?.target?.files?.[0];
                if (!file) {
                    this.imagePreview = null;
                    return;
                }
                const reader = new FileReader();
                reader.onload = (e) => { this.imagePreview = e.target?.result || null; };
                reader.readAsDataURL(file);
            },
            formatRp(value) {
                return new Intl.NumberFormat('id-ID').format(Number(value || 0));
            }
        }
    }
</script>
@endsection
