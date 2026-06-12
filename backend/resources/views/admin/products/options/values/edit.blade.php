@php
    /** @var \App\Models\Product $product */
    /** @var \App\Models\ProductOption $option */
    /** @var \App\Models\ProductOptionValue $value */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ubah Pilihan Add-on · {{ $product->name }} · {{ $option->name }}</h2>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.products.options.values.update', [$product, $option, $value]) }}" class="grid gap-4" x-data="valueFormPreview({
                        name: @js(old('name', $value->name)),
                        priceDelta: @js((int) old('price_delta', $value->price_delta)),
                        isActive: @js((bool) old('is_active', $value->is_active)),
                    })">
                        @csrf
                        @method('PUT')

                        <div>
                            <label class="text-sm font-medium">Nama Pilihan</label>
                            <input name="name" x-model="name" value="{{ old('name', $value->name) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Tambahan Harga (Rp)</label>
                            <input name="price_delta" x-model.number="priceDelta" type="number" min="0" value="{{ old('price_delta', $value->price_delta) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            @error('price_delta')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            <div class="text-xs text-gray-500 mt-1">Isi 0 jika tidak ada biaya tambahan.</div>
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" x-model="isActive" {{ old('is_active', $value->is_active) ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Aktif</span>
                        </label>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
                            <div class="font-semibold text-gray-900">Preview Pilihan</div>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="font-medium" x-text="name || 'Nama pilihan belum diisi'"></span>
                                <span class="font-semibold text-emerald-700">+Rp <span x-text="formatRp(priceDelta)"></span></span>
                            </div>
                            <div class="mt-1 text-xs text-gray-500">Status: <span x-text="isActive ? 'Aktif' : 'Nonaktif'"></span></div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Urutan Tampil</label>
                            <input name="sort_order" type="number" min="0" max="10000" value="{{ old('sort_order', $value->sort_order) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            @error('sort_order')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="flex items-center gap-3">
                            <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Simpan Perubahan</button>
                            <a href="{{ route('admin.products.options.index', $product) }}" class="text-sm text-gray-600">Kembali</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function valueFormPreview(initial) {
            return {
                name: initial?.name || '',
                priceDelta: Number(initial?.priceDelta || 0),
                isActive: !!initial?.isActive,
                formatRp(value) {
                    return new Intl.NumberFormat('id-ID').format(Number(value || 0));
                }
            }
        }
    </script>
@endsection
