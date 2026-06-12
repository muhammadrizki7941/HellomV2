@php
    $bp = $basePath ?? (isset($tenant) ? '/t/'.$tenant->slug : '');
@endphp

<x-layouts.cashier
    :cashierHomeUrl="($bp === '' ? '/cashier' : $bp.'/cashier')"
    :cashierLogoutUrl="($bp === '' ? '/cashier/logout' : $bp.'/cashier/logout')">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Orders</h1>
        <p class="text-gray-600">Manage and process customer orders</p>
    </div>

    @if($orders->isEmpty())
        <div class="text-center py-12">
            <div class="text-6xl mb-4">📋</div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No orders yet</h3>
            <p class="text-gray-500">Orders will appear here when customers place them.</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($orders as $order)
                <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm hover:shadow-md transition">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="text-lg font-semibold text-gray-900">{{ $order->order_number }}</h3>
                                <span class="px-2 py-1 text-xs font-medium rounded-full
                                    @if($order->status === 'new') bg-blue-100 text-blue-800
                                    @elseif($order->status === 'accepted') bg-yellow-100 text-yellow-800
                                    @elseif($order->status === 'preparing') bg-purple-100 text-purple-800
                                    @elseif($order->status === 'completed') bg-green-100 text-green-800
                                    @else bg-gray-100 text-gray-800 @endif">
                                    {{ ucfirst($order->status) }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 mb-1">{{ $order->created_at->format('M d, Y H:i') }}</p>
                            <p class="text-sm text-gray-600">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ $bp }}/cashier/orders/{{ $order->order_number }}" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">View</a>
                            @if($order->status === 'new')
                                <form method="POST" action="{{ $bp }}/cashier/orders/{{ $order->order_number }}/status" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="accepted">
                                    <button class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">Accept</button>
                                </form>
                            @elseif($order->status === 'accepted')
                                <form method="POST" action="{{ $bp }}/cashier/orders/{{ $order->order_number }}/status" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="preparing">
                                    <button class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition">Prepare</button>
                                </form>
                            @elseif($order->status === 'preparing')
                                <form method="POST" action="{{ $bp }}/cashier/orders/{{ $order->order_number }}/status" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="completed">
                                    <button class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition">Complete</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.cashier>