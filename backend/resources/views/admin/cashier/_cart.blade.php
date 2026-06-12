@php
    /** @var \App\Models\PaymentSetting|null $paymentSetting */
    /** @var array<string,string> $paymentMethods */
@endphp

<div class="bg-white overflow-hidden shadow-sm rounded-2xl">
    <div class="p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="font-semibold text-gray-900">Cart</div>
                <div class="text-xs text-gray-500" x-text="cart.length ? cart.length + ' item' : 'Kosong'"></div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="px-3 py-2 rounded-xl border bg-white text-xs font-semibold" @click="clearCart()" :disabled="cart.length===0">Clear</button>
                <button type="button" class="px-3 py-2 rounded-xl border bg-white text-xs font-semibold lg:hidden" @click="cartOpen = false">Tutup</button>
            </div>
        </div>

        <div class="mt-4 grid gap-2">
            <template x-for="(it, idx) in cart" :key="it.key">
                <div class="rounded-2xl border border-gray-200 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-sm text-gray-900 truncate" x-text="it.name"></div>
                            <div class="text-xs text-gray-500" x-show="it.optionsLabel" x-cloak x-text="it.optionsLabel"></div>
                            <div class="mt-1 text-xs text-gray-500">Rp <span x-text="formatRp(it.unitPrice)"></span> × <span x-text="it.qty"></span></div>
                        </div>
                        <div class="text-sm font-bold text-gray-900">Rp <span x-text="formatRp(it.unitPrice*it.qty)"></span></div>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-2">
                        <div class="inline-flex rounded-xl border overflow-hidden">
                            <button type="button" class="px-3 py-2 text-xs font-semibold bg-white hover:bg-gray-50" @click="decQty(idx)">-</button>
                            <div class="px-3 py-2 text-xs font-semibold bg-gray-50" x-text="it.qty"></div>
                            <button type="button" class="px-3 py-2 text-xs font-semibold bg-white hover:bg-gray-50" @click="incQty(idx)">+</button>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" class="px-3 py-2 rounded-xl border bg-white text-xs font-semibold" @click="editItem(idx)">Edit</button>
                            <button type="button" class="px-3 py-2 rounded-xl border bg-white text-xs font-semibold text-red-600 hover:text-red-700" @click="removeItem(idx)">Hapus</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-4 border-t pt-4">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-600">Subtotal</div>
                <div class="text-lg font-bold text-gray-900">Rp <span x-text="formatRp(subtotal())"></span></div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.cashier.checkout') }}" class="mt-4" @submit.prevent>
            @csrf

            <input type="hidden" name="items" x-ref="itemsField" />
            <input type="hidden" name="table" :value="selectedTable" />
            <input type="hidden" name="table_label" :value="tableLabel" />
            <input type="hidden" name="service_type" :value="serviceType" />
            <input type="hidden" name="customer_name" :value="customerName" />
            <input type="hidden" name="notes" :value="notes" />
            <input type="hidden" name="payment_method" :value="paymentMethod" />
            <input type="hidden" name="payment_status" :value="paymentStatus" />
        </form>
    <!-- Fallback overlay (non-Alpine) -->
    <style>
        /* CSP-safe fallback: allow showing overlay via hash (#fallback-process-modal) */
        #fallback-process-modal { display: none; }
        #fallback-process-modal:target { display: flex !important; align-items: center; justify-content: center; }
    </style>
    <div id="fallback-process-modal" style="position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div style="background:white; padding:20px; border-radius:12px; max-width:480px; margin:0 16px; text-align:left;">
            <h3 style="font-weight:700; margin-bottom:8px;">Proses Order (Fallback)</h3>
            <p style="font-size:13px; color:#374151;">Jika modal utama gagal muncul, gunakan fallback ini untuk melanjutkan.</p>
            <div style="margin-top:12px; display:flex; gap:8px; justify-content:flex-end;">
                <button id="fallback-close" style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#fff">Close</button>
            </div>
        </div>
    </div>