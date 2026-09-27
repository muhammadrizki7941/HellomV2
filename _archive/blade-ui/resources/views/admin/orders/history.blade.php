@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Order> $orders */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between mb-6">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Riwayat Order</h2>
        <a href="{{ route('admin.orders.index') }}" class="rounded-xl bg-gray-900 text-white px-4 py-2 font-semibold">Order Aktif</a>
    </div>
@endsection

@section('content')
    <!-- Konten utama -->
    @if(session('success'))
        <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Order #</th>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Customer</th>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Table</th>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Status</th>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Payment</th>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Total</th>
                    <th class="text-left text-xs font-semibold text-gray-600 px-4 py-3">Tanggal</th>
                    <th class="text-right text-xs font-semibold text-gray-600 px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($orders as $order)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $order->order_number }}</div>
                            <div class="text-xs text-gray-500">{{ $order->order_source ?? 'N/A' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $order->customer_name ?? 'N/A' }}</div>
                            @if($order->user)
                                <div class="text-xs text-gray-500">{{ $order->user->email }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            {{ $order->table_label ?? 'N/A' }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($order->status === \App\Models\Order::STATUS_COMPLETED)
                                <span class="rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">Completed</span>
                            @elseif($order->status === \App\Models\Order::STATUS_CANCELLED)
                                <span class="rounded-full bg-red-100 text-red-700 px-3 py-1 text-xs font-semibold">Cancelled</span>
                            @else
                                <span class="rounded-full bg-gray-100 text-gray-700 px-3 py-1 text-xs font-semibold">{{ ucfirst($order->status) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($order->payment_status === 'paid')
                                <span class="rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">Paid</span>
                            @else
                                <span class="rounded-full bg-amber-100 text-amber-700 px-3 py-1 text-xs font-semibold">Unpaid</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold">
                            Rp {{ number_format($order->total_amount, 0, ',', '.') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            {{ $order->created_at->format('d M Y H:i') }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.orders.show', $order->order_number) }}" class="rounded-xl border border-gray-300 px-3 py-2 text-sm font-semibold">Lihat</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">Belum ada riwayat order.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection