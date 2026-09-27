@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Product> $products */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Category> $categories */
    /** @var \Illuminate\Support\Collection<int, \App\Models\DiningTable> $tables */
    /** @var \App\Models\PaymentSetting|null $paymentSetting */
    /** @var array<string,string> $paymentMethods */

    $payload = [
        'categories' => $categories->map(fn ($c) => [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
        ])->values()->all(),
        'tables' => $tables->map(fn ($t) => [
            'public_id' => (string) $t->public_id,
            'code' => (string) ($t->code ?? ''),
            'name' => (string) ($t->name ?? ''),
            'label' => (string) (($t->name ?: $t->code) ?: $t->public_id),
        ])->values()->all(),
        'products' => $products->map(function ($p) {
            return [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'price' => (int) $p->price,
                'image_url' => $p->imageUrl(),
                'track_stock' => (bool) $p->track_stock,
                'stock' => $p->stock === null ? null : (int) $p->stock,
                'category_ids' => $p->categories?->pluck('id')->map(fn ($v) => (int) $v)->values()->all() ?? [],
                'options' => ($p->options ?? collect())->map(function ($opt) {
                    return [
                        'id' => (int) $opt->id,
                        'name' => (string) $opt->name,
                        'type' => (string) $opt->type,
                        'is_required' => (bool) $opt->is_required,
                        'values' => ($opt->values ?? collect())->map(fn ($v) => [
                            'id' => (int) $v->id,
                            'name' => (string) $v->name,
                            'price_delta' => (int) $v->price_delta,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all(),
        'paymentMethods' => $paymentMethods ?? [],
        'cashierSettings' => [
            'auto_complete_when_paid' => (bool) ($paymentSetting?->auto_complete_when_paid ?? true),
            'require_paid_before_submit' => (bool) ($paymentSetting?->require_paid_before_submit ?? false),
        ],
        'initial' => [
            'table' => (string) old('table', ''),
            'table_label' => (string) old('table_label', ''),
            'customer_name' => (string) old('customer_name', ''),
            'notes' => (string) old('notes', ''),
            'payment_method' => (string) old('payment_method', $paymentSetting?->default_method ?? 'cash'),
            'payment_status' => (string) old('payment_status', 'paid'),
        ],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mode Kasir - {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased bg-gray-50 overflow-hidden overflow-y-auto">
    <div id="cashier-shell" class="h-full flex flex-col" x-data="cashier({
        ordersCount: @js($ordersCount),
        realtimePublicUrl: @js($realtimePublicUrl ?? ''),
        pollUrl: @js($pollUrl ?? ''),
        ordersCountUrl: @js(route('admin.cashier.orders-count')),
        categories: @js($payload['categories']),
        tables: @js($payload['tables']),
        products: @js($payload['products']),
        paymentMethods: @js($payload['paymentMethods']),
        cashierSettings: @js($payload['cashierSettings']),
        initial: @js($payload['initial']),
        activeTab: 'pos'
    })" x-init="init()">
        @include('admin.cashier._sidebar')

        <!-- Main Content Area -->
        <div id="cashier-main-panel" class="flex-1 flex flex-col overflow-hidden min-h-0 w-full">
            <!-- Top Search Bar -->
            <div class="bg-white border-b border-gray-200 px-4 md:px-6 py-4">
                <div class="flex flex-wrap items-center gap-3 md:gap-4">
                    <button type="button" class="px-2 py-1 rounded-md border bg-white text-gray-700 hover:bg-gray-50" onclick="cashierToggleSidebar()" title="Tampilkan/Sembunyikan menu">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <div class="order-3 w-full md:order-none md:flex-1 relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" x-model="q" x-ref="search" placeholder="Cari menu..." class="w-full pl-10 pr-4 py-2.5 rounded-xl border-gray-200 focus:border-emerald-500 focus:ring-emerald-500" />
                    </div>
                    <div class="hidden sm:flex items-center gap-2 text-sm text-gray-600">
                        <span x-text="new Date().toLocaleString('id-ID')"></span>
                    </div>
                    <div class="flex items-center gap-2 ml-auto md:ml-0">
                        <button type="button" class="px-3 py-2 rounded-2xl border text-xs font-semibold"
                                :class="alarmEnabled ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-600'"
                                @click="toggleAlarm()">
                            Notifikasi: <span x-text="alarmEnabled ? 'Aktif' : 'Nonaktif'"></span>
                        </button>
                        <button type="button" class="px-3 py-2 rounded-2xl border border-gray-200 bg-white text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                @click="testAlarm()">
                            Coba Bunyi
                        </button>
                    </div>
                </div>
            </div>

           

            <!-- Products Grid -->
            <div class="flex-1 overflow-y-auto px-4 md:px-5 py-4">
                <div class="mb-3">
                    <h3 class="text-lg font-bold text-gray-900">Tambah Menu ke Keranjang</h3>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3">
                    <template x-for="p in filteredProducts" :key="p.id">
                        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden hover:shadow-md hover:border-emerald-300 transition-all duration-200 cursor-pointer" @click="addOrConfigure(p)">
                            <div class="bg-gray-50 relative h-28 md:h-32">
                                <img x-show="p.image_url" :src="p.image_url" :alt="p.name" class="w-full h-full object-cover" onerror="if(this.dataset.retryImg){return;} this.dataset.retryImg='1'; const u=new URL(this.src, window.location.origin); u.searchParams.set('_img_retry', Date.now().toString()); this.src=u.toString();" />
                                <div x-show="!p.image_url" class="w-full h-full flex items-center justify-center text-gray-400">
                                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="p-3">
                                <h4 class="font-semibold text-gray-900 truncate text-sm" x-text="p.name"></h4>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <span class="text-sm font-bold text-slate-800">Rp <span x-text="formatRp(p.price)"></span></span>
                                    <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-xs font-semibold hover:bg-emerald-600 transition-colors shadow-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                        </svg>
                                        Pilih
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="mt-6 text-sm text-gray-500" x-show="filteredProducts.length === 0" x-cloak>
                    Menu tidak ditemukan.
                </div>
            </div>
        </div>

        <div id="cashier-mobile-cartfab-wrap" x-show="isMobileViewport() && !mobileReviewOpen" x-cloak>
            <button type="button"
                id="cashier-mobile-cartfab"
                    class="w-14 h-14 rounded-full bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg transition flex items-center justify-center"
                    :class="{ 'opacity-50 pointer-events-none': cart.length===0, 'animate-pulse': cart.length > 0 }"
                    title="Proses Pesanan"
                    @click="openMobileReview()">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m1.6 8L5.4 5M7 13l-1.293 1.293A1 1 0 006.414 16H19m-12 0a2 2 0 104 0m8 0a2 2 0 11-4 0"/>
                </svg>
                <span class="sr-only">Proses Pesanan</span>
            </button>
        </div>

           <div x-show="isMobileViewport() && mobileReviewOpen"
             x-transition.opacity
             x-cloak
               class="fixed inset-0 z-40 bg-slate-900/45"
             @click="closeMobileReview()"></div>

        <!-- Order Details Panel (Right Side) -->
           <div id="cashier-detail-panel"
               :class="{ 'cashier-mobile-review-hidden': isMobileViewport() && !mobileReviewOpen }"
             @keydown.escape.window="closeMobileReview()"
               class="w-full bg-white border-t border-gray-200 flex flex-col max-h-[45vh]">
            <div class="p-4 md:p-6 border-b">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base md:text-lg font-bold text-gray-900">Rincian Pesanan</h2>
                        <div class="mt-1 md:mt-2 text-xs md:text-sm text-gray-600" x-text="cart.length ? cart.length + ' menu' : 'Keranjang kosong'"></div>
                        <div class="text-xs text-gray-500" x-text="new Date().toLocaleString('id-ID')"></div>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <button type="button"
                            x-show="mobileReviewOpen"
                            class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50"
                                @click="closeMobileReview()"
                                title="Tutup rincian">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                        <div class="text-xs text-gray-500 text-right" x-show="!isMobileViewport() && cart.length > 0">Proses lewat tombol di bagian bawah.</div>
                    </div>
                </div>
            </div>



            <!-- Cart Items -->
            <div class="flex-1 overflow-y-auto px-3 md:px-5 py-3 md:py-4">
                <div class="space-y-3">
                    <template x-for="(it, idx) in cart" :key="it.key">
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1">
                                        <div class="font-semibold text-gray-900 text-xs md:text-sm leading-tight" x-text="it.name"></div>
                                        <div class="text-xs text-gray-500 mt-0.5 leading-tight" x-show="it.optionsLabel" x-text="it.optionsLabel"></div>
                                        <div class="flex items-center gap-2 mt-2">
                                            <button type="button" @click="decQty(idx)" class="w-5 h-5 rounded-md bg-gray-100 hover:bg-gray-200 flex items-center justify-center">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                                                </svg>
                                            </button>
                                            <span class="text-sm font-semibold w-8 text-center" x-text="it.qty"></span>
                                            <button type="button" @click="incQty(idx)" class="w-5 h-5 rounded-md bg-gray-100 hover:bg-gray-200 flex items-center justify-center">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs md:text-sm font-bold text-gray-900">Rp <span x-text="formatRp(it.unitPrice * it.qty)"></span></div>
                                        <button type="button" @click="removeItem(idx)" class="mt-1 text-xs text-red-600 hover:text-red-700">Hapus</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div class="text-center py-8 text-gray-400" x-show="cart.length === 0" x-cloak>
                        <svg class="w-12 h-12 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                        <p class="text-sm">Keranjang masih kosong</p>
                    </div>
                </div>
            </div>

            <!-- Payment Summary (sticky action zone) -->
            <div class="border-t p-4 md:p-6 space-y-2 md:space-y-3 bg-white shadow-inner">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs md:text-sm font-semibold text-gray-900">Proses Pesanan</div>
                    <div class="hidden md:block text-xs text-gray-500" x-show="cart.length">Periksa pembayaran sebelum kirim</div>
                </div>

                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Subtotal</span>
                    <span class="font-semibold">Rp <span x-text="formatRp(subtotal())"></span></span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Diskon</span>
                    <span class="font-semibold">Rp 0</span>
                </div>
                <div class="border-t pt-3 flex items-center justify-between">
                    <span class="font-bold text-gray-900">Total</span>
                    <span class="text-xl font-bold text-gray-900">Rp <span x-text="formatRp(subtotal())"></span></span>
                </div>

                <div class="pt-3">
                    <div class="text-xs text-gray-500">Ringkasan harga order di atas.</div>
                </div>

                <button type="button" x-show="!isMobileViewport()" class="w-full inline-flex items-center justify-center px-4 py-3 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm shadow-sm transition"
                        :class="{ 'opacity-50 pointer-events-none': cart.length===0, 'animate-pulse': cart.length > 0 }"
                        @click="openProcessModal()">
                    Proses Pembayaran
                </button>

                <div class="text-xs text-red-500" x-show="cart.length===0">Tambah item dulu sebelum proses order.</div>
            </div>

            <div x-show="isMobileViewport()" class="sticky bottom-0 z-30 px-3 py-2 border-t border-emerald-100 bg-white/95 backdrop-blur-sm" x-cloak>
                <button type="button"
                        class="w-full px-4 py-3 rounded-xl bg-emerald-600 text-white font-semibold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm shadow-sm transition"
                        :class="{ 'opacity-50 pointer-events-none': cart.length===0, 'animate-pulse': cart.length > 0 }"
                        @click="openProcessModal()">
                    Proses Pembayaran
                </button>
            </div>
        </div>

        <!-- Hidden Form -->
        <form method="POST" action="{{ route('admin.cashier.checkout') }}" x-ref="checkoutForm" class="hidden">
            @csrf
            <input type="hidden" name="items" x-ref="itemsField" />
            <input type="hidden" name="table" :value="selectedTable" />
            <input type="hidden" name="service_type" :value="serviceType" />
            <input type="hidden" name="customer_name" :value="customerName" />
            <input type="hidden" name="notes" :value="notes" />
            <input type="hidden" name="payment_method" :value="paymentMethod" />
            <input type="hidden" name="payment_status" :value="effectivePaymentStatus()" />
        </form>

        <!-- Option Modal -->
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-show="modalOpen" x-transition x-cloak>
            <div class="absolute inset-0 bg-black/40" @click="closeModal()"></div>
            <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <h3 class="font-bold text-gray-900" x-text="modalProduct?.name"></h3>
                    <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-4 max-h-96 overflow-y-auto space-y-4">
                    <template x-for="opt in (modalProduct?.options || [])" :key="opt.id">
                        <div>
                            <div class="font-semibold text-gray-900 mb-2" x-text="opt.name"></div>
                            <div class="space-y-2">
                                <template x-for="v in (opt.values || [])" :key="v.id">
                                    <label class="flex items-center justify-between gap-3 p-3 rounded-xl border hover:bg-gray-50 cursor-pointer">
                                        <div class="flex items-center gap-2">
                                            <input x-show="opt.type !== 'multi'" type="radio" :name="'opt_'+opt.id" :value="v.id" x-model.number="selectionSingle[opt.id]" class="text-emerald-500" />
                                            <input x-show="opt.type === 'multi'" type="checkbox" :value="v.id" @change="toggleMulti(opt.id, v.id, $event.target.checked)" :checked="(selectionMulti[opt.id]||[]).includes(v.id)" class="text-emerald-500" />
                                            <span class="text-sm" x-text="v.name"></span>
                                        </div>
                                        <span class="text-sm font-semibold" x-show="v.price_delta !== 0" x-text="(v.price_delta > 0 ? '+' : '') + 'Rp ' + formatRp(Math.abs(v.price_delta))"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div class="flex items-center justify-between pt-4 border-t">
                        <span class="text-sm text-gray-600">Quantity</span>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="modalQty = Math.max(1, modalQty-1)" class="w-8 h-8 rounded-lg bg-gray-100 hover:bg-gray-200">-</button>
                            <span class="w-12 text-center font-semibold" x-text="modalQty"></span>
                            <button type="button" @click="modalQty = Math.min(99, modalQty+1)" class="w-8 h-8 rounded-lg bg-gray-100 hover:bg-gray-200">+</button>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 border-t bg-gray-50 flex items-center justify-between">
                    <div>
                        <div class="text-xs text-gray-500">Total Price</div>
                        <div class="text-lg font-bold">Rp <span x-text="formatRp(modalUnitPrice())"></span></div>
                    </div>
                    <button type="button" @click="confirmModalAdd()" class="px-6 py-3 rounded-xl bg-emerald-500 text-white font-semibold hover:bg-emerald-600">
                        Add to Cart
                    </button>
                </div>
            </div>
        </div>

        <!-- Process Order Modal (Multi-step) -->
        <div x-show="processModalOpen" x-cloak x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" @click="closeProcessModal()"></div>
            <div class="relative w-full max-w-xl bg-white rounded-3xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-gray-900">Proses Order</h3>
                        <div class="text-xs text-gray-500">Ikuti langkah untuk menyelesaikan order</div>
                    </div>
                    <button type="button" @click="closeProcessModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="px-6 py-4 max-h-[70vh] overflow-y-auto">
                    <!-- Step indicator (combined step 1) -->
                    <div class="flex items-center gap-2 text-xs text-gray-500 mb-4">
                        <div :class="processStep===1 ? 'font-semibold text-gray-900' : ''">1. Nama & Tipe Order</div>
                        <div>•</div>
                        <div :class="processStep===2 ? 'font-semibold text-gray-900' : ''">2. Metode Bayar</div>
                        <div>•</div>
                        <div :class="processStep===3 ? 'font-semibold text-gray-900' : ''">3. Konfirmasi</div>
                    </div>

                    <!-- Step 1: Customer name, notes & Service type (combined) -->
                    <div x-show="processStep===1" x-cloak class="space-y-3">
                        <div x-show="processError" x-text="processError" class="text-sm text-red-600 bg-red-50 border border-red-100 px-3 py-2 rounded-xl"></div>
                        <label class="text-xs font-semibold text-gray-600">Nama Pelanggan <span class="text-red-500">*</span></label>
                        <input type="text" x-ref="processCustomerName" x-model="customerName" @keydown.enter.prevent="processNext()" class="mt-1 w-full rounded-xl border-gray-200 text-sm" placeholder="Mis: Budi" />

                        <label class="text-xs font-semibold text-gray-600">Catatan (opsional)</label>
                        <textarea rows="3" x-model="notes" class="mt-1 w-full rounded-xl border-gray-200 text-sm" placeholder="Catatan untuk dapur/kasir..."></textarea>

                        <div class="pt-2">
                            <div class="text-xs font-semibold text-gray-600 mb-2">Tipe Order</div>
                            <div class="flex gap-2">
                                <button type="button" @click="setServiceType('dine_in')" :class="serviceType==='dine_in' ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-600'" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold">Makan di Tempat</button>
                                <button type="button" @click="setServiceType('takeout')" :class="serviceType==='takeout' ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-600'" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold">Bungkus</button>
                            </div>

                            <div x-show="serviceType==='dine_in'" x-cloak class="mt-3">
                                <label class="text-xs font-semibold text-gray-600">Meja (opsional)</label>
                                <select x-model="selectedTable" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                    <option value="">Walk-in (tanpa meja)</option>
                                    <template x-for="t in tables" :key="t.public_id">
                                        <option :value="t.public_id" x-text="t.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Payment methods (now step 2) -->
                    <div x-show="processStep===2" x-cloak class="space-y-3">
                        <div class="text-xs font-semibold text-gray-600">Metode Pembayaran</div>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            @foreach($paymentMethods as $k => $label)
                                <button type="button" @click="paymentMethod='{{ $k }}'" :class="paymentMethod==='{{ $k }}' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-gray-900 border-gray-200'" class="px-3 py-2 rounded-2xl border text-xs font-semibold">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <!-- Step 3: Payment status (now final step) -->
                    <div x-show="processStep===3" x-cloak class="space-y-3">
                        <div class="text-xs font-semibold text-gray-600">Status Pembayaran</div>
                        <div class="mt-2 flex gap-2">
                            <button type="button" @click="paymentStatus='paid'; paymentStatusTouched=true" :class="paymentStatus==='paid' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-gray-100 text-gray-600 border-gray-200'" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold">✓ Sudah Bayar</button>
                            <button type="button" @click="paymentStatus='unpaid'; paymentStatusTouched=true" :class="paymentStatus==='unpaid' ? 'bg-amber-500 text-white border-amber-500' : 'bg-gray-100 text-gray-600 border-gray-200'" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold">⏱ Belum Bayar</button>
                        </div>

                        <div class="mt-3 text-sm">
                            <div class="font-semibold">Ringkasan</div>
                            <div class="text-xs text-gray-500">Subtotal: Rp <span x-text="formatRp(subtotal())"></span></div>
                            <div class="text-xs text-gray-500">Jumlah item: <span x-text="cart.length"></span></div>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 border-t bg-gray-50 flex items-center justify-between gap-3">
                    <div class="flex gap-2">
                        <button type="button" class="px-4 py-2 rounded-xl bg-white text-sm font-semibold border" @click="processPrev()" x-show="processStep>1" x-cloak>Kembali</button>
                        <button type="button" class="px-4 py-2 rounded-xl bg-white text-sm font-semibold border" @click="processNext()" x-show="processStep<3" x-cloak>Lanjut</button>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="text-sm font-semibold">Total: Rp <span x-text="formatRp(subtotal())"></span></div>
                        <button type="button" class="px-4 py-2 rounded-xl bg-emerald-500 text-white font-semibold" @click="processSubmit()" :disabled="processSubmitting" x-show="processStep===3" x-cloak>
                            <span x-show="!processSubmitting">Kirim</span>
                            <span x-show="processSubmitting">Memproses…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    <script>
        function cashier(cfg) {
            return {
                // Orders count notification properties
                ordersCount: cfg.ordersCount || 0,
                realtimePublicUrl: cfg.realtimePublicUrl || '',
                pollUrl: cfg.pollUrl || '',
                ordersCountUrl: cfg.ordersCountUrl || '',
                socket: null,
                socketStatus: 'disconnected',
                pollTimer: null,
                lastPollSince: null,

                // Alarm properties
                alarmEnabled: true,
                alarmVolume: 0.25,
                alarmPreset: 'double',
                _audioCtx: null,
                _alarmUnlockHinted: false,

                // Original cashier properties
                categories: cfg.categories || [],
                tables: cfg.tables || [],
                allProducts: cfg.products || [],
                paymentMethodsObj: cfg.paymentMethods || {},
                cashierSettings: cfg.cashierSettings || {},

                q: '',
                cat: 0,
                cart: [],
                selectedTable: '',
                serviceType: 'dine_in',
                serviceTypeTouched: false,
                customerName: '',
                notes: '',
                paymentMethod: '',
                paymentStatus: 'paid',
                paymentStatusTouched: false,
                submitting: false,

                modalOpen: false,
                modalProduct: null,
                modalQty: 1,
                modalError: '',
                selectionSingle: {},
                selectionMulti: {},
                modalMode: 'add',
                editingIndex: null,

                // Process order modal state
                processModalOpen: false,
                processStep: 1,
                processSubmitting: false,
                processError: '',
                mobileReviewOpen: false,

                isMobileViewport() {
                    return window.innerWidth < 768;
                },

                openMobileReview() {
                    if (!this.cart.length) return;
                    this.mobileReviewOpen = true;
                    document.body.classList.add('overflow-hidden');
                },

                closeMobileReview() {
                    if (!this.isMobileViewport()) return;
                    this.mobileReviewOpen = false;
                    if (!this.processModalOpen && !this.modalOpen) {
                        document.body.classList.remove('overflow-hidden');
                    }
                },

                openProcessModal() {
                    console.debug('[cashier] openProcessModal() called');
                    this.processStep = 1;
                    this.processError = '';
                    this.processSubmitting = false;
                    if (!this.paymentMethod) this.paymentMethod = this.initial?.payment_method || '';
                    if (!this.paymentStatus) this.paymentStatus = this.initial?.payment_status || 'paid';
                    this.paymentStatusTouched = !!this.paymentStatus;

                    this.mobileReviewOpen = false;
                    this.processModalOpen = true;
                    document.body.classList.add('overflow-hidden');

                    this.$nextTick(() => {
                        this.$refs.processCustomerName && this.$refs.processCustomerName.focus();
                    });
                },
                closeProcessModal() {
                    this.processModalOpen = false;
                    if (!this.mobileReviewOpen && !this.modalOpen) {
                        document.body.classList.remove('overflow-hidden');
                    }
                },
                processNext() {
                    console.debug('[cashier] processNext() step=', this.processStep);
                    // basic validation per step (Step 1 now combines Customer + Service Type)
                    if (this.processStep === 1) {
                        if (!this.customerName || this.customerName.trim() === '') { this.processError = 'Nama customer wajib diisi.'; return; }
                        if (!this.serviceType) { this.processError = 'Pilih tipe order.'; return; }
                    }
                    if (this.processStep === 2) {
                        if (!this.paymentMethod) { this.processError = 'Pilih metode pembayaran.'; return; }
                    }
                    this.processError = '';
                    this.processStep = Math.min(3, this.processStep + 1);
                },

                async processSubmit(){
                    console.debug('[cashier] processSubmit() start');
                    // final validation
                    if (!this.cart.length) { this.processError = 'Keranjang kosong.'; return; }
                    if (!this.serviceType) { this.processError = 'Pilih tipe order.'; return; }
                    if (!this.customerName || this.customerName.trim()==='') { this.processError = 'Nama customer wajib diisi.'; return; }
                    if (!this.paymentMethod) { this.processError = 'Pilih metode pembayaran.'; return; }
                    if (this.paymentMethod !== 'qris_dynamic' && !this.paymentStatusTouched) { this.processError = 'Pilih status pembayaran: Paid atau Unpaid.'; return; }

                    this.processSubmitting = true;
                    // set items and hidden fields like submitOrder does
                    const items = this.cart.map(it => ({ product_id: Number(it.product_id), qty: Number(it.qty), options: it.options || [] }));
                    this.$refs.itemsField.value = JSON.stringify(items);
                    // ensure hidden fields populated
                    this.$refs.checkoutForm.querySelector('input[name="table"]').value = this.selectedTable;
                    this.$refs.checkoutForm.querySelector('input[name="service_type"]').value = this.serviceType;
                    this.$refs.checkoutForm.querySelector('input[name="customer_name"]').value = this.customerName;
                    this.$refs.checkoutForm.querySelector('input[name="notes"]').value = this.notes;
                    this.$refs.checkoutForm.querySelector('input[name="payment_method"]').value = this.paymentMethod;
                    this.$refs.checkoutForm.querySelector('input[name="payment_status"]').value = this.effectivePaymentStatus();

                    // submit the form (server will handle unpaid/new logic & redirect to orders)
                    console.debug('[cashier] Submitting checkout form');
                    this.$refs.checkoutForm.submit();
                },


                processPrev(){ this.processError=''; this.processStep = Math.max(1, this.processStep - 1); },

                init() {
                    // Initialize orders count notification
                    this.initRealtime();
                    this.startPolling();

                    // Load alarm settings
                    this.loadAlarmSettings();

                    // Listen for global open-process events (fallback when Alpine click binding has issues)
                    // Removed: no longer needed

                    // Attach click listeners to static buttons (CSP-safe) as Alpine may not evaluate inline expressions under strict CSP
                    // Removed: Alpine @click should be sufficient


                    // Initialize cashier functionality
                    const init = cfg.initial || {};
                    this.customerName = String(init.customer_name || '');
                    this.paymentMethod = String(init.payment_method || '');
                    this.paymentStatus = String(init.payment_status || 'paid');
                    this.paymentStatusTouched = false;
                    this.serviceType = String(init.service_type || 'dine_in');
                    this.serviceTypeTouched = false;
                    if (this.paymentMethod === 'qris_dynamic') {
                        this.paymentStatusTouched = true;
                        this.paymentStatus = 'unpaid';
                        return;
                    }

                    // When default method is already selected, consider payment status chosen
                    // so the cashier doesn't think the process button is missing.
                    if (this.paymentMethod && (this.paymentStatus === 'paid' || this.paymentStatus === 'unpaid')) {
                        this.paymentStatusTouched = true;
                    }

                    window.addEventListener('resize', () => {
                        if (this.isMobileViewport()) {
                            this.mobileReviewOpen = false;
                            if (!this.processModalOpen && !this.modalOpen) {
                                document.body.classList.remove('overflow-hidden');
                            }
                            return;
                        }

                        this.mobileReviewOpen = false;
                        if (!this.processModalOpen && !this.modalOpen) {
                            document.body.classList.remove('overflow-hidden');
                        }
                    });
                },

                // Alarm methods
                loadAlarmSettings() {
                    const enabled = localStorage.getItem('alarmEnabled');
                    const volume = localStorage.getItem('alarmVolume');
                    const preset = localStorage.getItem('alarmPreset');

                    this.alarmEnabled = enabled !== null ? JSON.parse(enabled) : true;
                    this.alarmVolume = volume !== null ? parseFloat(volume) : 0.25;
                    this.alarmPreset = preset !== null ? preset : 'double';
                },

                saveAlarmSettings() {
                    localStorage.setItem('alarmEnabled', JSON.stringify(this.alarmEnabled));
                    localStorage.setItem('alarmVolume', this.alarmVolume.toString());
                    localStorage.setItem('alarmPreset', this.alarmPreset);
                },

                toggleAlarm() {
                    this.alarmEnabled = !this.alarmEnabled;
                    this.saveAlarmSettings();
                },

                ensureAudioContext() {
                    if (!this._audioCtx) {
                        this._audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    }
                    if (this._audioCtx.state === 'suspended') {
                        this._audioCtx.resume();
                    }
                },

                beep(frequency, duration, volume = this.alarmVolume) {
                    this.ensureAudioContext();
                    const oscillator = this._audioCtx.createOscillator();
                    const gainNode = this._audioCtx.createGain();

                    oscillator.connect(gainNode);
                    gainNode.connect(this._audioCtx.destination);

                    oscillator.frequency.value = frequency;
                    oscillator.type = 'sine';

                    gainNode.gain.setValueAtTime(volume, this._audioCtx.currentTime);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, this._audioCtx.currentTime + duration);

                    oscillator.start(this._audioCtx.currentTime);
                    oscillator.stop(this._audioCtx.currentTime + duration);
                },

                playAlarm() {
                    if (!this.alarmEnabled) return;

                    this.ensureAudioContext();

                    if (this._audioCtx.state === 'suspended' && !this._alarmUnlockHinted) {
                        // Show hint to user about needing to interact with page
                        this._alarmUnlockHinted = true;
                        alert('Audio alarm requires page interaction first. Please click anywhere on the page to enable audio.');
                        return;
                    }

                    const preset = this.alarmPreset;
                    if (preset === 'single') {
                        this.beep(800, 0.3);
                    } else if (preset === 'double') {
                        this.beep(800, 0.3);
                        setTimeout(() => this.beep(800, 0.3), 200);
                    } else if (preset === 'triple') {
                        this.beep(800, 0.3);
                        setTimeout(() => this.beep(800, 0.3), 200);
                        setTimeout(() => this.beep(800, 0.3), 400);
                    } else if (preset === 'ascending') {
                        this.beep(600, 0.2);
                        setTimeout(() => this.beep(700, 0.2), 150);
                        setTimeout(() => this.beep(800, 0.2), 300);
                    } else if (preset === 'descending') {
                        this.beep(800, 0.2);
                        setTimeout(() => this.beep(700, 0.2), 150);
                        setTimeout(() => this.beep(600, 0.2), 300);
                    }
                },

                testAlarm() {
                    this.playAlarm();
                },

                // Orders count notification methods
                initRealtime() {
                    if (!this.realtimePublicUrl) return;

                    try {
                        this.ensureSocketIoLoaded().then(() => {
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
                                this.updateOrdersCount();
                                // Play alarm for new orders
                                if (payload && payload.order && payload.order.source === 'self_order') {
                                    this.playAlarm();
                                }
                            });

                            socket.on('order.updated', (payload) => {
                                this.updateOrdersCount();
                            });
                        }).catch((err) => {
                            console.debug('Failed to load Socket.IO client', err);
                            this.socketStatus = 'error';
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
                    }, 10000); // Poll every 10 seconds for cashier index
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

                        // Update count if there are new orders
                        if (data?.orders && data.orders.length > 0) {
                            this.updateOrdersCount();
                            // Play alarm for new self orders
                            const hasNewSelfOrder = data.orders.some(order => order.source === 'self_order');
                            if (hasNewSelfOrder) {
                                this.playAlarm();
                            }
                        }
                    } catch (e) {
                        // ignore
                    }
                },

                async updateOrdersCount() {
                    try {
                        // Fetch from dedicated orders count endpoint
                        const res = await fetch(this.ordersCountUrl, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const data = await res.json();
                        if (data && typeof data.ordersCount === 'number') {
                            this.ordersCount = data.ordersCount;
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

                // Original cashier methods
                setServiceType(next) {
                    this.serviceType = next;
                    this.serviceTypeTouched = true;
                    if (next === 'takeout') {
                        this.selectedTable = '';
                    }
                },

                onPaymentMethodChanged() {
                    // Force unpaid for dynamic QR and show process button immediately.
                    if (this.paymentMethod === 'qris_dynamic') {
                        this.paymentStatusTouched = true;
                        this.paymentStatus = 'unpaid';
                        return;
                    }
                    // Require cashier to explicitly pick paid/unpaid per order (UX).
                    this.paymentStatusTouched = false;
                },

                canProcessOrder() {
                    if (this.submitting) return false;
                    if (!this.cart.length) return false;
                    if (!this.paymentMethod) return false;
                    if (!this.serviceTypeTouched) return false;
                    if (!this.customerName || this.customerName.trim() === '') return false;
                    if (this.paymentMethod !== 'qris_dynamic' && !this.paymentStatusTouched) return false;

                    const requirePaidBeforeSubmit = !!(this.cashierSettings.require_paid_before_submit);
                    const effective = this.effectivePaymentStatus();
                    if (requirePaidBeforeSubmit && this.paymentMethod !== 'qris_dynamic' && effective !== 'paid') return false;
                    return true;
                },

                processButtonLabel() {
                    if (!this.paymentMethod) return 'Pilih metode pembayaran';
                    if (this.paymentMethod !== 'qris_dynamic' && !this.paymentStatusTouched) return 'Pilih status (Paid/Unpaid)';

                    const effective = this.effectivePaymentStatus();
                    const autoComplete = !!(this.cashierSettings.auto_complete_when_paid);

                    if (this.paymentMethod === 'qris_dynamic') {
                        return 'Buat QR & Proses Pesanan';
                    }

                    if (effective === 'paid') {
                        return autoComplete ? 'Proses & Complete' : 'Proses Pesanan (Paid)';
                    }
                    return 'Proses Pesanan (Unpaid)';
                },

                get filteredProducts() {
                    const q = (this.q || '').toLowerCase().trim();
                    const cat = Number(this.cat || 0);
                    return this.allProducts.filter(p => {
                        if (q && !(p.name || '').toLowerCase().includes(q)) return false;
                        if (cat && !((p.category_ids || []).includes(cat))) return false;
                        return true;
                    });
                },

                formatRp(v) {
                    const n = Number(v);
                    if (!isFinite(n)) return '0';
                    try { return new Intl.NumberFormat('id-ID').format(Math.round(n)); }
                    catch(e){ return String(Math.round(n)); }
                },

                hasRequiredOptions(p) {
                    return (p.options || []).some(o => o.is_required);
                },

                addOrConfigure(p) {
                    const options = p.options || [];
                    if (options.length === 0) {
                        this.addToCart(p, [], 1);
                        return;
                    }
                    this.openModal(p);
                },

                openModal(p) {
                    this.modalProduct = p;
                    this.modalQty = 1;
                    this.modalError = '';
                    this.selectionSingle = {};
                    this.selectionMulti = {};
                    this.modalMode = 'add';
                    this.editingIndex = null;

                    (p.options || []).forEach(opt => {
                        if (opt.type === 'multi') {
                            this.selectionMulti[opt.id] = [];
                        } else {
                            this.selectionSingle[opt.id] = null;
                        }
                    });

                    this.modalOpen = true;
                    try { document.body.classList.add('overflow-hidden'); } catch (e) {}
                },

                closeModal() {
                    this.modalOpen = false;
                    this.modalProduct = null;
                    try {
                        if (!this.processModalOpen && !this.mobileReviewOpen) {
                            document.body.classList.remove('overflow-hidden');
                        }
                    } catch (e) {}
                },

                toggleMulti(optionId, valueId, checked) {
                    optionId = Number(optionId);
                    valueId = Number(valueId);
                    const arr = Array.isArray(this.selectionMulti[optionId]) ? this.selectionMulti[optionId] : [];
                    const has = arr.includes(valueId);
                    if (checked && !has) arr.push(valueId);
                    if (!checked && has) arr.splice(arr.indexOf(valueId), 1);
                    this.selectionMulti[optionId] = arr;
                },

                modalSelectedOptions() {
                    const p = this.modalProduct;
                    if (!p) return [];
                    const out = [];
                    (p.options || []).forEach(opt => {
                        if (opt.type === 'multi') {
                            const ids = (this.selectionMulti[opt.id] || []).slice().map(Number).filter(Boolean).sort((a,b) => a-b);
                            if (ids.length) out.push({ option_id: Number(opt.id), value_ids: ids });
                        } else {
                            const id = Number(this.selectionSingle[opt.id] || 0);
                            if (id) out.push({ option_id: Number(opt.id), value_id: id });
                        }
                    });
                    out.sort((a,b) => (a.option_id||0) - (b.option_id||0));
                    return out;
                },

                modalUnitPrice() {
                    const p = this.modalProduct;
                    if (!p) return 0;
                    let total = Number(p.price || 0);
                    const selected = this.modalSelectedOptions();
                    (p.options || []).forEach(opt => {
                        const valuesMap = {};
                        (opt.values || []).forEach(v => valuesMap[Number(v.id)] = Number(v.price_delta || 0));
                        if (opt.type === 'multi') {
                            const row = selected.find(r => Number(r.option_id) === Number(opt.id));
                            const ids = (row && row.value_ids) ? row.value_ids : [];
                            ids.forEach(id => { total += Number(valuesMap[Number(id)] || 0); });
                        } else {
                            const row = selected.find(r => Number(r.option_id) === Number(opt.id));
                            const id = row ? Number(row.value_id || 0) : 0;
                            if (id) total += Number(valuesMap[id] || 0);
                        }
                    });
                    return Math.max(0, Math.round(total));
                },

                confirmModalAdd() {
                    const p = this.modalProduct;
                    const options = this.modalSelectedOptions();
                    this.addToCart(p, options, this.modalQty);
                    this.closeModal();
                },

                signature(options) {
                    try {
                        const normalized = (options || []).map(r => {
                            const o = { option_id: Number(r.option_id || 0) };
                            if (Array.isArray(r.value_ids)) {
                                const ids = r.value_ids.slice().map(Number).filter(Boolean).sort((a,b)=>a-b);
                                o.value_ids = ids;
                            } else {
                                o.value_id = Number(r.value_id || 0);
                            }
                            return o;
                        }).sort((a,b)=>a.option_id-b.option_id);
                        return JSON.stringify(normalized);
                    } catch(e) {
                        return '';
                    }
                },

                optionLabels(p, options) {
                    const mapByOpt = {};
                    (p.options || []).forEach(opt => {
                        const mapV = {};
                        (opt.values || []).forEach(v => mapV[Number(v.id)] = v);
                        mapByOpt[Number(opt.id)] = { opt, mapV };
                    });
                    const parts = [];
                    (options || []).forEach(r => {
                        const oid = Number(r.option_id || 0);
                        const holder = mapByOpt[oid];
                        if (!holder) return;
                        if (Array.isArray(r.value_ids)) {
                            const names = r.value_ids.map(id => holder.mapV[Number(id)]?.name).filter(Boolean);
                            if (names.length) parts.push(holder.opt.name + ': ' + names.join(', '));
                        } else if (r.value_id) {
                            const name = holder.mapV[Number(r.value_id)]?.name;
                            if (name) parts.push(holder.opt.name + ': ' + name);
                        }
                    });
                    return parts.join(' · ');
                },

                computeUnitPrice(p, options) {
                    let total = Number(p.price || 0);
                    const selected = options || [];
                    (p.options || []).forEach(opt => {
                        const valuesMap = {};
                        (opt.values || []).forEach(v => valuesMap[Number(v.id)] = Number(v.price_delta || 0));
                        const row = selected.find(r => Number(r.option_id) === Number(opt.id));
                        if (!row) return;
                        if (Array.isArray(row.value_ids)) {
                            (row.value_ids || []).forEach(id => { total += Number(valuesMap[Number(id)] || 0); });
                        } else if (row.value_id) {
                            total += Number(valuesMap[Number(row.value_id)] || 0);
                        }
                    });
                    return Math.max(0, Math.round(total));
                },

                addToCart(p, options, qty) {
                    const unitPrice = this.computeUnitPrice(p, options);
                    const sig = this.signature(options);
                    const key = p.id + '|' + sig;
                    const existing = this.cart.find(x => x.key === key);
                    if (existing) {
                        existing.qty = Math.min(99, Number(existing.qty || 0) + Number(qty || 1));
                        try {
                            const ui = (typeof cashierEnsureUi === 'function') ? cashierEnsureUi() : (window.cashierUi || null);
                            if (ui && typeof ui.toast === 'function') {
                                ui.toast(`Berhasil menambahkan pesanan: ${p.name}`, 'success');
                            }
                        } catch (e) {}
                        return;
                    }
                    this.cart.push({
                        key,
                        product_id: Number(p.id),
                        name: p.name,
                        qty: Math.max(1, Math.min(99, Number(qty || 1))),
                        options,
                        optionsLabel: this.optionLabels(p, options),
                        unitPrice,
                    });
                    try {
                        const ui = (typeof cashierEnsureUi === 'function') ? cashierEnsureUi() : (window.cashierUi || null);
                        if (ui && typeof ui.toast === 'function') {
                            ui.toast(`Berhasil menambahkan pesanan: ${p.name}`, 'success');
                        }
                    } catch (e) {}
                },

                incQty(i) { this.cart[i].qty = Math.min(99, Number(this.cart[i].qty || 0) + 1); },
                decQty(i) {
                    this.cart[i].qty = Math.max(1, Number(this.cart[i].qty || 1) - 1);
                    if (this.cart[i].qty <= 0) this.cart.splice(i, 1);
                },
                removeItem(i) { this.cart.splice(i, 1); },
                clearCart() { this.cart = []; },

                subtotal() {
                    return this.cart.reduce((sum, it) => sum + (Number(it.unitPrice || 0) * Number(it.qty || 0)), 0);
                },

                effectivePaymentStatus() {
                    if (this.paymentMethod === 'qris_dynamic') return 'unpaid';
                    return this.paymentStatus || 'unpaid';
                },

                submitOrder() {
                    if (this.submitting) return;
                    if (!this.cart.length) return;
                    if (!this.paymentMethod) {
                        alert('Pilih metode pembayaran terlebih dahulu');
                        return;
                    }
                    if (!this.serviceTypeTouched) {
                        alert('Pilih tipe pesanan: Makan di Tempat atau Bungkus');
                        return;
                    }
                    if (!this.customerName || this.customerName.trim() === '') {
                        alert('Nama customer wajib diisi');
                        return;
                    }

                    this.submitting = true;
                    const items = this.cart.map(it => ({
                        product_id: Number(it.product_id),
                        qty: Number(it.qty),
                        options: it.options || [],
                    }));
                    this.$refs.itemsField.value = JSON.stringify(items);

                    const form = this.$refs.checkoutForm;
                    if (form && typeof form.submit === 'function') {
                        form.submit();
                        return;
                    }
                    this.submitting = false;
                },

                chooseAndProcess(nextStatus) {
                    this.paymentStatus = nextStatus;
                    this.paymentStatusTouched = true;
                    if (this.canProcessOrder()) {
                        this.submitOrder();
                    }
                },

                confirmPaymentAndSubmit(){
                    // Called from inline cart (mobile/compact) Place Order button
                    if (this.paymentMethod !== 'qris_dynamic' && !this.paymentStatusTouched) {
                        alert('Pilih status pembayaran (Paid / Unpaid) terlebih dahulu.');
                        return;
                    }

                    // Ensure items are set and submit using same flow
                    this.submitOrder();
                },

                // Next-action helper for visual hints
                getNextActionName() {
                    // If cart empty, we expect cashier to add items elsewhere (visual hint of categories/products exists)
                    if (!this.cart.length) return 'addItems';
                    if (!this.serviceTypeTouched) return 'serviceType';
                    if (!this.customerName || this.customerName.trim() === '') return 'customerName';
                    if (!this.paymentMethod) return 'paymentMethod';
                    if (this.paymentMethod !== 'qris_dynamic' && !this.paymentStatusTouched) return 'paymentStatus';
                    if (!this.canProcessOrder()) return 'process';
                    return null;
                },

                isNext(name) { try{ return String(this.getNextActionName()) === String(name); }catch(e){ return false; } },
            }
        }
    </script>
    <style>
        @media (max-width: 767px) {
            #cashier-shell { flex-direction: column !important; height: 100% !important; }
            #cashier-main-panel { flex: 1 1 auto !important; min-height: 0 !important; }

            #cashier-mobile-cartfab-wrap {
                position: fixed !important;
                left: 50% !important;
                transform: translateX(-50%) !important;
                bottom: calc(1rem + env(safe-area-inset-bottom, 0px)) !important;
                z-index: 55 !important;
            }

            #cashier-mobile-cartfab {
                width: 3.5rem !important;
                height: 3.5rem !important;
                border-radius: 9999px !important;
                box-shadow: 0 12px 24px rgba(15, 23, 42, 0.28) !important;
            }

            #cashier-detail-panel.cashier-mobile-review-hidden {
                display: none !important;
            }

            #cashier-detail-panel {
                position: fixed !important;
                inset: auto 0 0 0 !important;
                z-index: 50 !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                max-height: 82vh !important;
                min-height: 220px;
                border-top: 1px solid rgb(229 231 235) !important;
                border-left: 0 !important;
                border-top-left-radius: 1rem;
                border-top-right-radius: 1rem;
                box-shadow: 0 -10px 30px rgba(15, 23, 42, 0.2);
            }

            #cashier-detail-panel .shadow-inner {
                padding-bottom: 0.75rem;
            }
        }

        @media (min-width: 768px) {
            #cashier-shell { flex-direction: row !important; height: 100% !important; }
            #cashier-main-panel { flex: 1 1 auto !important; min-width: 0 !important; }
            #cashier-detail-panel {
                width: 20rem !important;
                min-width: 20rem !important;
                max-width: 20rem !important;
                max-height: none !important;
                min-height: 0 !important;
                border-left: 1px solid rgb(229 231 235) !important;
                border-top: 0 !important;
            }
        }
    </style>

</body>
</html>