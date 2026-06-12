@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ReservationSpace> $spaces */
@endphp

<x-customer-layout>
    <x-slot name="headerRight">
        <div class="text-right">
            <div class="text-xs text-slate-500">Booking</div>
            <div class="font-semibold text-slate-900">Reservasi</div>
        </div>
    </x-slot>

    <div class="mt-2">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" style="border-radius: var(--button-radius)">
            <div class="text-lg font-semibold">Pilih Tempat</div>
            <div class="mt-1 text-sm text-slate-600">Lihat foto, paket menu termasuk, lalu booking jadwal.</div>
        </div>

        <div class="mt-4 grid gap-3">
            @forelse($spaces as $s)
                <a href="{{ route('reservation.show', $s) }}" class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden block" style="border-radius: var(--button-radius)">
                    <div class="bg-slate-50">
                        @if($s->coverImageUrl())
                            <img src="{{ $s->coverImageUrl() }}" alt="" class="h-44 w-full object-cover" />
                        @else
                            <div class="h-44 w-full grid place-items-center text-slate-500">Tidak ada foto</div>
                        @endif
                    </div>
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-base font-semibold">{{ $s->name }}</div>
                                <div class="text-xs text-slate-600">
                                    @if($s->location) {{ $s->location }} @endif
                                    @if($s->capacity) · Kapasitas {{ $s->capacity }} @endif
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                                    <span class="px-2 py-1 rounded-full border {{ $s->rent_enabled ? 'bg-slate-100 border-slate-200 text-slate-700' : 'bg-emerald-50 border-emerald-200 text-emerald-800' }}">
                                        {{ $s->rent_enabled ? 'Sewa berbayar' : 'Sewa gratis' }}
                                    </span>
                                    @if(($s->min_menu_total ?? 0) > 0)
                                        <span class="px-2 py-1 rounded-full border bg-amber-50 border-amber-200 text-amber-800">
                                            Min order Rp {{ number_format($s->min_menu_total, 0, ',', '.') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-slate-500">Mulai</div>
                                <div class="text-sm font-extrabold">Rp {{ number_format($s->total_price, 0, ',', '.') }}</div>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-2xl border border-slate-200 bg-white p-3" style="border-radius: var(--button-radius)">
                                <div class="text-slate-500">Sewa</div>
                                <div class="font-semibold">Rp {{ number_format($s->rent_price, 0, ',', '.') }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-3" style="border-radius: var(--button-radius)">
                                <div class="text-slate-500">Menu</div>
                                <div class="font-semibold">Rp {{ number_format($s->items_total, 0, ',', '.') }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-3" style="border-radius: var(--button-radius)">
                                <div class="text-slate-500">Total</div>
                                <div class="font-extrabold">Rp {{ number_format($s->total_price, 0, ',', '.') }}</div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="inline-flex items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold text-white" style="background: var(--primary-color); border-radius: var(--button-radius)">
                                Lihat Detail & Booking
                                <span aria-hidden="true">→</span>
                            </div>
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-600" style="border-radius: var(--button-radius)">
                    Belum ada tempat reservasi yang aktif.
                </div>
            @endforelse
        </div>
    </div>
</x-customer-layout>
