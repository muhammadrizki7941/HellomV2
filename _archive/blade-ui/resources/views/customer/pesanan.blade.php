@php
    /** @var ?\App\Models\DiningTable $table */
@endphp

<x-customer-layout :showHeader="false">
    <div x-data="pesananPage(@js([
        'tableToken' => (string) ($tableToken ?? ''),
        'tableLabel' => $table ? ($table->code ?? $table->name ?? $table->public_id) : '',
        'tableId' => $table?->id,
        'initialPendingOrder' => $pendingOrder,
        'pendingUrl' => route('order.pending'),
        'orderPageUrl' => route('order.page', array_filter(['table' => request('table')])),
        'realtimePublicUrl' => (string) ($realtimePublicUrl ?? ''),
    ]))" x-init="init()" class="pt-4">

        <!-- Toast (pesanan) - must live inside x-data scope -->
        <div class="fixed top-4 inset-x-0 z-[9999] flex items-center justify-center px-4"
            x-show="toastOpen && (toastTitle || toastMessage)" x-transition x-cloak>
            <div class="w-full max-w-md rounded-3xl border shadow-xl bg-white p-4"
                :class="toastType==='error' ? 'border-rose-200' : (toastType==='preparing' ? 'border-purple-200' : 'border-emerald-200')">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold"
                            :class="toastType==='error' ? 'text-rose-800' : (toastType==='preparing' ? 'text-purple-800' : 'text-emerald-800')"
                            x-text="toastTitle"></div>
                        <div class="mt-1 text-sm text-slate-700" x-text="toastMessage"></div>
                    </div>
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-900" @click.stop="toastOpen=false">Tutup</button>
                </div>
                <div class="mt-3 h-1.5 rounded-full overflow-hidden bg-slate-100">
                    <div class="h-full"
                        :class="toastType==='error' ? 'bg-rose-500 animate-pulse' : (toastType==='preparing' ? 'bg-purple-500 animate-pulse' : 'bg-emerald-500 animate-pulse')"
                        style="width: 100%"></div>
                </div>
            </div>
        </div>

        <div class="flex items-start justify-between gap-3" x-transition.opacity.duration.300ms>
            <div>
                <div class="text-xs font-semibold" style="color: var(--secondary-color)">PESANAN</div>
                <div class="text-2xl font-extrabold tracking-tight text-slate-900">Pesanan kamu</div>
                <div class="mt-1 text-sm text-slate-600" x-show="tableToken">
                    Meja <span class="font-semibold" x-text="tableLabel || tableToken"></span>
                </div>
            </div>
            <button type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                style="border-radius: var(--button-radius)" @click="refreshPendingOrder()" :disabled="loading">
                <span x-show="!loading">Refresh</span>
                <span x-show="loading" x-cloak>Memuat…</span>
            </button>
        </div>

        <!-- If no table token: guide user clearly -->
        <div class="mt-5 rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm" x-show="!tableToken" x-cloak
            x-transition:enter="transition duration-500 ease-out" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
            <div class="flex items-start gap-3">
                <div class="h-10 w-10 rounded-2xl grid place-items-center text-white font-black bg-amber-900">QR</div>
                <div class="flex-1">
                    <div class="font-extrabold text-amber-950">Silahkan scan QR meja Anda dahulu</div>
                    <div class="mt-1 text-sm text-amber-900/90">Biar sistem tahu kamu order dari meja mana. Setelah scan, status pesanan akan muncul di sini.</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a :href="orderPageUrl" class="inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-sm font-bold text-white"
                            :style="'background: var(--primary-color); border-radius: var(--button-radius)'"><span>Scan dari halaman Menu</span><span>→</span></a>
                        <a :href="orderPageUrl + '#menu'" class="inline-flex items-center gap-2 rounded-2xl border border-amber-200 bg-white px-4 py-2 text-sm font-semibold text-amber-950 hover:bg-amber-100/40"
                            style="border-radius: var(--button-radius)">Lihat menu dulu</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div class="mt-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" x-show="isEmpty()" x-cloak
            x-transition:enter="transition duration-500 ease-out" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
            <div class="flex items-start gap-4">
                <div class="h-12 w-12 rounded-3xl grid place-items-center text-white font-black" style="background: var(--primary-color)">🧾</div>
                <div class="flex-1">
                    <div class="text-lg font-bold text-slate-900">kamu belum pesan apa pun, yuk pesan sekarang</div>
                    <div class="mt-1 text-sm text-slate-600">Pilih menu favoritmu, lalu checkout. Pesananmu akan muncul di sini.</div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a :href="orderPageUrl + '#menu'" class="inline-flex items-center gap-2 rounded-2xl px-5 py-3 text-sm font-semibold text-white"
                            :style="'background: var(--primary-color); border-radius: var(--button-radius)'"><span>Yuk pesan sekarang</span><span>→</span></a>
                        <a :href="orderPageUrl + '#menu'" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            style="border-radius: var(--button-radius)">Lihat menu</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending order -->
        <div class="mt-5 rounded-3xl bg-white p-6 shadow-sm border border-slate-100" x-show="pendingOrder && isPendingStatus(pendingOrder.status)" x-cloak
            x-transition:enter="transition duration-500 ease-out" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs text-slate-500">Nomor order</div>
                    <div class="text-lg font-semibold" x-text="pendingOrder?.order_number || '-' "></div>
                    <div class="mt-1 text-xs text-slate-500" x-show="pendingOrder?.created_at" x-text="formatDateTime(pendingOrder?.created_at)"></div>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold border" :class="statusClass(pendingOrder?.status)" x-text="pendingOrder?.status"></span>
            </div>

            <!-- Animated status highlight -->
            <div class="mt-4 rounded-2xl border p-4"
                :class="statusTone(pendingOrder?.status).box">
                <div class="flex items-start gap-3">
                    <div class="h-11 w-11 rounded-2xl grid place-items-center text-xl"
                        :class="statusTone(pendingOrder?.status).icon">
                        <span class="inline-block" :class="statusTone(pendingOrder?.status).anim" x-text="statusTone(pendingOrder?.status).emoji"></span>
                    </div>
                    <div class="flex-1">
                        <div class="font-extrabold" :class="statusTone(pendingOrder?.status).title" x-text="statusTone(pendingOrder?.status).titleText"></div>
                        <div class="mt-1 text-sm" :class="statusTone(pendingOrder?.status).desc" x-text="statusTone(pendingOrder?.status).descText"></div>
                        <div class="mt-3 h-1.5 rounded-full overflow-hidden bg-white/60">
                            <div class="h-full" :class="statusTone(pendingOrder?.status).bar" :style="'width: ' + statusPercent(pendingOrder?.status) + '%'" style="transition: width 600ms ease"></div>
                        </div>
                    </div>
                </div>

                <!-- Stepper -->
                <div class="mt-4 grid grid-cols-4 gap-2 text-[11px] font-semibold">
                    <template x-for="step in statusSteps" :key="step.key">
                        <div class="rounded-2xl border px-2 py-2 text-center"
                            :class="stepClass(step.key, pendingOrder?.status)">
                            <div class="font-extrabold" x-text="step.label"></div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-4 grid gap-2">
                <template x-for="it in (pendingOrder?.items || [])" :key="it.product_name + '_' + it.qty">
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3 flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium" x-text="it.product_name"></div>
                            <div class="text-xs text-slate-500">Rp <span x-text="formatRp(it.unit_price)"></span> × <span x-text="it.qty"></span></div>
                            <template x-if="(it.options || []).length">
                                <div class="mt-1 text-xs text-slate-600" x-text="(it.options || []).map(o => o.option_name + ': ' + o.value_name).join(' · ')"></div>
                            </template>
                        </div>
                        <div class="font-semibold">Rp <span x-text="formatRp(it.line_total)"></span></div>
                    </div>
                </template>
            </div>

            <div class="mt-4 flex items-center justify-between">
                <div class="text-sm text-slate-600">Total: <span class="font-semibold text-slate-900">Rp <span x-text="formatRp(pendingOrder?.total_amount || 0)"></span></span></div>
                <a :href="orderPageUrl + '#menu'" class="text-xs font-semibold underline text-slate-700 hover:text-slate-900">Tambah menu</a>
            </div>
        </div>

        <!-- Auto QR Code Section (shows after 1 minute when order is completed) -->
        <div class="mt-5 rounded-3xl bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 p-6" x-show="showQrCode && pendingOrder?.status === 'completed'" x-transition x-cloak>
            <div class="text-center">
                <div class="text-lg font-semibold text-blue-900 mb-2">Scan untuk Order Lagi</div>
                <div class="text-sm text-blue-700 mb-4">QR code untuk kembali ke menu order di meja ini</div>
                
                <div class="inline-block bg-white p-4 rounded-2xl border border-blue-200 shadow-sm">
                    {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(200)->margin(2)->generate(url('/t/' . request()->route('tenant') . '/order?table=' . ($tableToken ?? ''))) !!}
                </div>
                
                <div class="mt-4 text-xs text-blue-600">
                    Atau klik <a :href="orderPageUrl" class="underline font-semibold">link ini</a> untuk order lagi
                </div>
            </div>
        </div>

        <!-- Countdown Timer (shows before QR code when order is completed) -->
        <div class="mt-5 rounded-3xl bg-slate-50 border border-slate-200 p-6 text-center" x-show="!showQrCode && pendingOrder?.status === 'completed'" x-transition x-cloak>
            <div class="text-lg font-semibold text-slate-900 mb-2">QR Code Akan Muncul</div>
            <div class="text-sm text-slate-600 mb-4">Untuk memudahkan order lagi, QR code akan muncul otomatis dalam:</div>
            
            <div class="text-3xl font-bold text-slate-900 mb-4" x-text="formatCountdown(countdownSeconds)"></div>
            
            <div class="text-xs text-slate-500">
                Atau klik <a :href="orderPageUrl" class="underline font-semibold">Order Lagi</a> sekarang
            </div>
        </div>

        
    </div>

    <script>
        function pesananPage(cfg) {
            return {
                tableToken: cfg.tableToken || '',
                tableLabel: cfg.tableLabel || '',
                tableId: cfg.tableId || null,
                pendingOrder: cfg.initialPendingOrder || null,
                pendingUrl: cfg.pendingUrl,
                orderPageUrl: cfg.orderPageUrl,
                realtimePublicUrl: cfg.realtimePublicUrl || '',
                loading: false,

                pollTimer: null,
                lastStatus: null,

                socket: null,
                connected: false,

                toastOpen: false,
                toastType: 'preparing',
                toastTitle: '',
                toastMessage: '',
                toastTimer: null,

                // QR Code auto-show timer for completed orders
                showQrCode: false,
                countdownSeconds: 60, // 1 minute
                countdownTimer: null,

                statusSteps: [
                    { key: 'new', label: 'Dibuat' },
                    { key: 'accepted', label: 'Diterima' },
                    { key: 'preparing', label: 'Disiapkan' },
                    { key: 'completed', label: 'Selesai' },
                ],

                init() {
                    // Try to get table token from localStorage if not provided by server
                    if (!this.tableToken) {
                        try {
                            const storedToken = localStorage.getItem('tableToken');
                            if (storedToken) {
                                this.tableToken = storedToken;
                                // Update the orderPageUrl to include the table token
                                this.orderPageUrl = this.orderPageUrl.split('?')[0] + '?table=' + encodeURIComponent(storedToken);
                            }
                        } catch (e) {}
                    }

                    // If a table token exists, auto-refresh once on load
                    if (this.tableToken) {
                        this.lastStatus = this.pendingOrder?.status || null;
                        this.refreshPendingOrder();
                        this.startPolling();

                        // Start QR countdown if order is already completed
                        if (this.pendingOrder?.status === 'completed') {
                            this.startQrCountdown();
                        }
                    }

                    // Realtime updates (matches admin cashier updates). Polling stays as fallback.
                    this.trySocket(this.realtimePublicUrl);
                },

                startPolling() {
                    try { if (this.pollTimer) clearInterval(this.pollTimer); } catch (e) {}
                    this.pollTimer = setInterval(() => {
                        this.refreshPendingOrder(true);
                    }, 6000);
                },

                stopPolling() {
                    try { if (this.pollTimer) clearInterval(this.pollTimer); } catch (e) {}
                    this.pollTimer = null;
                },

                showToast(type, title, message) {
                    this.toastType = type;
                    this.toastTitle = title;
                    this.toastMessage = message;
                    this.toastOpen = true;
                    try { if (this.toastTimer) clearTimeout(this.toastTimer); } catch (e) {}
                    this.toastTimer = setTimeout(() => { this.toastOpen = false; }, 4200);
                },

                isoMs(iso) {
                    if (!iso) return null;
                    const t = Date.parse(String(iso));
                    return Number.isFinite(t) ? t : null;
                },

                applyOrderUpdate(payload) {
                    if (!payload) return;

                    const incomingOrderNumber = String(payload.order_number || '');
                    const currentOrderNumber = String(this.pendingOrder?.order_number || '');

                    // Update when it refers to the currently displayed order.
                    // Also allow picking up a newly-created order for the same table (when we don't have one yet).
                    const incomingTableId = payload.dining_table_id ?? payload.table_id ?? null;
                    const sameTable = this.tableId && incomingTableId && Number(incomingTableId) === Number(this.tableId);

                    if (currentOrderNumber) {
                        if (incomingOrderNumber !== currentOrderNumber) return;
                    } else {
                        if (!sameTable) return;
                    }

                    const prevStatus = String(this.pendingOrder?.status || this.lastStatus || '');
                    const nextStatus = String(payload.status || prevStatus);

                    // Ignore stale out-of-order updates if updated_at is available.
                    const incomingUpdated = this.isoMs(payload.updated_at);
                    const currentUpdated = this.isoMs(this.pendingOrder?.updated_at);
                    if (incomingUpdated !== null && currentUpdated !== null && incomingUpdated < currentUpdated) return;

                    // Merge into our pendingOrder shape
                    const next = {
                        ...(this.pendingOrder || {}),
                        ...payload,
                    };
                    this.pendingOrder = next;

                    if (nextStatus && nextStatus !== prevStatus) {
                        this.lastStatus = nextStatus;
                        const tone = this.statusTone(nextStatus);
                        const type = nextStatus === 'preparing' ? 'preparing' : (nextStatus === 'completed' ? 'success' : 'preparing');
                        this.showToast(type, tone.titleText, tone.descText);

                        // Start QR countdown when order becomes completed
                        if (nextStatus === 'completed') {
                            this.startQrCountdown();
                        }
                    }
                },

                trySocket(url) {
                    const u = String(url || '').trim();
                    if (!u) return;

                    const script = document.createElement('script');
                    script.src = u.replace(/\/$/, '') + '/socket.io/socket.io.js';
                    script.async = true;
                    script.onload = () => {
                        if (!window.io) return;
                        try {
                            this.socket = window.io(u, { transports: ['websocket', 'polling'] });

                            this.socket.on('connect', () => { this.connected = true; });
                            this.socket.on('disconnect', () => { this.connected = false; });

                            this.socket.on('order.updated', (data) => this.applyOrderUpdate(data));
                            this.socket.on('order.created', (data) => this.applyOrderUpdate(data));
                        } catch (e) {
                            // ignore
                        }
                    };
                    script.onerror = () => {};
                    document.head.appendChild(script);
                },

                isPendingStatus(status) {
                    return ['new', 'accepted', 'preparing', 'completed'].includes(status || '');
                },

                isEmpty() {
                    // Empty when we have a table token but no active order
                    if (!this.tableToken) return false;
                    if (!this.pendingOrder) return true;
                    return !this.isPendingStatus(this.pendingOrder.status);
                },

                statusClass(status) {
                    if (status === 'new') return 'border-slate-200 bg-slate-50 text-slate-700';
                    if (status === 'accepted') return 'border-indigo-200 bg-indigo-50 text-indigo-700';
                    if (status === 'preparing') return 'border-purple-200 bg-purple-50 text-purple-700';
                    if (status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
                    if (status === 'cancelled') return 'border-rose-200 bg-rose-50 text-rose-700';
                    return 'border-slate-200 bg-slate-50 text-slate-700';
                },

                async refreshPendingOrder() {
                    if (!this.tableToken) return;
                    this.loading = true;
                    try {
                        const url = this.pendingUrl + '?table=' + encodeURIComponent(this.tableToken);
                        const res = await fetch(url, {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (!res.ok) return;
                        const data = await res.json();
                        const next = data?.order || null;
                        const prevStatus = this.pendingOrder?.status || this.lastStatus;
                        // Ignore stale poll responses if updated_at is available.
                        const incomingUpdated = this.isoMs(next?.updated_at);
                        const currentUpdated = this.isoMs(this.pendingOrder?.updated_at);
                        if (incomingUpdated !== null && currentUpdated !== null && incomingUpdated < currentUpdated) {
                            return;
                        }

                        this.pendingOrder = next;

                        const nextStatus = next?.status || null;
                        if (nextStatus && nextStatus !== prevStatus) {
                            this.lastStatus = nextStatus;
                            const tone = this.statusTone(nextStatus);
                            const type = nextStatus === 'preparing' ? 'preparing' : (nextStatus === 'completed' ? 'success' : 'preparing');
                            this.showToast(type, tone.titleText, tone.descText);
                        }
                    } finally {
                        this.loading = false;
                    }
                },

                statusIndex(status) {
                    const order = ['new', 'accepted', 'preparing', 'completed'];
                    const idx = order.indexOf(String(status || ''));
                    return idx < 0 ? -1 : idx;
                },

                stepClass(stepKey, currentStatus) {
                    const i = this.statusIndex(stepKey);
                    const c = this.statusIndex(currentStatus);
                    if (c >= i && i >= 0) {
                        return 'border-white/30 bg-white/30 text-slate-900 shadow-sm';
                    }
                    return 'border-white/15 bg-white/10 text-white/80';
                },

                statusPercent(status) {
                    const idx = this.statusIndex(status);
                    if (idx <= 0) return 18;
                    if (idx === 1) return 45;
                    if (idx === 2) return 78;
                    if (idx >= 3) return 100;
                    return 20;
                },

                statusTone(status) {
                    const s = String(status || 'new');
                    if (s === 'new') {
                        return {
                            emoji: '🧾',
                            titleText: 'Pesanan dibuat',
                            descText: 'Pesanan kamu sudah masuk sistem. Tunggu kasir konfirmasi ya.',
                            box: 'border-slate-200 bg-slate-50',
                            icon: 'bg-slate-900 text-white',
                            title: 'text-slate-900',
                            desc: 'text-slate-700',
                            bar: 'bg-slate-900',
                            anim: 'animate-pulse',
                        };
                    }
                    if (s === 'accepted') {
                        return {
                            emoji: '🤝',
                            titleText: 'Pesanan diterima',
                            descText: 'Kasir sudah menerima pesananmu. Sebentar lagi disiapkan.',
                            box: 'border-indigo-200 bg-indigo-50',
                            icon: 'bg-indigo-600 text-white',
                            title: 'text-indigo-900',
                            desc: 'text-indigo-800',
                            bar: 'bg-indigo-600',
                            anim: 'animate-bounce',
                        };
                    }
                    if (s === 'preparing') {
                        return {
                            emoji: '⏳',
                            titleText: 'Sedang disiapkan',
                            descText: 'Yeyay pesananmu sedang disiapkan ya, ditunggu ya.',
                            box: 'border-purple-200 bg-purple-50',
                            icon: 'bg-purple-600 text-white',
                            title: 'text-purple-900',
                            desc: 'text-purple-800',
                            bar: 'bg-purple-600 animate-pulse',
                            anim: 'animate-pulse',
                        };
                    }
                    if (s === 'completed') {
                        return {
                            emoji: '✅',
                            titleText: 'Pesanan selesai',
                            descText: 'Yeay, pesananmu siap/selesai. Kalau di tempat, biasanya akan diantar ke meja.',
                            box: 'border-emerald-200 bg-emerald-50',
                            icon: 'bg-emerald-600 text-white',
                            title: 'text-emerald-900',
                            desc: 'text-emerald-800',
                            bar: 'bg-emerald-600',
                            anim: 'animate-ping',
                        };
                    }
                    return {
                        emoji: 'ℹ️',
                        titleText: 'Status pesanan',
                        descText: 'Cek detail pesanan di bawah ya.',
                        box: 'border-slate-200 bg-slate-50',
                        icon: 'bg-slate-900 text-white',
                        title: 'text-slate-900',
                        desc: 'text-slate-700',
                        bar: 'bg-slate-900',
                        anim: 'animate-pulse',
                    };
                },

                formatRp(n) {
                    const num = Number(n || 0);
                    return new Intl.NumberFormat('id-ID').format(num);
                },

                formatDateTime(iso) {
                    if (!iso) return '';
                    const d = new Date(iso);
                    return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                },

                startQrCountdown() {
                    // Clear any existing timer
                    if (this.countdownTimer) {
                        clearInterval(this.countdownTimer);
                    }

                    // Reset countdown
                    this.countdownSeconds = 60;
                    this.showQrCode = false;

                    this.countdownTimer = setInterval(() => {
                        this.countdownSeconds--;

                        if (this.countdownSeconds <= 0) {
                            this.showQrCode = true;
                            if (this.countdownTimer) {
                                clearInterval(this.countdownTimer);
                                this.countdownTimer = null;
                            }
                        }
                    }, 1000);
                },

                formatCountdown(seconds) {
                    const mins = Math.floor(seconds / 60);
                    const secs = seconds % 60;
                    return `${mins}:${secs.toString().padStart(2, '0')}`;
                },
            }
        }
    </script>
</x-customer-layout>
