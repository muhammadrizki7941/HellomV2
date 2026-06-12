@php
    /** @var \App\Models\Product $product */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Add-on Produk</h2>
            <div class="text-xs text-gray-500">{{ $product->name }}</div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.products.edit', $product) }}" class="inline-flex items-center rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold">Kembali ke Produk</a>
            <a href="{{ route('admin.products.options.create', $product) }}" class="inline-flex items-center rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Tambah Grup Add-on</a>
        </div>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-4 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-900">
                        <div class="font-semibold">Alur singkat yang konsisten</div>
                        <div class="mt-1">1) Buat <strong>Grup Add-on</strong> (contoh: Level Pedas) → 2) Tambah <strong>Pilihan</strong> (contoh: Pedas Sedang, +Rp 0) → 3) Aktifkan/nonaktifkan sesuai kebutuhan.</div>
                    </div>

                    @if(session('status'))
                        <div class="mb-4 text-sm text-emerald-700">{{ session('status') }}</div>
                    @endif

                    <div class="grid gap-4">
                        @forelse($product->options as $opt)
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-900">
                                            {{ $opt->name }}
                                            @if(!$opt->is_active)
                                                <span class="ml-2 text-xs font-semibold text-gray-500">(nonaktif)</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            Tipe: <span class="font-mono">{{ $opt->type === 'multi' ? 'bisa pilih banyak' : 'pilih satu' }}</span>
                                            · Wajib: <span class="font-mono">{{ $opt->is_required ? 'ya' : 'tidak' }}</span>
                                            · Urutan: <span class="font-mono">{{ $opt->sort_order }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('admin.products.options.values.create', [$product, $opt]) }}" class="rounded-xl border border-gray-300 px-3 py-2 text-xs font-semibold">Tambah Pilihan</a>
                                        <a href="{{ route('admin.products.options.edit', [$product, $opt]) }}" class="rounded-xl border border-gray-300 px-3 py-2 text-xs font-semibold">Ubah</a>
                                        <form method="POST" action="{{ route('admin.products.options.destroy', [$product, $opt]) }}" onsubmit="return confirm('Hapus grup add-on ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-xl border border-red-200 text-red-700 px-3 py-2 text-xs font-semibold">Hapus</button>
                                        </form>
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-2">
                                    @forelse($opt->values as $val)
                                        <div class="flex items-center justify-between rounded-xl bg-gray-50 border border-gray-100 px-3 py-2 text-sm">
                                            <div>
                                                <span class="font-medium">{{ $val->name }}</span>
                                                @if(!$val->is_active)
                                                    <span class="ml-2 text-xs font-semibold text-gray-500">(nonaktif)</span>
                                                @endif
                                                <span class="ml-2 text-xs text-gray-500">{{ (int)$val->price_delta >= 0 ? '+' : '-' }}Rp {{ number_format(abs((int)$val->price_delta), 0, ',', '.') }}</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('admin.products.options.values.edit', [$product, $opt, $val]) }}" class="rounded-xl border border-gray-300 px-3 py-1.5 text-xs font-semibold">Ubah</a>
                                                <form method="POST" action="{{ route('admin.products.options.values.destroy', [$product, $opt, $val]) }}" onsubmit="return confirm('Hapus pilihan add-on ini?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded-xl border border-red-200 text-red-700 px-3 py-1.5 text-xs font-semibold">Hapus</button>
                                                </form>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="text-sm text-gray-500">Belum ada pilihan. Tambahkan pilihan agar pelanggan bisa memilih add-on ini.</div>
                                    @endforelse
                                </div>
                            </div>
                        @empty
                            <div class="text-sm text-gray-600">Belum ada grup add-on untuk produk ini.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
