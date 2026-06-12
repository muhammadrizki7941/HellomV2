@php
    $tenantId = null;
    if (app()->bound(\App\Services\Tenancy\TenantContext::class)) {
        $tenantContext = app(\App\Services\Tenancy\TenantContext::class);
        $tenantId = $tenantContext->slug;
    }

    $kitchenOrderDetails = $acceptedOrders
        ->concat($preparingOrders)
        ->keyBy('order_number')
        ->map(function ($order) {
            return [
                'order_number' => (string) $order->order_number,
                'customer_name' => (string) ($order->customer_name ?? '-'),
                'service_type' => (string) ($order->service_type ?? 'dine_in'),
                'table_label' => (string) ($order->table?->label ?? '-'),
                'created_at' => $order->created_at?->format('d M, H:i') ?? '-',
                'items' => collect($order->items)->map(function ($item) {
                    return [
                        'qty' => (int) ($item->qty ?? 0),
                        'name' => (string) ($item->product->name ?? $item->product_name ?? 'Item'),
                        'addons' => (string) ($item->options_label ?? ''),
                    ];
                })->values()->all(),
            ];
        })
        ->values()
        ->all();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dapur (Kasir) - {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased bg-gray-50 overflow-hidden">
<div class="h-full flex">
    @include('admin.cashier._sidebar')

    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top Bar -->
        <div class="bg-white border-b border-gray-200 px-6 py-4">
            <div class="flex items-center gap-4">
                <button type="button" class="lg:hidden px-2 py-1 rounded-md border bg-white text-gray-700" onclick="cashierToggleSidebar()" title="Tampilkan/Sembunyikan menu">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="flex-1">
                    <h2 class="font-semibold text-lg text-gray-900">Pesanan Dapur</h2>
                    <div class="text-xs text-gray-500">Realtime URL: {{ $realtimePublicUrl ?: '-' }}</div>
                </div>
                <div class="text-sm text-gray-600">{{ now()->toDateString() }}</div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Accepted Orders -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-amber-50">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-amber-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900">Menunggu Diproses</h3>
                                <p class="text-sm text-gray-600">{{ $acceptedOrders->count() }} pesanan</p>
                            </div>
                        </div>
                    </div>

                    <div class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
                        @forelse($acceptedOrders as $order)
                        <div class="p-4 hover:bg-gray-50 transition">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="font-bold text-gray-900">#{{ $order->order_number }}</span>
                                        <span class="px-2 py-1 bg-amber-100 text-amber-800 text-xs font-semibold rounded-full">{{ ucfirst($order->service_type) }}</span>
                                        @if($order->table)
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">Table {{ $order->table->label }}</span>
                                        @endif
                                    </div>

                                    <div class="text-sm text-gray-600 mb-2">
                                        <div class="font-medium">{{ $order->customer_name }}</div>
                                        <div class="text-xs">{{ $order->created_at->format('M j, H:i') }}</div>
                                    </div>

                                    <div class="space-y-1">
                                        @foreach($order->items as $item)
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-900">{{ $item->qty }}x {{ $item->product->name }} @if($item->options_label)<span class="text-gray-500">({{ $item->options_label }})</span>@endif</span>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <button onclick="startPreparation('{{ $order->order_number }}')" class="px-4 py-2 bg-emerald-500 text-white text-sm font-semibold rounded-lg hover:bg-emerald-600 transition">Mulai Proses</button>
                                    <button type="button" onclick="openKitchenDetail('{{ $order->order_number }}')" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-200 transition text-center">Lihat Isi Pesanan</button>
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="p-8 text-center text-gray-500">
                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            <p class="text-sm">Belum ada pesanan yang menunggu diproses</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                <!-- Preparing Orders -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-blue-50">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900">Sedang Diproses</h3>
                                <p class="text-sm text-gray-600">{{ $preparingOrders->count() }} pesanan</p>
                            </div>
                        </div>
                    </div>

                    <div class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
                        @forelse($preparingOrders as $order)
                        <div class="p-4 hover:bg-gray-50 transition">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="font-bold text-gray-900">#{{ $order->order_number }}</span>
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">{{ ucfirst($order->service_type) }}</span>
                                        @if($order->table)
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">Table {{ $order->table->label }}</span>
                                        @endif
                                    </div>

                                    <div class="text-sm text-gray-600 mb-2">
                                        <div class="font-medium">{{ $order->customer_name }}</div>
                                        <div class="text-xs">{{ $order->created_at->format('M j, H:i') }}</div>
                                    </div>

                                    <div class="space-y-1">
                                        @foreach($order->items as $item)
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-900">{{ $item->qty }}x {{ $item->product->name }} @if($item->options_label)<span class="text-gray-500">({{ $item->options_label }})</span>@endif</span>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <button onclick="completeOrder('{{ $order->order_number }}')" class="px-4 py-2 bg-green-500 text-white text-sm font-semibold rounded-lg hover:bg-green-600 transition">Siap Disajikan</button>
                                    <button type="button" onclick="openKitchenDetail('{{ $order->order_number }}')" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-200 transition text-center">Lihat Isi Pesanan</button>
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="p-8 text-center text-gray-500">
                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                            <p class="text-sm">Belum ada pesanan yang sedang diproses</p>
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="kitchen-detail-modal" class="hidden fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/40" onclick="closeKitchenDetail()"></div>
        <div class="relative min-h-full flex items-center justify-center p-4">
            <div class="w-full max-w-xl bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Detail Pesanan Dapur</h3>
                        <div id="kitchen-detail-meta" class="text-xs text-gray-500 mt-1">-</div>
                    </div>
                    <button type="button" class="w-9 h-9 inline-flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50" onclick="closeKitchenDetail()" title="Tutup">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-5 py-4 max-h-[70vh] overflow-y-auto">
                    <div id="kitchen-detail-items" class="space-y-3"></div>
                </div>

                <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between gap-3">
                    <div class="text-xs text-gray-600">Fokus dapur: jumlah, nama menu, dan add-on (tanpa harga).</div>
                    <button type="button" id="kitchen-print-btn" class="px-3 py-2 rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-700 text-xs font-semibold hover:bg-emerald-100" onclick="reprintKitchenDetail()">Cetak Ulang</button>
                </div>
            </div>
        </div>
    </div>

    <script>
const kitchenOrderDetails = @json($kitchenOrderDetails);
const kitchenDetailMap = Object.fromEntries((kitchenOrderDetails || []).map(o => [String(o.order_number), o]));
let kitchenCurrentOrderNumber = null;

function kitchenEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function kitchenTicketHtml(data) {
    const rows = Array.isArray(data?.items) ? data.items : [];
    const serviceLabel = String(data?.service_type || '') === 'takeout' ? 'Bungkus' : 'Makan di Tempat';
    const itemsHtml = rows.map((row) => {
        const addons = String(row?.addons || '').trim();
        return `
            <div class="item">
                <div class="line"><span class="qty">x${Number(row?.qty || 0)}</span><span class="name">${kitchenEscapeHtml(row?.name || 'Item')}</span></div>
                ${addons ? `<div class="addon">+ ${kitchenEscapeHtml(addons)}</div>` : ''}
            </div>
        `;
    }).join('');

    return `
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tiket Dapur #${kitchenEscapeHtml(data?.order_number || '')}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 10px; font-size: 12px; }
        .ticket { width: 280px; }
        .title { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
        .meta { margin: 2px 0; }
        .sep { border-top: 1px dashed #333; margin: 8px 0; }
        .item { margin-bottom: 8px; }
        .line { display: flex; gap: 6px; }
        .qty { min-width: 32px; font-weight: 700; }
        .name { font-weight: 600; }
        .addon { margin-left: 38px; color: #444; font-size: 11px; }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="title">TIKET DAPUR</div>
        <div class="meta"><strong>No:</strong> #${kitchenEscapeHtml(data?.order_number || '-')}</div>
        <div class="meta"><strong>Waktu:</strong> ${kitchenEscapeHtml(data?.created_at || '-')}</div>
        <div class="meta"><strong>Layanan:</strong> ${kitchenEscapeHtml(serviceLabel)}</div>
        <div class="meta"><strong>Meja:</strong> ${kitchenEscapeHtml(data?.table_label || '-')}</div>
        <div class="meta"><strong>Pelanggan:</strong> ${kitchenEscapeHtml(data?.customer_name || '-')}</div>
        <div class="sep"></div>
        ${itemsHtml || '<div>Tidak ada item.</div>'}
    </div>
</body>
</html>
    `;
}

function printKitchenTicket(orderNumber) {
    const data = kitchenDetailMap[String(orderNumber)];
    if (!data) return;

    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    document.body.appendChild(frame);

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(kitchenTicketHtml(data));
    doc.close();

    setTimeout(() => {
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (e) {}
        setTimeout(() => {
            try { frame.remove(); } catch (e) {}
        }, 1000);
    }, 220);
}

function reprintKitchenDetail() {
    if (!kitchenCurrentOrderNumber) return;
    printKitchenTicket(kitchenCurrentOrderNumber);
}

function openKitchenDetail(orderNumber) {
    const data = kitchenDetailMap[String(orderNumber)];
    if (!data) {
        alert('Detail pesanan tidak ditemukan.');
        return;
    }

    const meta = document.getElementById('kitchen-detail-meta');
    const itemsWrap = document.getElementById('kitchen-detail-items');
    const modal = document.getElementById('kitchen-detail-modal');

    if (!meta || !itemsWrap || !modal) return;
    kitchenCurrentOrderNumber = String(orderNumber);

    const serviceLabel = String(data.service_type || '') === 'takeout' ? 'Bungkus' : 'Makan di Tempat';
    meta.textContent = `#${data.order_number} • ${serviceLabel} • Meja: ${data.table_label || '-'} • ${data.customer_name || '-'} • ${data.created_at || '-'}`;

    const rows = Array.isArray(data.items) ? data.items : [];
    if (!rows.length) {
        itemsWrap.innerHTML = '<div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">Belum ada item pada pesanan ini.</div>';
    } else {
        itemsWrap.innerHTML = rows.map((row) => {
            const addons = String(row.addons || '').trim();
            return `
                <div class="rounded-xl border border-gray-200 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-900 truncate">${row.name || 'Item'}</div>
                            ${addons ? `<div class="text-xs text-gray-500 mt-1">Add-on: ${addons}</div>` : ''}
                        </div>
                        <div class="text-sm font-bold text-gray-900">x${Number(row.qty || 0)}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    printKitchenTicket(orderNumber);
}

function closeKitchenDetail() {
    const modal = document.getElementById('kitchen-detail-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeKitchenDetail();
    }
});

function startPreparation(orderNumber) {
    if (!confirm('Mulai proses pesanan ini sekarang?')) return;

    const url = @js(route('admin.kitchen.status', ['orderNumber' => '__ORDER__'])).replace('__ORDER__', encodeURIComponent(orderNumber));

    fetch(url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({ status: 'preparing' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Gagal memperbarui status pesanan');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui status pesanan');
    });
}

function completeOrder(orderNumber) {
    if (!confirm('Tandai pesanan ini siap disajikan?')) return;

    const url = @js(route('admin.kitchen.status', ['orderNumber' => '__ORDER__'])).replace('__ORDER__', encodeURIComponent(orderNumber));

    fetch(url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({ status: 'completed' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Gagal memperbarui status pesanan');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui status pesanan');
    });
}

// Auto refresh every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);

// Real-time updates
let socket = null;
let realtimeUrl = '{{ config("realtime.public_url") }}';

function initRealtime() {
    if (!realtimeUrl) return;

    // Load Socket.IO if not already loaded
    if (!window.io) {
        const script = document.createElement('script');
        script.src = realtimeUrl.replace(/\/$/, '') + '/socket.io/socket.io.js';
        script.onload = connectSocket;
        script.onerror = () => {
            const el = document.getElementById('connectionStatus'); if(el) el.className = 'w-2 h-2 bg-red-500 rounded-full';
            const txt = document.getElementById('connectionText'); if(txt) txt.textContent = 'Koneksi Gagal';
        };
        document.head.appendChild(script);
    } else {
        connectSocket();
    }
}

function connectSocket() {
    try {
        socket = window.io(realtimeUrl, {
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionAttempts: 10,
            reconnectionDelay: 500,
            reconnectionDelayMax: 5000,
            timeout: 8000,
        });

        socket.on('connect', () => {
            const el = document.getElementById('connectionStatus'); if(el) el.className = 'w-2 h-2 bg-green-500 rounded-full animate-pulse';
            const txt = document.getElementById('connectionText'); if(txt) txt.textContent = 'Realtime Aktif';
        });

        socket.on('disconnect', () => {
            const el = document.getElementById('connectionStatus'); if(el) el.className = 'w-2 h-2 bg-yellow-500 rounded-full';
            const txt = document.getElementById('connectionText'); if(txt) txt.textContent = 'Menghubungkan Ulang...';
        });

        socket.on('connect_error', () => {
            const el = document.getElementById('connectionStatus'); if(el) el.className = 'w-2 h-2 bg-red-500 rounded-full';
            const txt = document.getElementById('connectionText'); if(txt) txt.textContent = 'Error Koneksi';
        });

        // Listen for order updates
        socket.on('order.created', (payload) => {
            if (payload && payload.order && payload.order.source !== 'pos') {
                // New order that needs kitchen attention
                showNotification('Pesanan Baru Masuk!', `Order #${payload.order.order_number} menunggu diproses dapur`);
                setTimeout(() => location.reload(), 2000);
            }
        });

        socket.on('order.updated', (payload) => {
            if (payload && payload.order) {
                // Order status changed
                setTimeout(() => location.reload(), 1000);
            }
        });

    } catch (e) {
        const el = document.getElementById('connectionStatus'); if(el) el.className = 'w-2 h-2 bg-red-500 rounded-full';
        const txt = document.getElementById('connectionText'); if(txt) txt.textContent = 'Koneksi Gagal';
    }
}

function showNotification(title, message) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = 'fixed top-4 right-4 z-50 bg-blue-500 text-white px-6 py-4 rounded-lg shadow-lg transform translate-x-full transition-transform duration-300';
    notification.innerHTML = `
        <div class="flex items-start gap-3">
            <div class="flex-1">
                <div class="font-semibold">${title}</div>
                <div class="text-sm opacity-90">${message}</div>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-white hover:text-gray-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);

    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Initialize real-time connection
document.addEventListener('DOMContentLoaded', initRealtime);
    </script>
</body>
</html>