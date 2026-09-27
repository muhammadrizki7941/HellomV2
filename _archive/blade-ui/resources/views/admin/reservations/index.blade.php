@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Reservation> $reservations */
    /** @var string $status */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reservations</h2>
        <a href="{{ route('admin.reservation-spaces.index') }}" class="px-3 py-2 rounded-xl border bg-white text-sm font-semibold">Kelola Space</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        @php
                            $filters = ['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
                        @endphp
                        @foreach($filters as $k => $label)
                            <a href="{{ route('admin.reservations.index', ['status' => $k]) }}"
                               class="px-3 py-2 rounded-full border {{ $status === $k ? 'bg-slate-900 text-white border-slate-900' : 'bg-white' }}">{{ $label }}</a>
                        @endforeach
                    </div>

                    <div class="mt-5 grid gap-3">
                        @forelse($reservations as $r)
                            <a href="{{ route('admin.reservations.show', $r) }}" class="rounded-2xl border border-gray-200 p-4 hover:bg-slate-50">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="font-semibold">{{ $r->space_name }}</div>
                                        <div class="text-xs text-gray-500">{{ $r->customer_name }} · {{ $r->customer_phone }}</div>
                                        <div class="text-xs text-gray-500">{{ $r->scheduled_at?->format('d M Y H:i') }} · {{ $r->duration_minutes }} menit</div>
                                        @if((int)($r->menu_commitment_total ?? 0) > 0)
                                            <div class="mt-1 text-[11px] text-gray-600">Pre-order menu: Rp {{ number_format((int)$r->menu_commitment_total, 0, ',', '.') }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500">Total</div>
                                        <div class="font-semibold">Rp {{ number_format($r->total_price, 0, ',', '.') }}</div>
                                        <div class="mt-1 inline-flex px-3 py-1 rounded-full text-xs font-semibold border
                                            {{ $r->status === 'pending' ? 'bg-amber-50 border-amber-200 text-amber-800' : '' }}
                                            {{ $r->status === 'confirmed' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : '' }}
                                            {{ $r->status === 'completed' ? 'bg-slate-100 border-slate-200 text-slate-800' : '' }}
                                            {{ $r->status === 'cancelled' ? 'bg-rose-50 border-rose-200 text-rose-800' : '' }}
                                        ">
                                            {{ $r->status }}
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @empty
                            <div class="text-sm text-gray-500">Belum ada reservasi.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
