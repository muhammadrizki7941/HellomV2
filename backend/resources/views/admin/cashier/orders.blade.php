@section('fullwidth', true)
@php
    /** @var array<int, array<string, mixed>> $ordersPayload */
    /** @var string|null $selectedOrderNumber */
    /** @var string $initialStatus */

    $statusTabs = [
        'new' => 'Baru',
        'accepted' => 'Diterima',
        'preparing' => 'Diproses',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'all' => 'Semua',
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Daftar Pesanan (Kasir) - {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased bg-gray-50 overflow-hidden lg:overflow-hidden overflow-y-auto">
<div id="cashier-orders-shell" class="h-full flex flex-col lg:flex-row w-full max-w-none px-0" x-data="cashierOrders({
        orders: @js($ordersPayload),
        selected: @js($selectedOrderNumber),
        initialStatus: @js($initialStatus),
        realtimePublicUrl: @js($realtimePublicUrl ?? ''),
        pollUrl: @js($pollUrl ?? ''),
        cashierSettings: @js($cashierSettings ?? []),
        urls: {
            paymentStatusBase: @js(route('admin.orders.index')),
            openAdminOrderBase: @js(route('admin.orders.index')),
            bulkUrl: @js(route('admin.cashier.orders.bulk')),
            receiptBase: @js(route('admin.cashier.orders')),
        },
    })" x-init="init()">

    @include('admin.cashier._sidebar')

    <!-- Main Content Area -->
    <div id="cashier-orders-content" class="flex-1 flex overflow-hidden w-full max-w-none px-0 min-h-0">
        <!-- Orders List -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <div class="bg-white border-b border-gray-200 px-4 md:px-6 py-4">
                <div class="flex flex-wrap items-center gap-3 md:gap-4">
                    <button type="button" class="px-2 py-1 rounded-md border bg-white text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-colors lg:px-3 lg:py-2 lg:text-sm" onclick="cashierToggleSidebar()" title="Tampilkan/Sembunyikan menu">
                        <svg class="w-5 h-5 lg:w-4 lg:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <span class="sr-only lg:not-sr-only lg:ml-2">Menu</span>
                    </button>
                    <div class="order-3 w-full md:order-none md:flex-1 relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" x-model="q" placeholder="Cari order (nama / nomor / meja)..." class="w-full pl-10 pr-4 py-2.5 rounded-xl border-gray-200 focus:border-emerald-500 focus:ring-emerald-500" />
                    </div>

                    <div class="flex items-center gap-2">
                        <select x-model="timeRange" class="rounded-xl border-gray-200 text-sm py-2 px-3 bg-white">
                            <option value="all">Semua Waktu</option>
                            <option value="today">Hari Ini</option>
                            <option value="7">7 Hari Terakhir</option>
                            <option value="30">30 Hari Terakhir</option>
                        </select>
                    </div>

                    <div class="hidden sm:block text-sm text-gray-600" x-text="new Date().toLocaleDateString('id-ID')"></div>
                    <div class="flex items-center gap-2 ml-auto md:ml-0">
                        <button type="button" class="px-3 py-2 rounded-xl border text-xs font-semibold transition-colors"
                                :class="alarmEnabled ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                @click="toggleAlarm()">
                            Notifikasi: <span x-text="alarmEnabled ? 'Aktif' : 'Nonaktif'"></span>
                        </button>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-xs font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                                @click="testAlarm()">
                            Coba Bunyi
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-2 overflow-x-auto">
                    @foreach($statusTabs as $key => $label)
                        <button type="button" @click="status='{{ $key }}'"
                                :class="status==='{{ $key }}' ? 'bg-emerald-50 text-emerald-700 border-emerald-300' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                                class="px-4 py-2 rounded-xl border text-sm font-semibold whitespace-nowrap transition-colors">
                            {{ $label }}
                        </button>
                    @endforeach

                    <div class="ml-auto flex items-center gap-2 whitespace-nowrap">
                        <button type="button" @click="bulk('complete')" :disabled="saving"
                                x-show="status !== 'cancelled'"
                                class="px-4 py-2 rounded-xl border text-sm font-semibold bg-white text-gray-700 hover:bg-emerald-50 hover:border-emerald-300 disabled:opacity-50 transition-colors">
                            Tandai Semua Selesai
                        </button>
                        <button type="button" @click="bulk('cancel')" :disabled="saving"
                                x-show="status !== 'cancelled'"
                                class="px-4 py-2 rounded-xl border text-sm font-semibold bg-white text-gray-700 hover:bg-red-50 hover:border-red-300 disabled:opacity-50 transition-colors">
                            Tandai Semua Batal
                        </button>
                        <button type="button" @click="deleteCancelledSelected()" :disabled="saving || selectedCancelledOrderNumbers.length===0"
                                x-show="status === 'cancelled'"
                                class="px-4 py-2 rounded-xl border text-sm font-semibold bg-white text-red-600 hover:bg-red-50 hover:border-red-300 disabled:opacity-50 transition-colors">
                            Hapus Terpilih
                        </button>
                        <button type="button" @click="deleteAllCancelled()" :disabled="saving || cancelledOrders().length===0"
                                x-show="status === 'cancelled'"
                                class="px-4 py-2 rounded-xl border text-sm font-semibold bg-white text-red-600 hover:bg-red-50 hover:border-red-300 disabled:opacity-50 transition-colors">
                            Hapus Semua Dibatalkan
                        </button>
                    </div>
                </div>

                <div class="mt-3 rounded-xl border px-4 py-3 text-sm"
                     :class="unpaidCount() > 0 ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-gray-50 border-gray-200 text-gray-700'">
                    <span class="font-semibold" x-text="unpaidCount()"></span>
                    <span>pesanan baru</span>
                    <span class="font-semibold">BELUM BAYAR</span>
                    <span class="text-xs" x-show="unpaidCount() > 0">— pastikan ditagih / dibayar sebelum diterima.</span>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 md:p-6 space-y-6">
                <!-- Grid 1: New Orders (Unpaid) -->
                <div x-show="status==='all' || status==='new'" x-cloak>
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-blue-100 text-blue-700 font-bold">N</span>
                            <div>
                                <div class="font-bold text-gray-900">Pesanan Baru</div>
                                <div class="text-xs text-gray-500">Order baru yang belum dibayar</div>
                            </div>
                        </div>
                        <div class="text-xs font-semibold rounded-full px-3 py-1"
                             :class="newUnpaidOrders().length ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600'"
                             x-text="newUnpaidOrders().length + ' pesanan'"></div>
                    </div>

                    <div class="grid gap-3">
                        <template x-for="o in newUnpaidOrders()" :key="o.order_number">
                            <button type="button" @click="select(o.order_number)"
                                    class="w-full text-left bg-white rounded-2xl border border-gray-200 p-4 hover:shadow transition"
                                    :class="(selected===o.order_number ? 'ring-2 ring-emerald-400 border-emerald-200' : '') + (o._highlight ? ' pulse-attention' : '')">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <div class="font-bold text-gray-900 truncate" x-text="o.order_number"></div>
                                            <span class="inline-flex items-center rounded-full bg-blue-100 text-blue-800 text-[11px] font-bold px-2 py-0.5">BARU</span>
                                            <span class="inline-flex items-center rounded-full bg-amber-100 text-amber-800 text-[11px] font-bold px-2 py-0.5">BELUM BAYAR</span>
                                            <template x-if="o.order_source === 'self_order'">
                                                <span class="inline-flex items-center rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold px-2 py-0.5">PESAN SENDIRI</span>
                                            </template>
                                            <template x-if="o.order_source === 'cashier'">
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold px-2 py-0.5">KASIR</span>
                                            </template>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span class="inline-flex items-center rounded-full text-[10px] font-bold px-2 py-0.5"
                                                  :class="o.service_type==='takeout' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-700'"
                                                  x-text="o.service_type==='takeout' ? 'BUNGKUS' : 'MAKAN DI TEMPAT'"></span>
                                            <span class="mx-1">•</span>
                                            <span x-text="o.table_label || (o.service_type==='takeout' ? 'Bungkus' : 'Datang Langsung')"></span>
                                            <span class="mx-1">•</span>
                                            <input type="text" 
                                                   x-model="o.customer_name" 
                                                   @blur="updateCustomerNameInline(o)"
                                                   @keydown.enter="updateCustomerNameInline(o)"
                                                   class="inline-block bg-transparent border-0 border-b border-dotted border-gray-400 focus:border-blue-500 focus:outline-none text-xs text-gray-500 px-1 py-0.5 min-w-20"
                                                   placeholder="Nama pelanggan">
                                            <span class="mx-1">•</span>
                                            <span x-text="formatTime(o.created_at)"></span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 truncate" x-show="o.notes" x-text="o.notes"></div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-xs text-gray-500" x-text="statusLabel(o.status)"></div>
                                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(o.total_amount)"></span></div>
                                    </div>
                                </div>
                            </button>
                        </template>

                        <div class="rounded-2xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500" x-show="newUnpaidOrders().length===0" x-cloak>
                            Tidak ada pesanan baru.
                        </div>
                    </div>
                </div>

                <!-- Grid 2: Paid & Accepted Orders -->
                <div x-show="status==='all' || status==='accepted'" x-cloak>
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-bold">P</span>
                            <div>
                                <div class="font-bold text-gray-900">Dibayar & Diterima</div>
                                <div class="text-xs text-gray-500">Pesanan yang sudah dibayar dan diterima</div>
                            </div>
                        </div>
                        <div class="text-xs font-semibold rounded-full px-3 py-1 bg-gray-100 text-gray-700" x-text="paidAcceptedOrders().length + ' pesanan'"></div>
                    </div>

                    <div class="grid gap-3">
                        <template x-for="o in paidAcceptedOrders()" :key="o.order_number">
                            <button type="button" @click="select(o.order_number)"
                                    class="w-full text-left bg-white rounded-2xl border border-gray-200 p-4 hover:shadow transition"
                                    :class="(selected===o.order_number ? 'ring-2 ring-emerald-400 border-emerald-200' : '') + (o._highlight ? ' pulse-attention' : '')">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <div class="font-bold text-gray-900 truncate" x-text="o.order_number"></div>
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-900 text-[11px] font-bold px-2 py-0.5">SUDAH BAYAR</span>
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-bold px-2 py-0.5">DITERIMA</span>
                                            <template x-if="o.order_source === 'self_order'">
                                                <span class="inline-flex items-center rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold px-2 py-0.5">PESAN SENDIRI</span>
                                            </template>
                                            <template x-if="o.order_source === 'cashier'">
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold px-2 py-0.5">KASIR</span>
                                            </template>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span class="inline-flex items-center rounded-full text-[10px] font-bold px-2 py-0.5"
                                                  :class="o.service_type==='takeout' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-700'"
                                                  x-text="o.service_type==='takeout' ? 'BUNGKUS' : 'MAKAN DI TEMPAT'"></span>
                                            <span class="mx-1">•</span>
                                            <span x-text="o.table_label || (o.service_type==='takeout' ? 'Bungkus' : 'Datang Langsung')"></span>
                                            <span class="mx-1">•</span>
                                            <input type="text" 
                                                   x-model="o.customer_name" 
                                                   @blur="updateCustomerNameInline(o)"
                                                   @keydown.enter="updateCustomerNameInline(o)"
                                                   class="inline-block bg-transparent border-0 border-b border-dotted border-gray-400 focus:border-blue-500 focus:outline-none text-xs text-gray-500 px-1 py-0.5 min-w-20"
                                                   placeholder="Nama pelanggan">
                                            <span class="mx-1">•</span>
                                            <span x-text="formatTime(o.created_at)"></span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 truncate" x-show="o.notes" x-text="o.notes"></div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-xs text-gray-500" x-text="statusLabel(o.status)"></div>
                                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(o.total_amount)"></span></div>
                                    </div>
                                </div>
                            </button>
                        </template>

                        <div class="rounded-2xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500" x-show="paidAcceptedOrders().length===0" x-cloak>
                            Tidak ada pesanan yang sudah dibayar dan diterima.
                        </div>
                    </div>
                </div>

                <!-- Grid 3: Preparing Orders -->
                <div x-show="status==='all' || status==='preparing'" x-cloak>
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 font-bold">R</span>
                            <div>
                                <div class="font-bold text-gray-900">Sedang Disiapkan</div>
                                <div class="text-xs text-gray-500">Pesanan yang sedang dalam proses persiapan</div>
                            </div>
                        </div>
                        <div class="text-xs font-semibold rounded-full px-3 py-1 bg-gray-100 text-gray-700" x-text="preparingOrders().length + ' pesanan'"></div>
                    </div>

                    <div class="grid gap-3">
                        <template x-for="o in preparingOrders()" :key="o.order_number">
                            <button type="button" @click="select(o.order_number)"
                                    class="w-full text-left bg-white rounded-2xl border border-gray-200 p-4 hover:shadow transition"
                                    :class="(selected===o.order_number ? 'ring-2 ring-emerald-400 border-emerald-200' : '') + (o._highlight ? ' pulse-attention' : '')">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <div class="font-bold text-gray-900 truncate" x-text="o.order_number"></div>
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-bold px-2 py-0.5">DIPROSES</span>
                                            <template x-if="o.order_source === 'self_order'">
                                                <span class="inline-flex items-center rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold px-2 py-0.5">PESAN SENDIRI</span>
                                            </template>
                                            <template x-if="o.order_source === 'cashier'">
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold px-2 py-0.5">KASIR</span>
                                            </template>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span class="inline-flex items-center rounded-full text-[10px] font-bold px-2 py-0.5"
                                                  :class="o.service_type==='takeout' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-700'"
                                                  x-text="o.service_type==='takeout' ? 'BUNGKUS' : 'MAKAN DI TEMPAT'"></span>
                                            <span class="mx-1">•</span>
                                            <span x-text="o.table_label || (o.service_type==='takeout' ? 'Bungkus' : 'Datang Langsung')"></span>
                                            <span class="mx-1">•</span>
                                            <input type="text" 
                                                   x-model="o.customer_name" 
                                                   @blur="updateCustomerNameInline(o)"
                                                   @keydown.enter="updateCustomerNameInline(o)"
                                                   class="inline-block bg-transparent border-0 border-b border-dotted border-gray-400 focus:border-blue-500 focus:outline-none text-xs text-gray-500 px-1 py-0.5 min-w-20"
                                                   placeholder="Nama pelanggan">
                                            <span class="mx-1">•</span>
                                            <span x-text="formatTime(o.created_at)"></span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 truncate" x-show="o.notes" x-text="o.notes"></div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-xs text-gray-500" x-text="statusLabel(o.status)"></div>
                                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(o.total_amount)"></span></div>
                                    </div>
                                </div>
                            </button>
                        </template>

                        <div class="rounded-2xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500" x-show="preparingOrders().length===0" x-cloak>
                            Tidak ada pesanan yang sedang disiapkan.
                        </div>
                    </div>
                </div>

                <!-- Grid 4: Completed Orders -->
                <div x-show="status==='all' || status==='completed'" x-cloak>
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-green-100 text-green-700 font-bold">C</span>
                            <div>
                                <div class="font-bold text-gray-900">Selesai</div>
                                <div class="text-xs text-gray-500">Pesanan yang sudah selesai dan siap disajikan</div>
                            </div>
                        </div>
                        <div class="text-xs font-semibold rounded-full px-3 py-1 bg-gray-100 text-gray-700" x-text="completedOrders().length + ' pesanan'"></div>
                    </div>

                    <div class="grid gap-3">
                        <template x-for="o in completedOrders()" :key="o.order_number">
                            <button type="button" @click="select(o.order_number)"
                                    class="w-full text-left bg-white rounded-2xl border border-gray-200 p-4 hover:shadow transition"
                                    :class="(selected===o.order_number ? 'ring-2 ring-emerald-400 border-emerald-200' : '') + (o._highlight ? ' pulse-attention' : '')">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <div class="font-bold text-gray-900 truncate" x-text="o.order_number"></div>
                                            <span class="inline-flex items-center rounded-full bg-green-100 text-green-800 text-[11px] font-bold px-2 py-0.5">SELESAI</span>
                                            <template x-if="o.order_source === 'self_order'">
                                                <span class="inline-flex items-center rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold px-2 py-0.5">PESAN SENDIRI</span>
                                            </template>
                                            <template x-if="o.order_source === 'cashier'">
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold px-2 py-0.5">KASIR</span>
                                            </template>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span class="inline-flex items-center rounded-full text-[10px] font-bold px-2 py-0.5"
                                                  :class="o.service_type==='takeout' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-700'"
                                                  x-text="o.service_type==='takeout' ? 'BUNGKUS' : 'MAKAN DI TEMPAT'"></span>
                                            <span class="mx-1">•</span>
                                            <span x-text="o.table_label || (o.service_type==='takeout' ? 'Bungkus' : 'Datang Langsung')"></span>
                                            <span class="mx-1">•</span>
                                            <input type="text" 
                                                   x-model="o.customer_name" 
                                                   @blur="updateCustomerNameInline(o)"
                                                   @keydown.enter="updateCustomerNameInline(o)"
                                                   class="inline-block bg-transparent border-0 border-b border-dotted border-gray-400 focus:border-blue-500 focus:outline-none text-xs text-gray-500 px-1 py-0.5 min-w-20"
                                                   placeholder="Nama pelanggan">
                                            <span class="mx-1">•</span>
                                            <span x-text="formatTime(o.created_at)"></span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 truncate" x-show="o.notes" x-text="o.notes"></div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-xs text-gray-500" x-text="statusLabel(o.status)"></div>
                                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(o.total_amount)"></span></div>
                                    </div>
                                </div>
                            </button>
                        </template>

                        <div class="rounded-2xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500" x-show="completedOrders().length===0" x-cloak>
                            Tidak ada pesanan yang sudah selesai.
                        </div>
                    </div>
                </div>

                <!-- Grid 5: Cancelled Orders -->
                <div x-show="status==='all' || status==='cancelled'" x-cloak>
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-red-100 text-red-700 font-bold">X</span>
                            <div>
                                <div class="font-bold text-gray-900">Dibatalkan</div>
                                <div class="text-xs text-gray-500">Pesanan yang sudah dibatalkan</div>
                            </div>
                        </div>
                        <div class="text-xs font-semibold rounded-full px-3 py-1 bg-gray-100 text-gray-700" x-text="cancelledOrders().length + ' pesanan'"></div>
                    </div>

                    <div class="grid gap-3">
                        <template x-for="o in cancelledOrders()" :key="o.order_number">
                            <button type="button" @click="select(o.order_number)"
                                    class="w-full text-left bg-white rounded-2xl border border-gray-200 p-4 hover:shadow transition"
                                    :class="(selected===o.order_number ? 'ring-2 ring-emerald-400 border-emerald-200' : '')">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <template x-if="status==='cancelled'">
                                                <input type="checkbox"
                                                       class="rounded border-gray-300 text-red-600 focus:ring-red-500"
                                                       :checked="isCancelledSelected(o.order_number)"
                                                       @click.stop="toggleCancelledSelection(o.order_number, $event.target.checked)">
                                            </template>
                                            <div class="font-bold text-gray-900 truncate" x-text="o.order_number"></div>
                                            <span class="inline-flex items-center rounded-full bg-red-100 text-red-800 text-[11px] font-bold px-2 py-0.5">DIBATALKAN</span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span x-text="o.table_label || (o.service_type==='takeout' ? 'Bungkus' : 'Datang Langsung')"></span>
                                            <span class="mx-1">•</span>
                                            <span x-text="o.customer_name || '-'"></span>
                                            <span class="mx-1">•</span>
                                            <span x-text="formatTime(o.created_at)"></span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 truncate" x-show="o.notes" x-text="o.notes"></div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-xs text-gray-500" x-text="statusLabel(o.status)"></div>
                                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(o.total_amount)"></span></div>
                                    </div>
                                </div>
                            </button>
                        </template>

                        <div class="rounded-2xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500" x-show="cancelledOrders().length===0" x-cloak>
                            Tidak ada pesanan yang dibatalkan.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Detail Backdrop -->
        <div x-show="isMobileViewport() && mobileDetailOpen"
             x-transition.opacity
             x-cloak
             class="fixed inset-0 z-40 bg-slate-900/45 lg:hidden"
             @click="closeMobileDetail()"></div>

        <!-- Detail Panel -->
        <div id="cashier-orders-detail-panel"
             x-show="!isMobileViewport() || mobileDetailOpen"
             x-transition
             x-cloak
             @keydown.escape.window="closeMobileDetail()"
             class="w-full lg:w-96 bg-white border-t lg:border-t-0 lg:border-l border-gray-200 flex flex-col max-h-[45vh] lg:max-h-none">
            <div class="p-6 border-b">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Detail Pesanan</h2>
                        <div class="mt-2 text-sm text-gray-600" x-text="selectedOrder()?.customer_name || 'Pilih pesanan'">Pilih pesanan</div>
                        <div class="text-xs text-gray-500" x-text="selectedOrder() ? formatTime(selectedOrder().created_at) : ''"></div>
                    </div>
                    <button type="button"
                            class="lg:hidden inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50"
                            @click="closeMobileDetail()"
                            title="Tutup detail">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4">
                <template x-if="selectedOrder()">
                    <div class="space-y-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-bold text-gray-900" x-text="selectedOrder().order_number"></div>
                                    <div class="text-xs text-gray-500">
                                        <span class="font-semibold" x-text="selectedOrder().service_type==='takeout' ? 'Bungkus' : 'Makan di Tempat'"></span>
                                        <span class="mx-1">•</span>
                                        <span x-text="(selectedOrder().table_label || (selectedOrder().service_type==='takeout' ? 'Bungkus' : 'Datang Langsung'))"></span>
                                        <template x-if="selectedOrder().order_source">
                                            <span>
                                                <span class="mx-1">•</span>
                                                <span class="font-semibold" x-text="selectedOrder().order_source === 'self_order' ? 'PESAN SENDIRI' : (selectedOrder().order_source === 'cashier' ? 'KASIR' : selectedOrder().order_source)"></span>
                                            </span>
                                        </template>
                                        <span class="mx-1">•</span>
                                        <span x-text="statusLabel(selectedOrder().status)"></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs text-gray-500">Total</div>
                                    <div class="text-lg font-bold">Rp <span x-text="formatRp(selectedOrder().total_amount)"></span></div>
                                </div>
                            </div>

                            <div class="mt-3 text-xs text-gray-600" x-show="selectedOrder().notes">
                                <div class="font-semibold">Catatan</div>
                                <div class="text-gray-500" x-text="selectedOrder().notes"></div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-gray-900">Status Pembayaran</div>
                                <span class="text-xs font-bold px-2 py-1 rounded-full"
                                      :class="selectedOrder().payment_status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                      x-text="selectedOrder().payment_status === 'paid' ? 'SUDAH BAYAR' : 'BELUM BAYAR'"></span>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <button type="button" class="px-3 py-2 rounded-xl text-xs font-semibold border"
                                        :disabled="saving" @click="setPaymentStatus('paid')"
                                        :class="selectedOrder().payment_status === 'paid' ? 'bg-green-500 text-white border-green-500' : 'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'">
                                    💰 Tandai Sudah Bayar
                                </button>
                                <button type="button" class="px-3 py-2 rounded-xl text-xs font-semibold border"
                                        :disabled="saving" @click="setPaymentStatus('unpaid')"
                                        :class="selectedOrder().payment_status === 'unpaid' ? 'bg-red-500 text-white border-red-500' : 'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'">
                                    ❌ Tandai Belum Bayar
                                </button>
                            </div>
                        </div>
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-gray-900">Status Order</div>
                                <span class="text-xs font-bold px-2 py-1 rounded-full bg-gray-100 text-gray-700" x-text="statusLabel(selectedOrder().status)"></span>
                            </div>

                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <button type="button" class="px-3 py-2.5 rounded-xl text-xs font-semibold border transition-colors"
                                        :disabled="saving" @click="setOrderStatus('preparing')"
                                        :class="(selectedOrder().status==='preparing' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-900 border-gray-200 hover:bg-emerald-50 hover:border-emerald-300') + (isNext('prepare') ? ' pulse-attention ring-2 ring-amber-400' : '')">
                                    👨‍🍳 Proses
                                </button>
                                <button type="button" class="px-3 py-2.5 rounded-xl text-xs font-semibold border transition-colors"
                                        :disabled="saving" @click="setOrderStatus('completed')"
                                        :class="(selectedOrder().status==='completed' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-900 border-gray-200 hover:bg-emerald-50 hover:border-emerald-300') + (isNext('complete') ? ' pulse-attention ring-2 ring-amber-400' : '')">
                                    ✓ Selesai
                                </button>
                                <button type="button" class="px-3 py-2.5 rounded-xl text-xs font-semibold border transition-colors"
                                        :disabled="saving" @click="setOrderStatus('cancelled')"
                                        :class="selectedOrder().status==='cancelled' ? 'bg-red-600 text-white border-red-600' : 'bg-white text-gray-900 border-gray-200 hover:bg-red-50 hover:text-red-600 hover:border-red-300'">
                                    ✕ Batalkan
                                </button>
                            </div>

                            <div class="mt-2 text-[11px] text-gray-500">
                                Catatan: Menandai selesai bisa gagal jika pengaturan mewajibkan status sudah bayar.
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold text-gray-900">Cetak Struk</div>
                            <div class="mt-3">
                                <button type="button" class="w-full px-3 py-2.5 rounded-xl text-xs font-semibold border bg-emerald-600 text-white border-emerald-600 hover:bg-emerald-700 transition-colors shadow-sm"
                                        @click="printReceipt(selectedOrder().order_number)">
                                    🖨️ Cetak Struk
                                </button>
                            </div>
                        </div>
                            <div class="mt-3 space-y-3">
                                <template x-for="(it, idx) in selectedOrder().items" :key="idx">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-gray-900" x-text="it.product_name"></div>
                                            <div class="text-xs text-gray-500" x-text="'Rp ' + formatRp(it.unit_price) + ' × ' + it.qty"></div>
                                            <div class="text-xs text-gray-500 mt-1" x-show="it.options && it.options.length">
                                                <template x-for="(op, j) in it.options" :key="j">
                                                    <div class="truncate" x-text="op.option_name + ': ' + op.value_name"></div>
                                                </template>
                                            </div>
                                        </div>
                                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(it.line_total)"></span></div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="text-xs text-red-600" x-show="error" x-text="error"></div>
                        <div class="text-xs text-emerald-700" x-show="notice" x-text="notice"></div>
                    </div>
                </template>

                <div class="text-center py-10 text-gray-400" x-show="!selectedOrder()" x-cloak>
                    Pilih pesanan dari daftar untuk melihat detail.
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Preview Modal -->
    <div x-show="showReceiptModal" x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;"
         @keydown.escape.window="showReceiptModal = false">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" @click="showReceiptModal = false">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>

            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full"
                 @click.stop>
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">
                                Preview Struk
                            </h3>
                            
                            <div id="receipt-preview" class="border border-gray-200 rounded-lg p-4 bg-gray-50 max-h-96 overflow-y-auto">
                                <div class="text-center text-gray-500">
                                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 mx-auto"></div>
                                    <div class="mt-2">Memuat preview...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" 
                            class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm"
                            @click="printNow()">
                        🖨️ Cetak Sekarang
                    </button>
                    <button type="button" 
                            class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-900 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                            @click="showReceiptModal = false">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function cashierOrders(cfg) {
        return {
            orders: cfg.orders || [],
            selected: cfg.selected || null,
            initialStatus: cfg.initialStatus || 'new',
            realtimePublicUrl: cfg.realtimePublicUrl || '',
            pollUrl: cfg.pollUrl || '',
            cashierSettings: cfg.cashierSettings || {},
            urls: cfg.urls || {},


            socket: null,
            socketStatus: 'disconnected',

            pollTimer: null,
            lastPollSince: null,

            alarmEnabled: true,
            alarmVolume: 0.25,
            alarmPreset: 'double',
            _audioCtx: null,
            _alarmUnlockHinted: false,

            q: '',
            status: 'new',
            timeRange: 'all',
            saving: false,
            error: '',
            notice: '',

            showReceiptModal: false,
            currentReceiptOrderNumber: null,
            mobileDetailOpen: false,
            selectedCancelledOrderNumbers: [],

            init() {
                this.status = this.initialStatus || 'new';
                if (!this.selected && this.orders.length) {
                    // Prefer an unpaid order first
                    const first = this.orders.find(o => o.payment_status === 'unpaid') || this.orders[0];
                    this.selected = first ? first.order_number : null;
                }

                // Initialize original customer names for inline editing
                this.orders.forEach(o => {
                    o._originalCustomerName = o.customer_name || '';
                });

                this.loadAlarmSettings();
                this.initRealtime();
                this.startPolling();

                window.addEventListener('resize', () => {
                    if (!this.isMobileViewport()) {
                        this.mobileDetailOpen = false;
                    }
                });

            },

            isMobileViewport() {
                return window.innerWidth < 1024;
            },

            closeMobileDetail() {
                if (!this.isMobileViewport()) return;
                this.mobileDetailOpen = false;
            },

            loadAlarmSettings() {
                try {
                    const raw = localStorage.getItem('cashier.orderAlarm');
                    if (!raw) return;
                    const j = JSON.parse(raw);
                    if (typeof j.alarmEnabled === 'boolean') this.alarmEnabled = j.alarmEnabled;
                    if (typeof j.alarmVolume === 'number') this.alarmVolume = Math.max(0, Math.min(1, j.alarmVolume));
                    if (typeof j.alarmPreset === 'string') this.alarmPreset = j.alarmPreset;
                } catch (e) {
                    // ignore
                }
            },

            saveAlarmSettings() {
                try {
                    localStorage.setItem('cashier.orderAlarm', JSON.stringify({
                        alarmEnabled: !!this.alarmEnabled,
                        alarmVolume: Number(this.alarmVolume || 0),
                        alarmPreset: String(this.alarmPreset || 'double'),
                    }));
                } catch (e) {
                    // ignore
                }
            },

            toggleAlarm() {
                this.alarmEnabled = !this.alarmEnabled;
                this.saveAlarmSettings();
                this.notice = this.alarmEnabled ? 'Alarm diaktifkan.' : 'Alarm dimatikan.';
                setTimeout(() => { this.notice = ''; }, 1500);
            },

            ensureAudioContext() {
                if (this._audioCtx) return this._audioCtx;
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return null;
                this._audioCtx = new Ctx();
                return this._audioCtx;
            },

            beep(freq, durationMs, type = 'sine') {
                const ctx = this.ensureAudioContext();
                if (!ctx) return;
                const vol = Math.max(0, Math.min(1, Number(this.alarmVolume || 0)));
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = type;
                o.frequency.value = freq;
                g.gain.value = vol;
                o.connect(g);
                g.connect(ctx.destination);
                const t = ctx.currentTime;
                o.start(t);
                o.stop(t + (durationMs / 1000));
            },

            playAlarm() {
                if (!this.alarmEnabled) return;

                const ctx = this.ensureAudioContext();
                if (!ctx) return;
                if (ctx.state === 'suspended') {
                    if (!this._alarmUnlockHinted) {
                        this._alarmUnlockHinted = true;
                        this.notice = 'Klik Test sekali untuk aktifkan suara alarm.';
                        setTimeout(() => { this.notice = ''; }, 3000);
                    }
                    return;
                }

                const preset = String(this.alarmPreset || 'double');
                if (preset === 'beep') {
                    this.beep(880, 120, 'sine');
                    return;
                }

                // default: double
                this.beep(1046, 120, 'triangle');
                setTimeout(() => this.beep(1318, 140, 'triangle'), 140);
            },

            async testAlarm() {
                const ctx = this.ensureAudioContext();
                if (ctx && ctx.state === 'suspended') {
                    try { await ctx.resume(); } catch (e) {}
                }
                this.playAlarm();
            },

            async initRealtime() {
                if (!this.realtimePublicUrl) return;

                try {
                    await this.ensureSocketIoLoaded();

                    const socket = window.io(this.realtimePublicUrl, {
                        transports: ['websocket', 'polling'],
                        reconnection: true,
                        reconnectionAttempts: 10,
                        reconnectionDelay: 500,
                        reconnectionDelayMax: 5000,
                        timeout: 8000,
                    });

                    this.socket = socket;

                    socket.on('connect', () => { this.socketStatus = 'connected'; });
                    socket.on('disconnect', () => { this.socketStatus = 'disconnected'; });
                    socket.on('connect_error', () => { this.socketStatus = 'error'; });

                    socket.on('order.created', (payload) => {
                        const order = this.normalizeOrderFromSocket(payload);
                        if (!order) return;
                        const isNew = this.upsertOrder(order, true);

                        // Auto-switch to "New" tab and highlight the order for visibility when unpaid
                        if (isNew && String(order.payment_status) === 'unpaid') {
                            try {
                                this.status = 'new';
                                this.selected = order.order_number;
                                const idx = this.orders.findIndex(o => o.order_number === order.order_number);
                                if (idx !== -1) {
                                    this.orders[idx]._highlight = true;
                                    setTimeout(() => { try { this.orders[idx]._highlight = false; } catch (e) {} }, 4000);
                                }
                            } catch (e) {}
                        }

                        // Play alarm for new self orders
                        if (isNew && order.order_source === 'self_order') {
                            this.playAlarm();
                        }
                    });

                    socket.on('order.updated', (payload) => {
                        const order = this.normalizeOrderFromSocket(payload);
                        if (!order) return;
                        this.upsertOrder(order, false);
                    });
                } catch (e) {
                    this.socketStatus = 'error';
                }
            },

            startPolling() {
                if (!this.pollUrl) return;
                try { if (this.pollTimer) clearInterval(this.pollTimer); } catch (e) {}

                this.lastPollSince = new Date().toISOString();
                this.pollTimer = setInterval(() => {
                    // Only poll as fallback when realtime isn't connected.
                    if (this.socketStatus === 'connected') return;
                    this.pollOnce();
                }, 5000);
            },

            async pollOnce() {
                if (!this.pollUrl) return;
                const since = this.lastPollSince || new Date(Date.now() - 2 * 60 * 1000).toISOString();

                try {
                    const u = new URL(this.pollUrl, window.location.origin);
                    u.searchParams.set('since', since);
                    const res = await fetch(u.toString(), { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) return;
                    const data = await res.json();
                    this.lastPollSince = data?.server_time || new Date().toISOString();

                    const incoming = Array.isArray(data?.orders) ? data.orders : [];
                    for (const raw of incoming) {
                        const order = this.normalizeOrderFromSocket(raw);
                        if (!order) continue;
                        const isNew = this.upsertOrder(order, true);
                        if (isNew && order.order_source === 'self_order') {
                            this.playAlarm();
                        }
                    }
                } catch (e) {
                    // ignore
                }
            },

            ensureSocketIoLoaded() {
                if (window.io) return Promise.resolve();

                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    const base = String(this.realtimePublicUrl || '').replace(/\/$/, '');
                    script.src = base + '/socket.io/socket.io.js';
                    script.async = true;
                    script.onload = () => resolve();
                    script.onerror = () => reject(new Error('Failed to load Socket.IO client'));
                    document.head.appendChild(script);
                });
            },

            normalizeOrderFromSocket(payload) {
                const raw = payload && payload.order ? payload.order : payload;
                if (!raw || !raw.order_number) return null;

                const createdAt = raw.created_at || raw.createdAt || null;
                const items = Array.isArray(raw.items)
                    ? raw.items.map(i => ({
                        product_name: i.product_name || i.productName || '',
                        qty: Number(i.qty ?? i.quantity ?? 0),
                        unit_price: Number(i.unit_price ?? i.price ?? 0),
                        line_total: Number(i.line_total ?? i.subtotal ?? 0),
                        options: Array.isArray(i.options)
                            ? i.options.map(op => ({
                                option_name: op.option_name || op.optionName || '',
                                value_name: op.value_name || op.valueName || '',
                            }))
                            : [],
                    }))
                    : [];

                return {
                    order_number: String(raw.order_number),
                    status: String(raw.status || ''),
                    payment_status: String(raw.payment_status || ''),
                    payment_method: String(raw.payment_method || ''),
                    service_type: String(raw.service_type || 'dine_in'),
                    order_source: String(raw.order_source || ''),
                    table_label: String(raw.table_label || ''),
                    customer_name: String(raw.customer_name || ''),
                    notes: String(raw.notes || ''),
                    total_amount: Number(raw.total_amount ?? 0),
                    created_at: createdAt,
                    payment_qr_url: raw.payment_qr_url ?? null,
                    _originalCustomerName: String(raw.customer_name || ''),
                    items,
                };
            },

            upsertOrder(order, prepend) {
                const idx = (this.orders || []).findIndex(o => o.order_number === order.order_number);
                if (idx === -1) {
                    if (prepend) this.orders.unshift(order);
                    else this.orders.push(order);
                    return true;
                }

                const wasSelected = this.selected === this.orders[idx].order_number;
                const next = { ...this.orders[idx], ...order };
                if (order.order_source === undefined) {
                    next.order_source = this.orders[idx].order_source;
                }
                this.orders[idx] = next;
                if (wasSelected) this.selected = this.orders[idx].order_number;
                return false;
            },

            formatRp(v) {
                const n = Number(v);
                if (!isFinite(n)) return '0';
                try { return new Intl.NumberFormat('id-ID').format(Math.round(n)); }
                catch(e){ return String(Math.round(n)); }
            },

            formatTime(iso) {
                if (!iso) return '';
                try { return new Date(iso).toLocaleString('id-ID'); }
                catch (e) { return String(iso); }
            },

            statusLabel(s) {
                const map = {
                    new: 'Baru',
                    accepted: 'Diterima',
                    preparing: 'Diproses',
                    completed: 'Selesai',
                    cancelled: 'Dibatalkan',
                };
                return map[String(s || '')] || String(s || '');
            },

            paymentMethodLabel(m) {
                const map = {
                    cash: 'Cash (Tunai)',
                    qris_static: 'QRIS (Statis)',
                    qris_dynamic: 'QRIS (Dinamis - API)',
                    qris: 'QRIS',
                };
                return map[String(m || '')] || String(m || '-');
            },

            receiptUrl(orderNumber) {
                return this.urls.receiptBase + '/' + encodeURIComponent(orderNumber) + '/receipt?autoprint=1';
            },

            select(orderNumber) {
                this.selected = orderNumber;
                this.error = '';
                this.notice = '';
                if (this.isMobileViewport()) {
                    this.mobileDetailOpen = true;
                }
            },

            isCancelledSelected(orderNumber) {
                return this.selectedCancelledOrderNumbers.includes(String(orderNumber));
            },

            toggleCancelledSelection(orderNumber, checked) {
                const value = String(orderNumber);
                if (checked) {
                    if (!this.selectedCancelledOrderNumbers.includes(value)) {
                        this.selectedCancelledOrderNumbers.push(value);
                    }
                    return;
                }
                this.selectedCancelledOrderNumbers = this.selectedCancelledOrderNumbers.filter(v => v !== value);
            },

            clearCancelledSelection() {
                this.selectedCancelledOrderNumbers = [];
            },

            selectedOrder() {
                if (!this.selected) return null;
                return this.orders.find(o => o.order_number === this.selected) || null;
            },

            matchesStatus(o) {
                if (this.status === 'all') return true;
                return String(o.status) === String(this.status);
            },

            matchesQuery(o) {
                const q = (this.q || '').toLowerCase().trim();
                if (!q) return true;
                const hay = [
                    o.order_number,
                    o.customer_name,
                    o.table_label,
                    o.notes,
                ].map(x => String(x || '').toLowerCase()).join(' ');
                return hay.includes(q);
            },

            visibleOrders() {
                return (this.orders || []).filter(o => this.matchesStatus(o) && this.matchesQuery(o) && this.matchesTime(o));
            },

            matchesTime(o){
                if(!o || !o.created_at) return true;
                if(this.timeRange === 'all') return true;
                const d = new Date(o.created_at);
                if(this.timeRange === 'today'){
                    const now = new Date();
                    return d.toDateString() === now.toDateString();
                }
                const days = Number(this.timeRange) || 0;
                if(days > 0){
                    const cutoff = new Date(); cutoff.setDate(cutoff.getDate() - days);
                    return d >= cutoff;
                }
                return true;
            },

            unpaidOrders() {
                return this.visibleOrders().filter(o => String(o.payment_status) === 'unpaid');
            },

            paidOrders() {
                return this.visibleOrders().filter(o => String(o.payment_status) === 'paid');
            },

            unpaidCount() {
                return this.newUnpaidOrders().length;
            },

            newUnpaidOrders() {
                return this.visibleOrders().filter(o => String(o.status) === 'new' && String(o.payment_status) === 'unpaid');
            },

            paidAcceptedOrders() {
                return this.visibleOrders().filter(o => String(o.payment_status) === 'paid' && String(o.status) === 'accepted');
            },

            preparingOrders() {
                return this.visibleOrders().filter(o => String(o.status) === 'preparing');
            },

            completedOrders() {
                return this.visibleOrders().filter(o => String(o.status) === 'completed');
            },

            cancelledOrders() {
                return this.visibleOrders().filter(o => String(o.status) === 'cancelled');
            },

            totalOrdersCount() {
                return this.visibleOrders().length;
            },

            async setPaymentStatus(nextStatus) {
                const o = this.selectedOrder();
                if (!o) return;

                this.error = '';
                this.notice = '';
                this.saving = true;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const url = this.urls.paymentStatusBase + '/' + encodeURIComponent(o.order_number) + '/payment-status';

                    const res = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ payment_status: nextStatus }),
                    });

                    if (!res.ok) {
                        let msg = 'Gagal update status pembayaran.';
                        try {
                            const data = await res.json();
                            if (data && data.message) msg = String(data.message);
                        } catch (e) {}
                        throw new Error(msg);
                    }

                    o.payment_status = nextStatus;

                    if (nextStatus === 'unpaid') {
                        // Ensure order is treated as new/unpaid and appears in Pesanan Baru
                        o.status = 'new';
                        o._highlight = true;
                        this.orders = [o, ...this.orders.filter(x => x.order_number !== o.order_number)];
                        setTimeout(() => { try{ o._highlight = false; }catch(e){} }, 4000);
                    } else if (nextStatus === 'paid') {
                        // Set status based on payment method
                        const paymentMethod = String(o.payment_method || '');
                        if (paymentMethod === 'qris_static') {
                            o.status = 'accepted'; // Require manual acceptance
                        } else if (paymentMethod === 'qris_dynamic' || paymentMethod === 'cash') {
                            o.status = 'accepted';
                        } else if (this.cashierSettings && this.cashierSettings.auto_complete_when_paid) {
                            o.status = 'completed';
                        } else if (o.status === 'new') {
                            o.status = 'accepted';
                        }
                        o._highlight = true;
                        setTimeout(() => { try{ o._highlight = false; }catch(e){} }, 4000);
                    }

                    this.notice = 'Status pembayaran diperbarui.';
                } catch (e) {
                    this.error = e?.message ? String(e.message) : 'Terjadi error.';
                } finally {
                    this.saving = false;
                }
            },

            async updateCustomerName() {
                const o = this.selectedOrder();
                if (!o) return;

                const newName = (this.editingCustomerName || '').trim();
                if (newName === o.customer_name) return; // No change

                this.error = '';
                this.notice = '';
                this.saving = true;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const url = this.urls.openAdminOrderBase + '/' + encodeURIComponent(o.order_number) + '/customer-name';

                    const res = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ customer_name: newName }),
                    });

                    if (!res.ok) {
                        let msg = 'Gagal update nama pelanggan.';
                        try {
                            const data = await res.json();
                            if (data && data.message) msg = String(data.message);
                        } catch (e) {}
                        throw new Error(msg);
                    }

                    o.customer_name = newName;
                    this.notice = 'Nama pelanggan berhasil diperbarui.';
                } catch (e) {
                    this.error = e?.message ? String(e.message) : 'Terjadi error.';
                } finally {
                    this.saving = false;
                }
            },

            async updateCustomerNameInline(order) {
                if (!order) return;

                const newName = (order.customer_name || '').trim();
                if (newName === order._originalCustomerName) return; // No change

                // Temporarily store the original name for comparison
                order._originalCustomerName = newName;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const url = this.urls.openAdminOrderBase + '/' + encodeURIComponent(order.order_number) + '/customer-name';

                    const res = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ customer_name: newName }),
                    });

                    if (!res.ok) {
                        // Revert on error
                        order.customer_name = order._originalCustomerName;
                        console.error('Failed to update customer name');
                    } else {
                        // Update the original name on success
                        order._originalCustomerName = newName;
                    }
                } catch (e) {
                    // Revert on error
                    order.customer_name = order._originalCustomerName;
                    console.error('Error updating customer name:', e);
                }
            },

            // Next-action helper for kitchen orders UI
            getNextActionName() {
                const o = this.selectedOrder();
                if (!o) return null;
                if (String(o.payment_status) === 'unpaid') return 'setPaid';
                if (String(o.status) === 'new' && String(o.payment_status) === 'paid') return 'prepare';
                if (String(o.status) === 'preparing') return 'complete';
                return null;
            },

            isNext(name) { try{ return String(this.getNextActionName()) === String(name); }catch(e){ return false; } },

            async setOrderStatus(nextStatus) {
                const o = this.selectedOrder();
                if (!o) return;

                if (String(nextStatus) === 'preparing' && String(o.order_source || '') === 'self_order' && String(o.payment_status || '') !== 'paid') {
                    const msg = 'Pesanan self-order belum dibayar. Ubah status pembayaran menjadi sudah bayar sebelum diproses.';
                    try { alert(msg); } catch (e) {}
                    this.error = msg;
                    this.notice = '';
                    return;
                }

                if (String(nextStatus) === 'completed' && String(o.payment_status || '') !== 'paid') {
                    const msg = 'Pesanan belum dibayar. Ubah status pembayaran menjadi sudah bayar sebelum diselesaikan.';
                    try { alert(msg); } catch (e) {}
                    this.error = msg;
                    this.notice = '';
                    return;
                }

                this.error = '';
                this.notice = '';
                this.saving = true;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const url = this.urls.openAdminOrderBase + '/' + encodeURIComponent(o.order_number) + '/status';

                    const res = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ status: nextStatus }),
                    });

                    if (!res.ok) {
                        let msg = 'Gagal update status pesanan.';
                        try {
                            const data = await res.json();
                            if (data && data.message) msg = String(data.message);
                        } catch (e) {}
                        throw new Error(msg);
                    }

                    o.status = nextStatus;
                    this.notice = 'Status pesanan diperbarui.';
                } catch (e) {
                    this.error = e?.message ? String(e.message) : 'Terjadi error.';
                } finally {
                    this.saving = false;
                }
            },

            async deleteSelected() {
                const o = this.selectedOrder();
                if (!o) return;
                if (!confirm('Batalkan pesanan ini?')) return;

                await this.setOrderStatus('cancelled');
            },

            async deleteCancelledSelected() {
                const list = (this.selectedCancelledOrderNumbers || []).slice();
                if (!list.length) return;
                await this.bulk('delete', list);
            },

            async deleteAllCancelled() {
                const list = this.cancelledOrders().map(o => o.order_number);
                if (!list.length) return;
                await this.bulk('delete', list);
            },

            async bulk(action, overrideList = null) {
                const list = Array.isArray(overrideList) ? overrideList : this.visibleOrders().map(o => o.order_number);
                if (!list.length) return;

                if (action === 'cancel') {
                    if (!confirm('Ubah semua pesanan yang tampil menjadi dibatalkan?')) return;
                } else if (action === 'complete') {
                    if (!confirm('Ubah semua pesanan yang tampil menjadi selesai? (yang belum bayar bisa gagal jika pengaturan mewajibkan sudah bayar)')) return;
                } else if (action === 'delete') {
                    if (!confirm('Hapus permanen pesanan dibatalkan yang dipilih?')) return;
                }

                this.error = '';
                this.notice = '';
                this.saving = true;

                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const res = await fetch(this.urls.bulkUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ action, order_numbers: list }),
                    });

                    if (!res.ok) {
                        throw new Error('Bulk action gagal.');
                    }

                    const data = await res.json();
                    const updated = (data && data.updated) ? data.updated : [];
                    const failed = (data && data.failed) ? data.failed : [];

                    if (action === 'cancel') {
                        this.orders.forEach(o => { if (updated.includes(o.order_number)) o.status = 'cancelled'; });
                    } else if (action === 'complete') {
                        this.orders.forEach(o => { if (updated.includes(o.order_number)) o.status = 'completed'; });
                    } else if (action === 'delete') {
                        this.orders = this.orders.filter(o => !updated.includes(o.order_number));
                        this.selectedCancelledOrderNumbers = this.selectedCancelledOrderNumbers.filter(v => !updated.includes(v));
                        if (this.selected && updated.includes(this.selected)) {
                            this.selected = this.orders.length ? this.orders[0].order_number : null;
                        }
                    }

                    if (failed.length) {
                        this.notice = `Selesai: ${updated.length} berhasil, ${failed.length} gagal.`;
                        return;
                    }
                    this.notice = `Selesai: ${updated.length} pesanan diproses.`;
                } catch (e) {
                    this.error = e?.message ? String(e.message) : 'Terjadi error.';
                } finally {
                    this.saving = false;
                }
            },

            async printReceipt(orderNumber) {
                this.currentReceiptOrderNumber = orderNumber;
                this.showReceiptModal = true;
                
                const previewDiv = document.getElementById('receipt-preview');
                if (!previewDiv) return;
                
                previewDiv.innerHTML = `
                    <div class="text-center text-gray-500">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 mx-auto"></div>
                        <div class="mt-2">Memuat preview...</div>
                    </div>
                `;

                try {
                    const url = this.urls.receiptBase + '/' + encodeURIComponent(orderNumber) + '/receipt';
                    const res = await fetch(url, {
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!res.ok) {
                        throw new Error('Gagal memuat receipt');
                    }

                    const html = await res.text();
                    previewDiv.innerHTML = html;
                } catch (e) {
                    previewDiv.innerHTML = `
                        <div class="text-center text-red-500">
                            <div class="text-sm">Gagal memuat preview</div>
                            <div class="text-xs mt-1">${e.message || 'Terjadi error'}</div>
                        </div>
                    `;
                }
            },

            async printNow() {
                if (!this.currentReceiptOrderNumber) return;

                try {
                    // Create a hidden iframe for printing
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    document.body.appendChild(iframe);

                    const url = this.urls.receiptBase + '/' + encodeURIComponent(this.currentReceiptOrderNumber) + '/receipt?autoprint=1';
                    
                    iframe.onload = () => {
                        try {
                            iframe.contentWindow.print();
                        } catch (e) {
                            console.error('Print failed:', e);
                        } finally {
                            // Remove iframe after a delay
                            setTimeout(() => {
                                document.body.removeChild(iframe);
                            }, 1000);
                        }
                    };

                    iframe.src = url;
                    
                    // Close modal
                    this.showReceiptModal = false;
                    this.notice = 'Struk sedang dicetak...';
                    setTimeout(() => { this.notice = ''; }, 3000);
                } catch (e) {
                    this.error = 'Gagal mencetak struk: ' + (e.message || 'Terjadi error');
                }
            },
        }
    }
</script>
<style>
    @media (max-width: 1023px) {
        #cashier-orders-shell { flex-direction: column !important; height: 100% !important; }
        #cashier-orders-content {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            flex-direction: column !important;
        }
        #cashier-orders-detail-panel {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            z-index: 50 !important;
            width: min(94vw, 680px) !important;
            min-width: 0 !important;
            max-width: 94vw !important;
            max-height: 86vh !important;
            min-height: 280px;
            border: 1px solid rgb(229 231 235) !important;
            border-radius: 1rem;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
        }
    }

    @media (min-width: 1024px) {
        #cashier-orders-shell { flex-direction: row !important; height: 100% !important; }
        #cashier-orders-content {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            flex-direction: row !important;
        }
        #cashier-orders-detail-panel {
            width: 24rem !important;
            min-width: 24rem !important;
            max-width: 24rem !important;
            max-height: none !important;
            min-height: 0 !important;
            border-left: 1px solid rgb(229 231 235) !important;
            border-top: 0 !important;
        }
    }
</style>

</body>
</html>
