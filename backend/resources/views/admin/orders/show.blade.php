@php
    /** @var \App\Models\Order $order */
    /** @var \App\Models\PaymentSetting $paymentSetting */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Order {{ $order->order_number }}</h2>
            <div class="mt-1 text-sm text-gray-500">Dibuat pada {{ $order->created_at->format('d M Y H:i') }}</div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.orders.index') }}" class="rounded-xl border bg-white text-sm font-semibold px-4 py-2">Kembali</a>
        </div>
    </div>
@endsection

@section('content')
    <!-- Konten utama -->
    @if(session('success'))
        <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if(session('status'))
        <div class="mb-4 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-blue-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Order Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Items -->
            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-6">
                <h3 class="text-lg font-semibold mb-4">Detail Order</h3>

                <div class="space-y-4">
                    @foreach($order->items as $item)
                        <div class="flex items-start justify-between py-3 border-b border-gray-100 last:border-b-0">
                            <div class="flex-1">
                                <div class="font-semibold">{{ $item->product_name }}</div>
                                <div class="text-sm text-gray-600">Qty: {{ $item->quantity }}</div>
                                @if($item->notes)
                                    <div class="text-sm text-gray-500 mt-1">Catatan: {{ $item->notes }}</div>
                                @endif
                                @if($item->options && count($item->options) > 0)
                                    <div class="text-sm text-gray-500 mt-1">
                                        Options: {{ collect($item->options)->pluck('name')->join(', ') }}
                                    </div>
                                @endif
                            </div>
                            <div class="text-right">
                                <div class="font-semibold">Rp {{ number_format($item->total_price, 0, ',', '.') }}</div>
                                <div class="text-sm text-gray-500">@ Rp {{ number_format($item->unit_price, 0, ',', '.') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Order Summary -->
                <div class="mt-6 pt-4 border-t border-gray-200">
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span>Subtotal</span>
                            <span>Rp {{ number_format($order->total_amount + $order->discount_amount, 0, ',', '.') }}</span>
                        </div>
                        @if($order->discount_amount > 0)
                            <div class="flex justify-between text-sm text-emerald-600">
                                <span>Diskon</span>
                                <span>- Rp {{ number_format($order->discount_amount, 0, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between font-semibold text-lg pt-2 border-t border-gray-200">
                            <span>Total</span>
                            <span>Rp {{ number_format($order->total_amount, 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Info & Actions -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-6">
                <h3 class="text-lg font-semibold mb-4">Informasi Customer</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium text-gray-600">Nama</label>
                        <div class="mt-1 text-sm">{{ $order->customer_name ?? 'N/A' }}</div>
                    </div>
                    @if($order->user)
                        <div>
                            <label class="text-sm font-medium text-gray-600">Email</label>
                            <div class="mt-1 text-sm">{{ $order->user->email }}</div>
                        </div>
                    @endif
                    <div>
                        <label class="text-sm font-medium text-gray-600">Meja</label>
                        <div class="mt-1 text-sm">{{ $order->table_label ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-600">Sumber Order</label>
                        <div class="mt-1 text-sm">{{ $order->order_source ?? 'N/A' }}</div>
                    </div>
                </div>
            </div>

            <!-- Status & Payment -->
            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-6">
                <h3 class="text-lg font-semibold mb-4">Status & Pembayaran</h3>
                <div class="space-y-4">
                    <div>
                        <label class="text-sm font-medium text-gray-600">Status Order</label>
                        <div class="mt-1">
                            @if($order->status === \App\Models\Order::STATUS_NEW)
                                <span class="rounded-full bg-blue-100 text-blue-700 px-3 py-1 text-xs font-semibold">New</span>
                            @elseif($order->status === \App\Models\Order::STATUS_ACCEPTED)
                                <span class="rounded-full bg-yellow-100 text-yellow-700 px-3 py-1 text-xs font-semibold">Accepted</span>
                            @elseif($order->status === \App\Models\Order::STATUS_PREPARING)
                                <span class="rounded-full bg-orange-100 text-orange-700 px-3 py-1 text-xs font-semibold">Preparing</span>
                            @elseif($order->status === \App\Models\Order::STATUS_COMPLETED)
                                <span class="rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">Completed</span>
                            @elseif($order->status === \App\Models\Order::STATUS_CANCELLED)
                                <span class="rounded-full bg-red-100 text-red-700 px-3 py-1 text-xs font-semibold">Cancelled</span>
                            @else
                                <span class="rounded-full bg-gray-100 text-gray-700 px-3 py-1 text-xs font-semibold">{{ ucfirst($order->status) }}</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-gray-600">Status Pembayaran</label>
                        <div class="mt-1">
                            @if($order->payment_status === 'paid')
                                <span class="rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">Paid</span>
                            @else
                                <span class="rounded-full bg-amber-100 text-amber-700 px-3 py-1 text-xs font-semibold">Unpaid</span>
                            @endif
                        </div>
                    </div>

                    @if($order->payment_method)
                        <div>
                            <label class="text-sm font-medium text-gray-600">Metode Pembayaran</label>
                            <div class="mt-1 text-sm">{{ $order->payment_method }}</div>
                        </div>
                    @endif

                    @if($order->payment_ref)
                        <div>
                            <label class="text-sm font-medium text-gray-600">Referensi Pembayaran</label>
                            <div class="mt-1 text-sm">{{ $order->payment_ref }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Actions -->
            @if($order->status !== \App\Models\Order::STATUS_COMPLETED && $order->status !== \App\Models\Order::STATUS_CANCELLED)
                <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold mb-4">Aksi</h3>
                    <div class="space-y-3">
                        @if($order->status === \App\Models\Order::STATUS_NEW)
                            <form method="POST" action="{{ route('admin.orders.status', $order->order_number) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ \App\Models\Order::STATUS_ACCEPTED }}">
                                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg">
                                    Terima Order
                                </button>
                            </form>
                        @endif

                        @if($order->status === \App\Models\Order::STATUS_ACCEPTED)
                            <form method="POST" action="{{ route('admin.orders.status', $order->order_number) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ \App\Models\Order::STATUS_PREPARING }}">
                                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded-lg">
                                    Mulai Persiapan
                                </button>
                            </form>
                        @endif

                        @if($order->status === \App\Models\Order::STATUS_PREPARING)
                            <form method="POST" action="{{ route('admin.orders.status', $order->order_number) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ \App\Models\Order::STATUS_COMPLETED }}">
                                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-lg">
                                    Selesai
                                </button>
                            </form>
                        @endif

                        @if($order->payment_status !== 'paid')
                            <form method="POST" action="{{ route('admin.orders.payment-status', $order->order_number) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="payment_status" value="paid">
                                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-lg">
                                    Tandai Sudah Bayar
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('admin.orders.status', $order->order_number) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ \App\Models\Order::STATUS_CANCELLED }}">
                            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg"
                                    onclick="return confirm('Apakah Anda yakin ingin membatalkan order ini?')">
                                Batalkan Order
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection