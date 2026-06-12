@php
    /** @var \App\Models\Product $product */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tambah Grup Add-on · {{ $product->name }}</h2>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.products.options.store', $product) }}" class="grid gap-4" x-data="optionFormPreview({
                        name: @js(old('name', '')),
                        type: @js(old('type', 'single')),
                        isRequired: @js((bool) old('is_required', false)),
                        isActive: @js((bool) old('is_active', true)),
                    })">
                        @csrf

                        <div>
                            <label class="text-sm font-medium">Nama Grup Add-on</label>
                            <input name="name" x-model="name" value="{{ old('name') }}" class="mt-1 w-full rounded-xl border-gray-300" required placeholder="Contoh: Level Pedas" />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Tipe Pilihan</label>
                            <select name="type" x-model="type" class="mt-1 w-full rounded-xl border-gray-300" required>
                                <option value="single" {{ old('type')==='single' ? 'selected' : '' }}>Pilih satu (radio)</option>
                                <option value="multi" {{ old('type')==='multi' ? 'selected' : '' }}>Bisa pilih banyak (checkbox)</option>
                            </select>
                            @error('type')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_required" value="1" x-model="isRequired" {{ old('is_required') ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Wajib dipilih</span>
                        </label>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" x-model="isActive" {{ old('is_active', '1') ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Aktif</span>
                        </label>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
                            <div class="font-semibold text-gray-900">Preview Grup Add-on</div>
                            <div class="mt-2 text-gray-700">
                                <div><span class="text-gray-500">Nama:</span> <span class="font-semibold" x-text="name || 'Belum diisi'"></span></div>
                                <div><span class="text-gray-500">Tipe:</span> <span x-text="type === 'multi' ? 'Bisa pilih banyak' : 'Pilih satu'"></span></div>
                                <div><span class="text-gray-500">Wajib:</span> <span x-text="isRequired ? 'Ya' : 'Tidak'"></span></div>
                                <div><span class="text-gray-500">Status:</span> <span x-text="isActive ? 'Aktif' : 'Nonaktif'"></span></div>
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Urutan Tampil</label>
                            <input name="sort_order" type="number" min="0" max="10000" value="{{ old('sort_order', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            @error('sort_order')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="flex items-center gap-3">
                            <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Simpan Grup</button>
                            <a href="{{ route('admin.products.options.index', $product) }}" class="text-sm text-gray-600">Kembali</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function optionFormPreview(initial) {
            return {
                name: initial?.name || '',
                type: initial?.type || 'single',
                isRequired: !!initial?.isRequired,
                isActive: !!initial?.isActive,
            }
        }
    </script>
@endsection
