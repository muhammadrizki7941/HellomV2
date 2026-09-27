@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tambah Reservation Space</h2>
        <a href="{{ route('admin.reservation-spaces.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Kembali</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    @if($errors->any())
                        <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                            <div class="font-semibold">Periksa input</div>
                            <ul class="list-disc pl-5 mt-2 text-sm">
                                @foreach($errors->all() as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.reservation-spaces.store') }}" class="grid gap-4" enctype="multipart/form-data">
                        @csrf

                        <div>
                            <label class="text-sm font-medium">Nama</label>
                            <input name="name" value="{{ old('name') }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium">Lokasi (opsional)</label>
                                <input name="location" value="{{ old('location') }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: Lantai 2 - Indoor" />
                                @error('location')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Kapasitas (opsional)</label>
                                <input name="capacity" type="number" min="1" value="{{ old('capacity') }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: 10" />
                                @error('capacity')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Deskripsi</label>
                            <textarea name="description" rows="4" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Deskripsi singkat, fasilitas, aturan, dll.">{{ old('description') }}</textarea>
                            @error('description')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium">Biaya sewa (Rp)</label>
                                <input name="rent_price" type="number" min="0" value="{{ old('rent_price', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                                @error('rent_price')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Sort order</label>
                                <input name="sort_order" type="number" min="0" value="{{ old('sort_order', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                @error('sort_order')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mt-1 rounded-2xl border border-gray-200 p-4 grid gap-3">
                            <div class="text-sm font-semibold">Aturan Reservasi</div>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="rent_enabled" value="1" {{ old('rent_enabled', 1) ? 'checked' : '' }} class="rounded border-gray-300" />
                                <span class="text-sm">Aktifkan biaya sewa (jika off → sewa = Rp 0 / free)</span>
                            </label>
                            <div>
                                <label class="text-sm font-medium">Minimal order menu (Rp) (opsional)</label>
                                <input name="min_menu_total" type="number" min="0" value="{{ old('min_menu_total', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: 1000000" />
                                @error('min_menu_total')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                <div class="text-xs text-gray-500 mt-1">Jika diisi, customer wajib mengisi komitmen order menu minimal sebesar ini untuk bisa submit reservasi.</div>
                            </div>
                        </div>

                        <div class="mt-1 rounded-2xl border border-gray-200 p-4 grid gap-3">
                            <div class="text-sm font-semibold">Galeri Foto Space (langsung saat create)</div>
                            <div class="text-xs text-gray-500">Upload banyak foto sekaligus agar setelah simpan data sudah lengkap tanpa perlu masuk menu edit.</div>

                            <div>
                                <label class="text-sm font-medium">Upload Foto</label>
                                <input name="images[]" type="file" accept="image/*" multiple class="mt-1 w-full" />
                                @error('images')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                @error('images.*')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                            </div>

                            <div class="grid sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm font-medium">Caption foto (opsional)</label>
                                    <input name="image_caption" value="{{ old('image_caption') }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Contoh: Area outdoor view malam" />
                                    @error('image_caption')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Sort order awal foto</label>
                                    <input name="image_sort_order" type="number" min="0" value="{{ old('image_sort_order', 0) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                                    @error('image_sort_order')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', 1) ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Active</span>
                        </label>

                        <div class="flex items-center gap-2 pt-2">
                            <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm font-semibold">Simpan</button>
                            <a href="{{ route('admin.reservation-spaces.index') }}" class="px-4 py-2 rounded-xl border text-sm font-semibold">Batal</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
