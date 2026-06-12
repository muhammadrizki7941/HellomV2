@php
    /** @var \App\Models\Order $order */
@endphp

<x-customer-layout>
    <x-slot name="headerRight">
        <div class="font-medium text-slate-700">Order</div>
        <div class="text-slate-900 font-semibold">{{ $order->order_number }}</div>
    </x-slot>

    <style>
        @keyframes steamUp {
            0% { transform: translateY(0); opacity: .2; }
            40% { opacity: .9; }
            100% { transform: translateY(-12px); opacity: 0; }
        }
        @keyframes wheelSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes deliverMove {
            0% { transform: translateX(-2px); }
            50% { transform: translateX(2px); }
            100% { transform: translateX(-2px); }
        }
    </style>

    <div x-data="customerThanks(@js($order->order_number), @js(rtrim(url('/'), '/')), @js($realtimePublicUrl ?? ''), @js($order->status), @js($order->updated_at?->toISOString()), @js($order->tenant_id))" x-init="init()">
        <!-- Toast (customer - always on top) -->
        <div class="fixed top-4 inset-x-0 z-[9999] flex items-center justify-center px-4" x-show="toastOpen" x-transition>
            <div class="w-full max-w-md rounded-3xl border shadow-xl bg-white p-4"
                :class="toastType==='error' ? 'border-rose-200' : (toastType==='info' ? 'border-slate-200' : (toastType==='preparing' ? 'border-purple-200' : 'border-emerald-200'))">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold"
                            :class="toastType==='error' ? 'text-rose-800' : (toastType==='preparing' ? 'text-purple-800' : (toastType==='completed' ? 'text-emerald-800' : 'text-slate-800'))"
                            x-text="toastTitle"></div>
                        <div class="mt-1 text-sm text-slate-700" x-text="toastMessage"></div>
                    </div>
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-900" @click="toastOpen=false">Tutup</button>
                </div>
            </div>
        </div>

        <div class="rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-2xl font-semibold">Pesanan terkirim</div>
                    <div class="mt-2 text-slate-600">Status pesanan akan update otomatis (realtime/polling).</div>
                </div>
                <div class="text-xs text-slate-500 text-right">
                    <div class="inline-flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full" :class="connected ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                        <span x-text="connected ? 'Realtime aktif' : 'Mode polling'"></span>
                    </div>
                    <div class="mt-1" x-show="lastUpdatedAt">Update: <span class="font-medium" x-text="formatDateTime(lastUpdatedAt)"></span></div>
                </div>
            </div>

        @if((int)($order->redeemed_points ?? 0) > 0 && (int)($order->discount_amount ?? 0) > 0)
            <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                <div class="font-semibold text-emerald-900">Selamat! Kamu berhasil pakai poin 🎉</div>
                <div class="mt-1 text-sm text-emerald-800">
                    Kamu pakai <span class="font-semibold">{{ number_format((int)$order->redeemed_points, 0, ',', '.') }}</span> poin dan hemat
                    <span class="font-semibold">Rp {{ number_format((int)$order->discount_amount, 0, ',', '.') }}</span>. Yuk kumpulin poin terus!
                </div>
            </div>
        @endif

            <div class="mt-5 grid gap-3">
                <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-xs text-slate-500">Nomor Order</div>
                            <div class="text-lg font-semibold">{{ $order->order_number }}</div>
                        </div>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold border" :class="statusClass(status)" x-text="statusLabel(status)"></span>
                    </div>

                    <!-- Friendly status message + lightweight animation -->
                    <div class="mt-4 rounded-2xl border p-4" :class="statusPanelClass(status)">
                        <div class="flex items-start gap-3">
                            <div class="h-12 w-12 rounded-2xl grid place-items-center" :class="statusIconWrapClass(status)">
                                <template x-if="status === 'preparing'">
                                    <div class="relative">
                                        <!-- Simple steaming burger (SVG + CSS) -->
                                        <svg width="44" height="44" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <rect x="10" y="24" width="24" height="7" rx="3" fill="currentColor" opacity="0.95"/>
                                            <rect x="10" y="20" width="24" height="4" rx="2" fill="currentColor" opacity="0.55"/>
                                            <path d="M12 20c1-6 19-6 20 0" stroke="currentColor" stroke-width="4" stroke-linecap="round" opacity="0.85"/>
                                        </svg>
                                        <span class="absolute left-2 top-0 h-2 w-2 rounded-full bg-white/90" style="animation: steamUp 900ms infinite;"></span>
                                        <span class="absolute left-5 top-1 h-2 w-2 rounded-full bg-white/80" style="animation: steamUp 1100ms 120ms infinite;"></span>
                                        <span class="absolute left-8 top-0.5 h-2 w-2 rounded-full bg-white/80" style="animation: steamUp 1000ms 220ms infinite;"></span>
                                    </div>
                                </template>

                                <template x-if="status === 'completed'">
                                    <div class="relative" style="animation: deliverMove 900ms ease-in-out infinite;">
                                        <!-- Simple delivery cart (SVG + CSS wheel spin) -->
                                        <svg width="44" height="44" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M10 14h6l2 14h14l3-10H19" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity="0.9"/>
                                            <circle cx="19" cy="34" r="3" stroke="currentColor" stroke-width="3" opacity="0.9"/>
                                            <circle cx="31" cy="34" r="3" stroke="currentColor" stroke-width="3" opacity="0.9"/>
                                        </svg>
                                    </div>
                                </template>

                                <template x-if="status !== 'preparing' && status !== 'completed'">
                                    <span class="text-xl" x-text="statusEmoji(status)"></span>
                                </template>
                            </div>

                            <div class="min-w-0">
                                <div class="font-semibold" :class="statusTitleClass(status)" x-text="statusTitle(status)"></div>
                                <div class="mt-1 text-sm" :class="statusSubtitleClass(status)" x-text="statusSubtitle(status)"></div>
                            </div>
                        </div>

                        <!-- Timeline -->
                        <div class="mt-4 grid grid-cols-4 gap-2 text-[11px]">
                            <template x-for="step in timelineSteps" :key="step.key">
                                <div class="rounded-2xl border px-3 py-2" :class="timelineClass(step.key)">
                                    <div class="font-semibold" x-text="step.label"></div>
                                    <div class="text-[10px] opacity-80" x-text="timelineHint(step.key)"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4">
                <div class="text-xs text-slate-500">Total</div>
                @php
                    $subtotal = (int) ($order->items?->sum('line_total') ?? 0);
                    $discount = (int) ($order->discount_amount ?? 0);
                    $total = (int) ($order->total_amount ?? 0);
                @endphp

                @if($discount > 0)
                    <div class="grid gap-1">
                        <div class="flex items-center justify-between text-sm">
                            <div class="text-slate-600">Subtotal</div>
                            <div class="font-semibold">Rp {{ number_format($subtotal, 0, ',', '.') }}</div>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <div class="text-slate-600">Diskon (poin)</div>
                            <div class="font-semibold text-emerald-700">- Rp {{ number_format($discount, 0, ',', '.') }}</div>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <div class="text-slate-900 font-semibold">Total bayar</div>
                            <div class="text-xl font-semibold">Rp {{ number_format($total, 0, ',', '.') }}</div>
                        </div>
                    </div>
                @else
                    <div class="text-xl font-semibold">Rp {{ number_format($total, 0, ',', '.') }}</div>
                @endif
            </div>

            @php
                /** @var \App\Models\PaymentSetting|null $paymentSetting */
                $method = (string) ($order->payment_method ?? '');
                if ($method === 'qris') { $method = 'qris_static'; }
            @endphp

            @if($method === 'qris_static')
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="font-semibold text-emerald-900">Pembayaran QRIS</div>
                    <div class="mt-1 text-sm text-emerald-800">Scan QR untuk bayar. Setelah bayar, tunggu konfirmasi dari kasir.</div>

                    @if($paymentSetting?->qrisStaticImageUrl())
                        @php($qrisImg = $paymentSetting->qrisStaticImageUrl())
                        <img src="{{ $qrisImg }}" alt="QRIS" class="mt-3 w-64 rounded-2xl border bg-white" />
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ $qrisImg }}" target="_blank" rel="noopener" download
                                class="inline-flex items-center rounded-2xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold shadow-sm">Download QR</a>
                            <a href="{{ $qrisImg }}" target="_blank" rel="noopener"
                                class="inline-flex items-center rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800">Buka gambar</a>
                        </div>
                    @elseif($paymentSetting?->qris_static_payload)
                        <div class="mt-3 text-xs text-emerald-900 break-all">{{ $paymentSetting->qris_static_payload }}</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button"
                                class="inline-flex items-center rounded-2xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold shadow-sm"
                                @click="try{navigator.clipboard.writeText(@js((string) $paymentSetting->qris_static_payload));}catch(e){}">
                                Copy QR string
                            </button>
                        </div>
                    @else
                        <div class="mt-3 text-sm text-emerald-900">QRIS belum diset. Hubungi kasir.</div>
                    @endif
                </div>
            @elseif($method === 'qris_dynamic')
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="font-semibold text-emerald-900">Pembayaran QRIS (Dinamis)</div>
                    <div class="mt-1 text-sm text-emerald-800">Scan QR untuk bayar. Setelah bayar, tunggu konfirmasi dari kasir.</div>

                    @if(!empty($order->payment_qr_url))
                        <img src="{{ $order->payment_qr_url }}" alt="QRIS" class="mt-3 w-64 rounded-2xl border bg-white" />
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a href="{{ $order->payment_qr_url }}" target="_blank" rel="noopener" download
                                class="inline-flex items-center rounded-2xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold shadow-sm">Download QR</a>
                            <a href="{{ $order->payment_qr_url }}" target="_blank" rel="noopener"
                                class="inline-flex items-center rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800">Buka gambar</a>
                        </div>
                    @elseif(!empty($order->payment_qr_string))
                        <div class="mt-3 text-xs text-emerald-900 break-all">{{ $order->payment_qr_string }}</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button"
                                class="inline-flex items-center rounded-2xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold shadow-sm"
                                @click="try{navigator.clipboard.writeText(@js((string) $order->payment_qr_string));}catch(e){}">
                                Copy QR string
                            </button>
                        </div>
                    @else
                        <div class="mt-3 text-sm text-emerald-900">QRIS dinamis belum tersedia. Hubungi kasir.</div>
                    @endif
                </div>
            @endif
        </div>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <a href="{{ route('order.page', ['table' => optional($order->table)->public_id]) }}" class="inline-flex items-center rounded-2xl bg-slate-900 text-white px-5 py-3 font-semibold shadow-sm">Order Lagi</a>
                <button type="button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold" @click="refreshNow()">Refresh status</button>
            </div>
        </div>

    <div class="mt-6 rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
        <div class="text-lg font-semibold">Rincian</div>
        <div class="mt-4 space-y-2">
            @foreach($order->items as $item)
                <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3">
                    <div>
                        <div class="font-medium">{{ $item->product_name }}</div>
                        <div class="text-xs text-slate-500">Rp {{ number_format($item->unit_price, 0, ',', '.') }} × {{ $item->qty }}</div>

                        @if($item->relationLoaded('options') && $item->options->isNotEmpty())
                            <div class="mt-1 text-xs text-slate-600 space-y-0.5">
                                @foreach($item->options->groupBy('option_name') as $optName => $rows)
                                    <div>
                                        <span class="text-slate-500">{{ $optName }}:</span>
                                        <span class="font-medium">{{ $rows->pluck('value_name')->join(', ') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="font-semibold">Rp {{ number_format($item->line_total, 0, ',', '.') }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        function customerThanks(orderNumber, baseUrl, realtimePublicUrl, initialStatus, initialUpdatedAt, tenantId) {
            return {
                orderNumber: String(orderNumber || ''),
                baseUrl: String(baseUrl || ''),
                status: String(initialStatus || 'new'),
                lastUpdatedAt: initialUpdatedAt || null,
                tenantId: tenantId || null,

                socket: null,
                connected: false,
                pollTimer: null,

                toastOpen: false,
                toastType: 'info',
                toastTitle: '',
                toastMessage: '',
                toastTimer: null,

                timelineSteps: [
                    { key: 'new', label: 'Masuk' },
                    { key: 'accepted', label: 'Diterima' },
                    { key: 'preparing', label: 'Dimasak' },
                    { key: 'completed', label: 'Selesai' },
                ],

                init() {
                    // Initial friendly toast
                    this.showToast('info', 'Order berhasil dikirim', 'Tunggu sebentar ya, status akan update otomatis.');

                    this.trySocket(realtimePublicUrl);
                    this.startPolling();
                },

                formatDateTime(iso) {
                    try {
                        const d = new Date(iso);
                        if (Number.isNaN(d.getTime())) return String(iso);
                        return new Intl.DateTimeFormat('id-ID', {
                            year: 'numeric', month: '2-digit', day: '2-digit',
                            hour: '2-digit', minute: '2-digit',
                        }).format(d);
                    } catch (e) {
                        return String(iso);
                    }
                },

                statusLabel(s) {
                    switch (s) {
                        case 'new': return 'Masuk';
                        case 'accepted': return 'Diterima';
                        case 'preparing': return 'Diproses';
                        case 'completed': return 'Selesai';
                        case 'cancelled': return 'Batal';
                        default: return String(s || '-');
                    }
                },

                statusEmoji(s) {
                    switch (s) {
                        case 'new': return '🧾';
                        case 'accepted': return '👍';
                        case 'preparing': return '🍳';
                        case 'completed': return '✅';
                        case 'cancelled': return '⛔';
                        default: return 'ℹ️';
                    }
                },

                statusTitle(s) {
                    switch (s) {
                        case 'new': return 'Pesanan kamu sudah masuk';
                        case 'accepted': return 'Pesanan diterima kasir';
                        case 'preparing': return 'Pesanan sedang dimasak';
                        case 'completed': return 'Pesanan sudah selesai';
                        case 'cancelled': return 'Pesanan dibatalkan';
                        default: return 'Status pesanan';
                    }
                },

                statusSubtitle(s) {
                    switch (s) {
                        case 'new': return 'Kasir akan memproses pesanan kamu sebentar lagi.';
                        case 'accepted': return 'Tim kami mulai menyiapkan pesanan.';
                        case 'preparing': return 'Sedang dimasak, mohon ditunggu ya.';
                        case 'completed': return 'Pesanan siap diantar ke meja kamu.';
                        case 'cancelled': return 'Jika ini tidak sesuai, silakan hubungi kasir.';
                        default: return 'Menunggu update...';
                    }
                },

                statusClass(s) {
                    switch (s) {
                        case 'new': return 'bg-blue-50 text-blue-700 border-blue-200';
                        case 'accepted': return 'bg-amber-50 text-amber-700 border-amber-200';
                        case 'preparing': return 'bg-purple-50 text-purple-700 border-purple-200';
                        case 'completed': return 'bg-emerald-50 text-emerald-700 border-emerald-200';
                        case 'cancelled': return 'bg-gray-100 text-gray-700 border-gray-200';
                        default: return 'bg-gray-100 text-gray-700 border-gray-200';
                    }
                },

                statusPanelClass(s) {
                    switch (s) {
                        case 'preparing': return 'border-purple-200 bg-purple-50';
                        case 'completed': return 'border-emerald-200 bg-emerald-50';
                        case 'cancelled': return 'border-rose-200 bg-rose-50';
                        default: return 'border-slate-200 bg-white';
                    }
                },

                statusIconWrapClass(s) {
                    switch (s) {
                        case 'preparing': return 'bg-purple-600 text-white';
                        case 'completed': return 'bg-emerald-600 text-white';
                        case 'cancelled': return 'bg-rose-600 text-white';
                        default: return 'bg-slate-900 text-white';
                    }
                },

                statusTitleClass(s) {
                    switch (s) {
                        case 'preparing': return 'text-purple-900';
                        case 'completed': return 'text-emerald-900';
                        case 'cancelled': return 'text-rose-900';
                        default: return 'text-slate-900';
                    }
                },

                statusSubtitleClass(s) {
                    switch (s) {
                        case 'preparing': return 'text-purple-800';
                        case 'completed': return 'text-emerald-800';
                        case 'cancelled': return 'text-rose-800';
                        default: return 'text-slate-700';
                    }
                },

                statusRank(s) {
                    const order = ['new', 'accepted', 'preparing', 'completed'];
                    const idx = order.indexOf(String(s || ''));
                    return idx >= 0 ? idx : -1;
                },

                timelineClass(stepKey) {
                    if (this.status === 'cancelled') {
                        return stepKey === 'new' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-200 bg-white text-slate-500';
                    }
                    const rNow = this.statusRank(this.status);
                    const rStep = this.statusRank(stepKey);
                    if (rStep <= rNow && rStep >= 0) {
                        if (stepKey === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
                        if (stepKey === 'preparing') return 'border-purple-200 bg-purple-50 text-purple-800';
                        if (stepKey === 'accepted') return 'border-amber-200 bg-amber-50 text-amber-800';
                        return 'border-blue-200 bg-blue-50 text-blue-800';
                    }
                    return 'border-slate-200 bg-white text-slate-500';
                },

                timelineHint(stepKey) {
                    const rNow = this.statusRank(this.status);
                    const rStep = this.statusRank(stepKey);
                    if (this.status === 'cancelled') return stepKey === 'new' ? 'dibatalkan' : '—';
                    if (rStep < 0) return '';
                    if (rStep < rNow) return 'selesai';
                    if (rStep === rNow) return 'sekarang';
                    return 'menunggu';
                },

                showToast(type, title, message, ttl = 3500) {
                    this.toastType = type;
                    this.toastTitle = title;
                    this.toastMessage = message;
                    this.toastOpen = true;
                    if (this.toastTimer) clearTimeout(this.toastTimer);
                    this.toastTimer = setTimeout(() => { this.toastOpen = false; }, ttl);
                },

                applyOrderUpdate(payload) {
                    if (!payload || String(payload.order_number || '') !== this.orderNumber) return;

                    const prev = this.status;
                    const next = String(payload.status || prev);

                    this.status = next;
                    if (payload.updated_at) this.lastUpdatedAt = payload.updated_at;

                    if (prev !== next) {
                        if (next === 'preparing') {
                            this.showToast('preparing', 'Pesanan sedang dimasak', 'Sedang diproses ya, sebentar lagi.');
                        } else if (next === 'completed') {
                            this.showToast('completed', 'Pesanan selesai', 'Pesanan siap diantar ke meja kamu.');
                        } else if (next === 'accepted') {
                            this.showToast('info', 'Pesanan diterima', 'Tim kami mulai menyiapkan pesanan.');
                        } else if (next === 'cancelled') {
                            this.showToast('error', 'Pesanan dibatalkan', 'Silakan hubungi kasir bila perlu.', 5000);
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

                            this.socket.on('connect', () => {
                                this.connected = true;
                                // Join tenant-specific room if tenantId is available
                                if (this.tenantId) {
                                    this.socket.emit('join', `tenant_${this.tenantId}`);
                                }
                            });

                            this.socket.on('disconnect', () => {
                                this.connected = false;
                            });

                            this.socket.on('order.updated', (data) => this.applyOrderUpdate(data));
                            this.socket.on('order.created', (data) => this.applyOrderUpdate(data));
                        } catch (e) {
                            // ignore
                        }
                    };
                    script.onerror = () => {};
                    document.head.appendChild(script);
                },

                startPolling() {
                    if (this.pollTimer) return;
                    this.pollTimer = setInterval(() => this.refreshNow(), 5000);
                    this.refreshNow();
                },

                async refreshNow() {
                    if (!this.orderNumber) return;
                    try {
                        const url = `${this.baseUrl}/order/status/${encodeURIComponent(this.orderNumber)}`;
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const json = await res.json();
                        const o = json.order || null;
                        if (!o) return;
                        this.applyOrderUpdate(o);
                    } catch (e) {
                        // ignore
                    }
                },
            }
        }
    </script>
    </div>
</x-customer-layout>
