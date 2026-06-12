@php
    /** @var \App\Models\ReservationSpace $space */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $products */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Space: {{ $space->name }}</h2>
        <a href="{{ route('admin.reservation-spaces.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Kembali</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 grid gap-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.reservation-spaces.update', $space) }}" class="grid gap-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <label class="text-sm font-medium">Nama</label>
                            <input name="name" value="{{ old('name', $space->name) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium">Lokasi</label>
                                <input name="location" value="{{ old('location', $space->location) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                @error('location')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Kapasitas</label>
                                <input name="capacity" type="number" min="1" value="{{ old('capacity', $space->capacity) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                @error('capacity')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Deskripsi</label>
                            <textarea name="description" rows="4" class="mt-1 w-full rounded-xl border-gray-300">{{ old('description', $space->description) }}</textarea>
                            @error('description')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium">Biaya sewa (Rp)</label>
                                <input name="rent_price" type="number" min="0" value="{{ old('rent_price', $space->rent_price) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                                @error('rent_price')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Sort order</label>
                                <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $space->sort_order) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                @error('sort_order')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mt-1 rounded-2xl border border-gray-200 p-4 grid gap-3">
                            <div class="text-sm font-semibold">Aturan Reservasi</div>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="rent_enabled" value="1" {{ old('rent_enabled', $space->rent_enabled) ? 'checked' : '' }} class="rounded border-gray-300" />
                                <span class="text-sm">Aktifkan biaya sewa (jika off → sewa = Rp 0 / free)</span>
                            </label>
                            <div>
                                <label class="text-sm font-medium">Minimal order menu (Rp) (opsional)</label>
                                <input name="min_menu_total" type="number" min="0" value="{{ old('min_menu_total', $space->min_menu_total) }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: 1000000" />
                                @error('min_menu_total')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                <div class="text-xs text-gray-500 mt-1">Jika diisi, customer wajib mengisi komitmen order menu minimal sebesar ini untuk bisa submit reservasi.</div>
                            </div>
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $space->is_active) ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Active</span>
                        </label>

                        <div class="flex items-center gap-2 pt-2">
                            <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm font-semibold">Update</button>
                            <a href="{{ route('admin.reservation-spaces.index') }}" class="px-4 py-2 rounded-xl border text-sm font-semibold">Kembali</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-lg font-semibold">Gambar</div>
                            <div class="text-xs text-gray-500">Upload beberapa foto untuk customer.</div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.reservation-spaces.images.store', $space) }}" enctype="multipart/form-data" class="mt-4 grid gap-3">
                        @csrf
                        <div>
                            <label class="text-sm font-medium">Foto</label>
                            <input name="images[]" type="file" accept="image/*" class="mt-1 w-full" multiple required />
                            @error('image')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            @error('images')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            @error('images.*')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            <div class="text-xs text-gray-500 mt-1">Bisa pilih banyak foto sekaligus.</div>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium">Caption</label>
                                <input name="caption" value="{{ old('caption') }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Opsional" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">Sort order</label>
                                <input name="sort_order" type="number" min="0" value="{{ old('sort_order', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            </div>
                        </div>
                        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm font-semibold w-fit">Tambah Gambar</button>
                    </form>

                    <div class="mt-5 grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @forelse($space->images as $img)
                            <div class="rounded-2xl border border-gray-200 overflow-hidden">
                                <img src="{{ $img->url() }}" alt="" class="h-44 w-full object-cover" />
                                <div class="p-3">
                                    <div class="text-xs text-gray-500">Sort: {{ $img->sort_order }}</div>
                                    @if($img->caption)
                                        <div class="text-sm mt-1">{{ $img->caption }}</div>
                                    @endif
                                    <form method="POST" action="{{ route('admin.reservation-spaces.images.destroy', [$space, $img]) }}" class="mt-3" onsubmit="return confirm('Hapus gambar ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="px-3 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 text-xs font-semibold">Hapus</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Belum ada gambar.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-lg font-semibold">Paket Menu Termasuk</div>
                            <div class="text-xs text-gray-500">Harga total = biaya sewa + total menu termasuk.</div>
                        </div>
                        <div class="text-sm font-semibold">Total sekarang: Rp {{ number_format($space->total_price, 0, ',', '.') }}</div>
                    </div>

                    <form method="POST" action="{{ route('admin.reservation-spaces.items.store', $space) }}" class="mt-4 grid gap-3" x-data="{ productPrices: @js($products->pluck('price','id')), selected: '' }">
                        @csrf
                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium">Produk</label>
                                <select name="product_id" class="mt-1 w-full rounded-xl border-gray-300" required x-model="selected">
                                    <option value="">- pilih -</option>
                                    @foreach($products as $p)
                                        <option value="{{ $p->id }}">{{ $p->name }} (Rp {{ number_format($p->price, 0, ',', '.') }})</option>
                                    @endforeach
                                </select>
                                @error('product_id')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Harga per item (Rp) (opsional)</label>
                                <input name="unit_price" type="number" min="0" class="mt-1 w-full rounded-xl border-gray-300" :placeholder="selected ? ('Default: ' + (productPrices[selected] || 0)) : 'Default ikut harga produk'" />
                                @error('unit_price')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="grid sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-sm font-medium">Qty</label>
                                <input name="qty" type="number" min="1" value="{{ old('qty', 1) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                                @error('qty')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Sort order</label>
                                <input name="sort_order" type="number" min="0" value="{{ old('sort_order', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            </div>
                            <div class="flex items-end">
                                <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm font-semibold w-full">Tambah Item</button>
                            </div>
                        </div>
                    </form>

                    <div class="mt-5 grid gap-3">
                        @forelse($space->items as $it)
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold">{{ $it->product_name }}</div>
                                        <div class="text-xs text-gray-500">Qty {{ $it->qty }} · @ Rp {{ number_format($it->unit_price, 0, ',', '.') }} · Line Rp {{ number_format($it->line_total, 0, ',', '.') }}</div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <form method="POST" action="{{ route('admin.reservation-spaces.items.update', [$space, $it]) }}" class="flex items-end gap-2">
                                            @csrf
                                            @method('PUT')
                                            <div>
                                                <label class="text-[11px] text-gray-500">Qty</label>
                                                <input name="qty" type="number" min="1" value="{{ $it->qty }}" class="w-24 rounded-xl border-gray-300" />
                                            </div>
                                            <div>
                                                <label class="text-[11px] text-gray-500">Harga</label>
                                                <input name="unit_price" type="number" min="0" value="{{ $it->unit_price }}" class="w-32 rounded-xl border-gray-300" />
                                            </div>
                                            <div>
                                                <label class="text-[11px] text-gray-500">Sort</label>
                                                <input name="sort_order" type="number" min="0" value="{{ $it->sort_order }}" class="w-24 rounded-xl border-gray-300" />
                                            </div>
                                            <button class="px-3 py-2 rounded-xl border bg-white text-xs font-semibold">Update</button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.reservation-spaces.items.destroy', [$space, $it]) }}" onsubmit="return confirm('Hapus item ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="px-3 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 text-xs font-semibold">Hapus</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Belum ada item paket.</div>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
