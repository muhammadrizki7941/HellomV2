@php
    $bp = $basePath ?? (isset($tenant) ? '/t/'.$tenant->slug : '');
@endphp

<x-layouts.cashier
    :cashierHomeUrl="($bp === '' ? '/cashier' : $bp.'/cashier')"
    :cashierLogoutUrl="($bp === '' ? '/cashier/logout' : $bp.'/cashier/logout')">

    <div class="text-center">
        <h1 class="text-3xl font-bold text-gray-900 mb-4">Cashier Dashboard</h1>
        <p class="text-gray-600 mb-8">Manage orders efficiently.</p>

        <div class="grid grid-cols-1 md:grid-cols-1 gap-6 max-w-md mx-auto">
            <a href="{{ $bp }}/cashier/orders" class="block bg-blue-500 hover:bg-blue-600 text-white p-6 rounded-lg shadow-md transition">
                <div class="text-2xl mb-2">📋</div>
                <h3 class="text-xl font-semibold">Orders</h3>
                <p class="text-sm opacity-90">View and manage customer orders</p>
            </a>
        </div>
    </div>
</x-layouts.cashier>
