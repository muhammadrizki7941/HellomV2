@php
    /** @var \App\Models\Reservation $reservation */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reservation Detail</h2>
        <a href="{{ route('admin.reservations.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Kembali</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 grid gap-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="text-lg font-semibold">{{ $reservation->space_name }}</div>
                            <div class="text-sm text-gray-600">{{ $reservation->scheduled_at?->format('d M Y H:i') }} · {{ $reservation->duration_minutes }} menit</div>
                            @if($reservation->guests_count)
                                <div class="text-sm text-gray-600">Tamu: {{ $reservation->guests_count }}</div>
                            @endif
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-500">Total</div>
                            <div class="text-xl font-extrabold">Rp {{ number_format($reservation->total_price, 0, ',', '.') }}</div>
                            <div class="mt-1 inline-flex px-3 py-1 rounded-full text-xs font-semibold border
                                {{ $reservation->status === 'pending' ? 'bg-amber-50 border-amber-200 text-amber-800' : '' }}
                                {{ $reservation->status === 'confirmed' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : '' }}
                                {{ $reservation->status === 'completed' ? 'bg-slate-100 border-slate-200 text-slate-800' : '' }}
                                {{ $reservation->status === 'cancelled' ? 'bg-rose-50 border-rose-200 text-rose-800' : '' }}
                            ">
                                {{ $reservation->status }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid sm:grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Customer</div>
                            <div class="mt-2 text-sm">Nama: <span class="font-semibold">{{ $reservation->customer_name }}</span></div>
                            <div class="text-sm">HP: <span class="font-semibold">{{ $reservation->customer_phone }}</span></div>
                            @if($reservation->customer_email)
                                <div class="text-sm">Email: <span class="font-semibold">{{ $reservation->customer_email }}</span></div>
                            @endif
                            @if($reservation->notes)
                                <div class="mt-2 text-sm text-gray-600">Catatan: {{ $reservation->notes }}</div>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold">Rincian Harga</div>
                            <div class="mt-2 text-sm text-gray-700 flex items-center justify-between"><span>Sewa</span><span>Rp {{ number_format($reservation->rent_price, 0, ',', '.') }}</span></div>
                            <div class="text-sm text-gray-700 flex items-center justify-between"><span>Menu termasuk</span><span>Rp {{ number_format($reservation->items_total, 0, ',', '.') }}</span></div>
                            <div class="text-sm text-gray-700 flex items-center justify-between"><span>Komitmen order menu</span><span>Rp {{ number_format($reservation->menu_commitment_total ?? 0, 0, ',', '.') }}</span></div>
                            <div class="mt-2 pt-2 border-t text-sm font-semibold flex items-center justify-between"><span>Total</span><span>Rp {{ number_format($reservation->total_price, 0, ',', '.') }}</span></div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-gray-200 p-4">
                        <div class="text-sm font-semibold">Paket Menu (Snapshot)</div>
                        <div class="mt-2 grid gap-2">
                            @forelse(($reservation->items_snapshot ?? []) as $it)
                                <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3">
                                    <div>
                                        <div class="text-sm font-semibold">{{ $it['product_name'] ?? '-' }}</div>
                                        <div class="text-xs text-gray-500">Qty {{ $it['qty'] ?? 0 }} · @ Rp {{ number_format((int)($it['unit_price'] ?? 0), 0, ',', '.') }}</div>
                                    </div>
                                    <div class="text-sm font-semibold">Rp {{ number_format((int)($it['line_total'] ?? 0), 0, ',', '.') }}</div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">(Tidak ada item snapshot)</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold">Pre-Order Menu (Customer)</div>
                                <div class="text-xs text-gray-500">Menu yang dipilih customer saat booking.</div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">Total</div>
                                <div class="text-sm font-semibold">Rp {{ number_format((int)($reservation->menu_commitment_total ?? 0), 0, ',', '.') }}</div>
                            </div>
                        </div>

                        <div class="mt-2 grid gap-2">
                            @forelse(($reservation->menu_order_snapshot ?? []) as $it)
                                <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3">
                                    <div>
                                        <div class="text-sm font-semibold">{{ $it['product_name'] ?? '-' }}</div>
                                        <div class="text-xs text-gray-500">Qty {{ $it['qty'] ?? 0 }} · @ Rp {{ number_format((int)($it['unit_price'] ?? 0), 0, ',', '.') }}</div>
                                    </div>
                                    <div class="text-sm font-semibold">Rp {{ number_format((int)($it['line_total'] ?? 0), 0, ',', '.') }}</div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">(Tidak ada pre-order menu)</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-gray-200 p-4">
                        <div class="text-sm font-semibold">Update Status</div>
                        <form method="POST" action="{{ route('admin.reservations.status', $reservation) }}" class="mt-3 grid gap-3">
                            @csrf
                            @method('PATCH')
                            <div class="grid sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm font-medium">Status</label>
                                    <select name="status" class="mt-1 w-full rounded-xl border-gray-300" required>
                                        @foreach(['pending','confirmed','completed','cancelled'] as $st)
                                            <option value="{{ $st }}" {{ $reservation->status === $st ? 'selected' : '' }}>{{ $st }}</option>
                                        @endforeach
                                    </select>
                                    @error('status')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Admin notes</label>
                                    <input name="admin_notes" value="{{ old('admin_notes', $reservation->admin_notes) }}" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Opsional" />
                                    @error('admin_notes')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm font-semibold w-fit">Simpan</button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
