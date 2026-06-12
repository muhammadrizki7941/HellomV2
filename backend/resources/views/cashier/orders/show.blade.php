@php
    $bp = $basePath ?? (isset($tenant) ? '/t/'.$tenant->slug : '');
@endphp

<x-layouts.cashier
    :cashierHomeUrl="($bp === '' ? '/cashier' : $bp.'/cashier')"
    :cashierLogoutUrl="($bp === '' ? '/cashier/logout' : $bp.'/cashier/logout')">

    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Order {{ $order->order_number }}</h1>
                <p class="text-gray-600">Order details and management</p>
            </div>
            <a href="{{ $bp }}/cashier/orders" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition">Back to Orders</a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Order Information</h2>
            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-gray-600">Status:</span>
                    <span class="font-medium
                        @if($order->status === 'new') text-blue-600
                        @elseif($order->status === 'accepted') text-yellow-600
                        @elseif($order->status === 'preparing') text-purple-600
                        @elseif($order->status === 'completed') text-green-600
                        @else text-gray-600 @endif">
                        {{ ucfirst($order->status) }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Created:</span>
                    <span class="font-medium">{{ $order->created_at->format('M d, Y H:i') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-600">Total:</span>
                    <span class="text-xl font-bold text-gray-900">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Items</h2>
            <div class="space-y-4">
                @foreach($order->items as $item)
                    <div class="flex justify-between items-start border-b border-gray-100 pb-3">
                        <div class="flex-1">
                            <h3 class="font-medium text-gray-900">{{ $item->product->name }}</h3>
                            <p class="text-sm text-gray-600">Quantity: {{ $item->qty }} × Rp {{ number_format($item->unit_price, 0, ',', '.') }}</p>
                            @if($item->options->isNotEmpty())
                                <div class="text-sm text-gray-500 mt-1">
                                    Options: {{ $item->options->pluck('value_name')->join(', ') }}
                                </div>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-gray-900">Rp {{ number_format($item->line_total, 0, ',', '.') }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.cashier>