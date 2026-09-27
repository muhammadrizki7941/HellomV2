@php
    /** @var \App\Models\Reservation $reservation */
@endphp

<x-customer-layout>
    <x-slot name="headerRight">
        <a href="{{ route('customer.home') }}" class="text-xs font-semibold text-slate-600">Home</a>
    </x-slot>

    <div class="mt-2">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" style="border-radius: var(--button-radius)">
            <div class="text-lg font-semibold">Reservasi terkirim</div>
            <div class="mt-1 text-sm text-slate-600">Kami akan konfirmasi secepatnya. Simpan detail ini ya.</div>

            <div class="mt-4 grid gap-3">
                <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                    <div class="text-xs text-slate-500">Tempat</div>
                    <div class="font-semibold">{{ $reservation->space_name }}</div>
                    <div class="text-sm text-slate-600">{{ $reservation->scheduled_at?->format('d M Y H:i') }} · {{ $reservation->duration_minutes }} menit</div>
                </div>

                <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                    <div class="text-xs text-slate-500">Atas nama</div>
                    <div class="font-semibold">{{ $reservation->customer_name }}</div>
                    <div class="text-sm text-slate-600">{{ $reservation->customer_phone }}</div>
                </div>

                <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                    <div class="text-xs text-slate-500">Total</div>
                    <div class="text-xl font-extrabold">Rp {{ number_format($reservation->total_price, 0, ',', '.') }}</div>
                    <div class="mt-1 inline-flex px-3 py-1 rounded-full text-xs font-semibold border bg-amber-50 border-amber-200 text-amber-800">{{ $reservation->status }}</div>
                </div>

                @if(($reservation->menu_commitment_total ?? 0) > 0)
                    <div class="rounded-2xl border border-slate-200 p-4" style="border-radius: var(--button-radius)">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs text-slate-500">Pre-order menu</div>
                                <div class="text-sm font-semibold">Total Rp {{ number_format((int)($reservation->menu_commitment_total ?? 0), 0, ',', '.') }}</div>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2">
                            @foreach(($reservation->menu_order_snapshot ?? []) as $it)
                                <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3" style="border-radius: var(--button-radius)">
                                    <div>
                                        <div class="text-sm font-semibold">{{ $it['product_name'] ?? '-' }}</div>
                                        <div class="text-xs text-slate-600">Qty {{ $it['qty'] ?? 0 }} · @ Rp {{ number_format((int)($it['unit_price'] ?? 0), 0, ',', '.') }}</div>
                                    </div>
                                    <div class="text-sm font-semibold">Rp {{ number_format((int)($it['line_total'] ?? 0), 0, ',', '.') }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <a href="{{ route('reservation.index') }}" class="rounded-2xl px-5 py-4 font-extrabold text-white text-center shadow-sm" style="background: var(--primary-color); border-radius: var(--button-radius)">
                    Booking Tempat Lain
                </a>

                <a href="{{ route('order.page') }}" class="rounded-2xl px-5 py-4 font-extrabold border border-slate-200 bg-white text-slate-900 text-center" style="border-radius: var(--button-radius)">
                    Lanjut Pesan Menu
                </a>
            </div>
        </div>
    </div>
</x-customer-layout>
