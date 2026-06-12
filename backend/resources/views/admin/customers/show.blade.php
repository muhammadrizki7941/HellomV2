@extends('layouts.admin-sidebar')

@section('title', 'Detail Customer')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <a href="{{ route('admin.customers.index') }}"
                   class="mr-4 text-gray-400 hover:text-gray-600">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $customer->name }}</h1>
                    <p class="mt-1 text-sm text-gray-600">Detail informasi customer</p>
                </div>
            </div>
            <div class="flex space-x-3">
                <a href="{{ route('admin.customers.edit', $customer) }}"
                   class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    Edit
                </a>
                <form method="POST" action="{{ route('admin.customers.toggle-status', $customer) }}"
                      class="inline" onsubmit="return confirm('Yakin ingin mengubah status customer ini?')">
                    @csrf
                    @method('POST')
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 border border-orange-300 shadow-sm text-sm font-medium rounded-md text-orange-700 bg-white hover:bg-orange-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                        Toggle Status
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Customer Info -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Informasi Customer</h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <!-- Avatar -->
                    <div class="flex items-center">
                        <div class="flex-shrink-0 h-16 w-16">
                            <div class="h-16 w-16 rounded-full bg-gray-300 flex items-center justify-center">
                                <span class="text-xl font-medium text-gray-700">
                                    {{ substr($customer->name, 0, 1) }}
                                </span>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h4 class="text-lg font-medium text-gray-900">{{ $customer->name }}</h4>
                            <p class="text-sm text-gray-500">ID: {{ $customer->id }}</p>
                        </div>
                    </div>

                    <!-- Contact Info -->
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email</label>
                            <p class="text-sm text-gray-900">{{ $customer->email }}</p>
                        </div>
                        @if($customer->phone)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Telepon</label>
                            <p class="text-sm text-gray-900">{{ $customer->phone }}</p>
                        </div>
                        @endif
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Role</label>
                            <p class="text-sm text-gray-900">{{ ucfirst($customer->role) }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Saldo Points</label>
                            <p class="text-sm text-gray-900">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    {{ $customer->points_balance > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    {{ number_format($customer->points_balance) }} points
                                </span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Status</label>
                            @php
                                $isActive = $customer->points_balance > 0 ||
                                           $customer->orders()->where('created_at', '>=', now()->subDays(30))->exists();
                            @endphp
                            <p class="text-sm">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    {{ $isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    {{ $isActive ? 'Aktif' : 'Tidak Aktif' }}
                                </span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Terdaftar</label>
                            <p class="text-sm text-gray-900">{{ $customer->created_at->format('d F Y, H:i') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Terakhir Update</label>
                            <p class="text-sm text-gray-900">{{ $customer->updated_at->format('d F Y, H:i') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="bg-white rounded-lg shadow mt-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Statistik</h3>
                </div>
                <div class="px-6 py-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $customer->orders_count }}</div>
                            <div class="text-sm text-gray-500">Total Order</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600">{{ $customer->reservations_count }}</div>
                            <div class="text-sm text-gray-500">Reservasi</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Recent Orders -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Order Terbaru</h3>
                </div>
                <div class="px-6 py-4">
                    @if($customer->orders->count() > 0)
                    <div class="space-y-4">
                        @foreach($customer->orders as $order)
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 last:border-b-0">
                            <div>
                                <div class="text-sm font-medium text-gray-900">
                                    Order #{{ $order->order_number }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ $order->created_at->format('d/m/Y H:i') }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-medium text-gray-900">
                                    Rp {{ number_format($order->total_amount) }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ ucfirst($order->status) }}
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-sm text-gray-500 text-center py-4">Belum ada order</p>
                    @endif
                </div>
            </div>

            <!-- Recent Reservations -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Reservasi Terbaru</h3>
                </div>
                <div class="px-6 py-4">
                    @if($customer->reservations->count() > 0)
                    <div class="space-y-4">
                        @foreach($customer->reservations as $reservation)
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 last:border-b-0">
                            <div>
                                <div class="text-sm font-medium text-gray-900">
                                    Reservasi #{{ $reservation->id }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ $reservation->created_at->format('d/m/Y H:i') }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $reservation->party_size }} orang
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ ucfirst($reservation->status) }}
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-sm text-gray-500 text-center py-4">Belum ada reservasi</p>
                    @endif
                </div>
            </div>

            <!-- Recent Promotions -->
            @if($customer->promotions->count() > 0)
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Promosi Terbaru</h3>
                </div>
                <div class="px-6 py-4">
                    <div class="space-y-4">
                        @foreach($customer->promotions as $promotion)
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 last:border-b-0">
                            <div>
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $promotion->name }}
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ $promotion->created_at->format('d/m/Y H:i') }}
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ ucfirst($promotion->type) }}
                                </span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection