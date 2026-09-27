@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ReservationSpace> $spaces */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reservation Spaces</h2>
        <a href="{{ route('admin.reservation-spaces.create') }}" class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm font-semibold">Tambah</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="grid gap-3">
                        @forelse($spaces as $s)
                            <div class="rounded-2xl border border-gray-200 p-4 flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <div class="font-semibold">{{ $s->name }}</div>
                                    <div class="text-xs text-gray-500">
                                        <span class="px-2 py-1 rounded-full border {{ $s->is_active ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' }}">
                                            {{ $s->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                        <span class="ml-2">Slug: {{ $s->slug }}</span>
                                        @if($s->location)
                                            <span class="ml-2">· {{ $s->location }}</span>
                                        @endif
                                        @if($s->capacity)
                                            <span class="ml-2">· Kapasitas {{ $s->capacity }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">Sewa: Rp {{ number_format($s->rent_price, 0, ',', '.') }} · Sort: {{ $s->sort_order }}</div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.reservation-spaces.edit', $s) }}" class="px-3 py-2 rounded-xl border bg-white text-xs font-semibold">Edit</a>
                                    <form method="POST" action="{{ route('admin.reservation-spaces.destroy', $s) }}" onsubmit="return confirm('Hapus space ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="px-3 py-2 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 text-xs font-semibold">Hapus</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Belum ada space reservasi.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
