@php
    $tenantId = null;
    if (app()->bound(\App\Services\Tenancy\TenantContext::class)) {
        $tenantContext = app(\App\Services\Tenancy\TenantContext::class);
        $tenantId = $tenantContext->slug;
    }
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ route('admin.kitchen.index') }}"
               class="flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Kembali ke Dapur
            </a>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pesanan #{{ $order->order_number }}</h2>
        </div>
        <div class="flex items-center gap-3">
            <span class="px-3 py-1 rounded-full text-sm font-semibold
                @if($order->status === 'accepted') bg-amber-100 text-amber-800
                @elseif($order->status === 'preparing') bg-blue-100 text-blue-800
                @elseif($order->status === 'completed') bg-green-100 text-green-800
                @else bg-gray-100 text-gray-800 @endif">
                {{ ucfirst($order->status) }}
            </span>
            <div class="text-xs text-gray-500">{{ $order->customer_name }} • {{ $order->created_at->format('d M Y H:i') }}</div>
        </div>
    </div>
@endsection

@section('content')
    <div class="p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Item Pesanan -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Informasi Pesanan -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Informasi Pesanan</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-semibold text-gray-600">Pelanggan</label>
                            <p class="text-gray-900">{{ $order->customer_name }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-gray-600">Tipe Layanan</label>
                            <p class="text-gray-900">{{ $order->service_type === 'dine_in' ? 'Makan di Tempat' : ($order->service_type === 'takeaway' ? 'Bungkus' : ucfirst($order->service_type)) }}</p>
                        </div>
                        @if($order->table)
                        <div>
                            <label class="text-sm font-semibold text-gray-600">Meja</label>
                            <p class="text-gray-900">{{ $order->table->label }}</p>
                        </div>
                        @endif
                        <div>
                            <label class="text-sm font-semibold text-gray-600">Status Pembayaran</label>
                            <p class="text-gray-900">{{ $order->payment_status === 'paid' ? 'Sudah Dibayar' : 'Belum Dibayar' }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-gray-600">Waktu Pesanan</label>
                            <p class="text-gray-900">{{ $order->created_at->format('d M Y H:i:s') }}</p>
                        </div>
                        @if($order->notes)
                        <div class="col-span-2">
                            <label class="text-sm font-semibold text-gray-600">Catatan</label>
                            <p class="text-gray-900 bg-gray-50 p-3 rounded-lg">{{ $order->notes }}</p>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Item Pesanan -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-bold text-gray-900">Daftar Item</h3>
                    </div>

                    <div class="divide-y divide-gray-100">
                        @foreach($order->items as $item)
                        <div class="p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <span class="text-lg font-bold text-gray-900">{{ $item->qty }}x</span>
                                        <div>
                                            <h4 class="font-semibold text-gray-900">{{ $item->product->name }}</h4>
                                            @if($item->options_label)
                                            <p class="text-sm text-gray-600 mt-1">{{ $item->options_label }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="text-sm text-gray-500">
                                        Harga Satuan: Rp {{ number_format($item->unit_price, 0, ',', '.') }}
                                    </div>
                                </div>

                                <div class="text-right">
                                    <div class="text-lg font-bold text-gray-900">
                                        Rp {{ number_format($item->unit_price * $item->qty, 0, ',', '.') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                        <div class="flex items-center justify-between">
                            <span class="text-lg font-bold text-gray-900">Total</span>
                            <span class="text-xl font-bold text-gray-900">
                                Rp {{ number_format($order->items->sum(function($item) { return $item->unit_price * $item->qty; }), 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel Aksi -->
            <div class="space-y-6">

                <!-- Ubah Status -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Ubah Status</h3>

                    <div class="space-y-3">
                        @if($order->status === 'accepted')
                        <button onclick="updateStatus('preparing')"
                                class="w-full px-4 py-3 bg-emerald-500 text-white font-semibold rounded-lg hover:bg-emerald-600 transition">
                            <div class="flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                Mulai Proses
                            </div>
                        </button>
                        @elseif($order->status === 'preparing')
                        <button onclick="updateStatus('completed')"
                                class="w-full px-4 py-3 bg-green-500 text-white font-semibold rounded-lg hover:bg-green-600 transition">
                            <div class="flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Tandai Selesai
                            </div>
                        </button>
                        @endif
                    </div>
                </div>

                <!-- Aksi Cepat -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Aksi Cepat</h3>

                    <div class="space-y-3">
                        <a href="{{ route('admin.orders.show', $order->order_number) }}"
                           class="flex items-center gap-3 w-full px-4 py-3 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            Lihat Detail Lengkap
                        </a>

                        <button onclick="printOrder()"
                               class="flex items-center gap-3 w-full px-4 py-3 bg-gray-50 text-gray-700 rounded-lg hover:bg-gray-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Cetak Pesanan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

<script>
function updateStatus(newStatus) {
    const statusText = newStatus === 'preparing' ? 'mulai memproses' : 'menandai selesai';
    if (!confirm(`Yakin ingin ${statusText} pesanan ini?`)) return;

    // Extract tenant from current URL path
    const pathParts = window.location.pathname.split('/');
    const tenantIndex = pathParts.indexOf('t');
    const tenant = tenantIndex !== -1 && pathParts.length > tenantIndex + 1 ? pathParts[tenantIndex + 1] : 'demo';

    fetch(`/t/${tenant}/admin/kitchen/orders/{{ $order->order_number }}/status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({ status: newStatus })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Gagal memperbarui status pesanan');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui status pesanan');
    });
}

function startPreparation(orderNumber) {
    updateStatus('preparing');
}

function completeOrder(orderNumber) {
    updateStatus('completed');
}

function printOrder() {
    window.print();
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: white !important; }
}
</style>

@endsection