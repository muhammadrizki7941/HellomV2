@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $promos */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Promo / Event (Customer)</h2>
        <a href="{{ route('admin.site-promotions.create') }}" class="inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Tambah</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
                <div class="p-5">
                    <div class="text-sm text-gray-600">Promo/Event yang tampil di tab Promo customer. Bisa diarahkan ke link apa saja.</div>
                </div>

                <div class="divide-y divide-gray-100">
                    @forelse($promos as $promo)
                        <div class="p-5 flex items-start justify-between gap-4">
                            <div class="flex items-start gap-4">
                                <div class="h-16 w-24 rounded-xl overflow-hidden bg-gray-100 border border-gray-200 grid place-items-center">
                                    @if($promo->thumbnailUrl())
                                        <img src="{{ $promo->thumbnailUrl() }}" class="h-full w-full object-cover" alt="thumb" onerror="window.__imgRetry && window.__imgRetry(this)" />
                                    @else
                                        <div class="text-xs text-gray-400">No image</div>
                                    @endif
                                </div>

                                <div>
                                    <div class="flex items-center gap-2">
                                        <div class="font-semibold text-gray-900">{{ $promo->title }}</div>
                                        @if($promo->is_active)
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Aktif</span>
                                        @else
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-600 border border-gray-200">Nonaktif</span>
                                        @endif
                                    </div>
                                    @if($promo->description)
                                        <div class="mt-1 text-sm text-gray-600 line-clamp-2">{{ $promo->description }}</div>
                                    @endif
                                    <div class="mt-2 text-xs text-gray-500">
                                        Slug: <span class="font-mono">{{ $promo->slug }}</span>
                                        @if($promo->starts_at)
                                            · Mulai: {{ $promo->starts_at->format('d/m/Y H:i') }}
                                        @endif
                                        @if($promo->ends_at)
                                            · Selesai: {{ $promo->ends_at->format('d/m/Y H:i') }}
                                        @endif
                                    </div>
                                    @if($promo->linkHref())
                                        <div class="mt-2">
                                            <a class="text-xs text-indigo-700 underline" href="{{ $promo->linkHref() }}" rel="noreferrer">Buka Link</a>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.site-promotions.edit', $promo) }}" class="rounded-xl border border-gray-300 px-3 py-2 text-sm font-semibold">Edit</a>
                                <form method="POST" action="{{ route('admin.site-promotions.destroy', $promo) }}" onsubmit="return confirm('Hapus promo ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">Hapus</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-gray-500">Belum ada promo/event.</div>
                    @endforelse
                </div>

                @if($promos->hasPages())
                    <div class="p-4 border-t border-gray-100">
                        {{ $promos->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
