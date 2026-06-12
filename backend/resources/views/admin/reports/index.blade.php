@php
    /** @var string $period */
    /** @var \Illuminate\Support\Carbon $start */
    /** @var \Illuminate\Support\Carbon $end */
    /** @var array $summary */
    /** @var array $chart */
    /** @var array $productsRange */
    /** @var array $segmentsRange */
    /** @var array $topToday */
    /** @var array $topMonth */
    /** @var array $topYear */

    $periodLabel = match($period) {
        'today' => 'Hari ini',
        'date' => 'Tanggal',
        'month' => 'Bulan',
        'year' => 'Tahun',
        'range' => 'Range',
        default => 'Hari ini',
    };

    $fmtRp = fn (int $v) => number_format($v, 0, ',', '.');
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Laporan</h2>
            <div class="mt-1 text-xs text-gray-500">Menghitung <span class="font-semibold">Orders</span> dan <span class="font-semibold">Reservasi</span> dengan status <span class="font-semibold">completed</span> (berdasarkan waktu update status).</div>
        </div>
        <div class="text-right">
            <div class="text-xs text-gray-500">Periode</div>
            <div class="text-sm font-semibold">{{ $periodLabel }} · {{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</div>
        </div>
    </div>
@endsection

@section('content')
    <!-- Konten utama -->
                    <form method="GET" action="{{ route('admin.reports.index') }}" class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-end">
                        <div class="lg:col-span-3">
                            <label class="text-sm font-semibold">Periode</label>
                            <select name="period" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3"
                                x-model="period"
                                @change="onPeriodChange($event)">
                                <option value="today" {{ $period==='today' ? 'selected' : '' }}>Hari ini</option>
                                <option value="date" {{ $period==='date' ? 'selected' : '' }}>Pilih tanggal</option>
                                <option value="month" {{ $period==='month' ? 'selected' : '' }}>Pilih bulan</option>
                                <option value="year" {{ $period==='year' ? 'selected' : '' }}>Pilih tahun</option>
                                <option value="range" {{ $period==='range' ? 'selected' : '' }}>Range tanggal</option>
                            </select>
                        </div>

                        <div class="lg:col-span-3" x-show="period==='date'" x-cloak>
                            <label class="text-sm font-semibold">Tanggal</label>
                            <input type="date" name="date" value="{{ $start->format('Y-m-d') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                        </div>

                        <div class="lg:col-span-3" x-show="period==='month'" x-cloak>
                            <label class="text-sm font-semibold">Bulan</label>
                            <input type="month" name="month" value="{{ $start->format('Y-m') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                        </div>

                        <div class="lg:col-span-3" x-show="period==='year'" x-cloak>
                            <label class="text-sm font-semibold">Tahun</label>
                            <input type="number" name="year" min="2000" max="2100" value="{{ $start->format('Y') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                        </div>

                        <div class="lg:col-span-3" x-show="period==='range'" x-cloak>
                            <label class="text-sm font-semibold">Dari</label>
                            <input type="date" name="start" value="{{ $start->format('Y-m-d') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                        </div>
                        <div class="lg:col-span-3" x-show="period==='range'" x-cloak>
                            <label class="text-sm font-semibold">Sampai</label>
                            <input type="date" name="end" value="{{ $end->format('Y-m-d') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                        </div>

                        <div class="lg:col-span-3 flex items-center gap-2">
                            <button type="submit" class="px-4 py-3 rounded-xl bg-slate-900 text-white text-sm font-semibold">Terapkan</button>

                            <a class="px-4 py-3 rounded-xl border bg-white text-sm font-semibold"
                               :href="exportSalesUrl()">Download Excel (Penjualan)</a>
                            <a class="px-4 py-3 rounded-xl border bg-white text-sm font-semibold"
                               :href="exportProductsUrl()">Download Excel (Produk)</a>
                        </div>
                    </form>

                    <!-- Summary Cards -->
                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Total Order</div>
                            <div class="mt-1 text-xl font-semibold">{{ number_format((int)($summary['orders_count'] ?? 0), 0, ',', '.') }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Total Reservasi</div>
                            <div class="mt-1 text-xl font-semibold">{{ number_format((int)($summary['reservations_count'] ?? 0), 0, ',', '.') }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Gross Penjualan</div>
                            <div class="mt-1 text-xl font-semibold">Rp {{ $fmtRp((int)($summary['revenue_gross'] ?? 0)) }}</div>
                            <div class="mt-1 text-xs text-gray-500">Gross = net + diskon (poin)</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Diskon (Poin)</div>
                            <div class="mt-1 text-xl font-semibold text-emerald-700">Rp {{ $fmtRp((int)($summary['discount_total'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Net Order</div>
                            <div class="mt-1 text-xl font-semibold">Rp {{ $fmtRp((int)($summary['revenue_net'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Omset Reservasi</div>
                            <div class="mt-1 text-xl font-semibold">Rp {{ $fmtRp((int)($summary['reservations_revenue'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Total Omset (Order + Reservasi)</div>
                            <div class="mt-1 text-xl font-semibold">Rp {{ $fmtRp((int)($summary['total_net'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="text-xs text-gray-500">Rata-rata / Transaksi</div>
                            <div class="mt-1 text-xl font-semibold">Rp {{ $fmtRp((int)($summary['avg_transaction'] ?? 0)) }}</div>
                        </div>
                    </div>

                    <!-- Charts -->
                    <div class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold">Tren Penghasilan (Net)</div>
                                <div class="text-xs text-gray-500">Per hari</div>
                            </div>
                            <div class="mt-3">
                                <canvas id="chartRevenue" height="140"></canvas>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold">Tren Jumlah Order</div>
                                <div class="text-xs text-gray-500">Per hari</div>
                            </div>
                            <div class="mt-3">
                                <canvas id="chartOrders" height="140"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Product Stats (range) -->
                    <div class="mt-8 rounded-2xl border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="font-semibold">Statistik Menu (Periode terpilih)</div>
                                <div class="mt-1 text-xs text-gray-500">Urutan berdasarkan total qty terjual dari order completed.</div>
                            </div>
                            <div class="text-xs text-gray-500" x-show="(products || []).length">Top <span x-text="Math.min(10, (products || []).length)"></span> ditampilkan di chart</div>
                        </div>

                        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <div class="font-semibold">Top Menu</div>
                                <div class="mt-3">
                                    <canvas id="chartTopProducts" height="180"></canvas>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <div class="font-semibold">Segmentasi</div>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                                    <div>
                                        <div class="text-xs font-semibold text-emerald-700">Banyak disukai</div>
                                        <div class="mt-2 space-y-2">
                                            @foreach(($segmentsRange['popular'] ?? []) as $r)
                                                <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                                    <div class="font-semibold">{{ $r['product_name'] }}</div>
                                                    <div class="text-xs text-gray-600">Qty: {{ number_format($r['qty'], 0, ',', '.') }}</div>
                                                </div>
                                            @endforeach
                                            @if(empty($segmentsRange['popular'] ?? []))
                                                <div class="text-xs text-gray-500">Belum ada data.</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold text-amber-700">Menengah disukai</div>
                                        <div class="mt-2 space-y-2">
                                            @foreach(($segmentsRange['medium'] ?? []) as $r)
                                                <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                                    <div class="font-semibold">{{ $r['product_name'] }}</div>
                                                    <div class="text-xs text-gray-600">Qty: {{ number_format($r['qty'], 0, ',', '.') }}</div>
                                                </div>
                                            @endforeach
                                            @if(empty($segmentsRange['medium'] ?? []))
                                                <div class="text-xs text-gray-500">Belum ada data.</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-semibold text-slate-700">Jarang disukai</div>
                                        <div class="mt-2 space-y-2">
                                            @foreach(($segmentsRange['rare'] ?? []) as $r)
                                                <div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                                    <div class="font-semibold">{{ $r['product_name'] }}</div>
                                                    <div class="text-xs text-gray-600">Qty: {{ number_format($r['qty'], 0, ',', '.') }}</div>
                                                </div>
                                            @endforeach
                                            @if(empty($segmentsRange['rare'] ?? []))
                                                <div class="text-xs text-gray-500">Belum ada data.</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick stats today/month/year -->
                    <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold">Top Menu Hari Ini</div>
                                <a class="text-xs font-semibold underline" href="{{ route('admin.reports.index', ['period' => 'today']) }}">Lihat</a>
                            </div>
                            <div class="mt-3 space-y-2">
                                @foreach($topToday as $r)
                                    <div class="flex items-center justify-between text-sm rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                        <div class="font-semibold">{{ $r['product_name'] }}</div>
                                        <div class="text-xs text-gray-600">Qty: {{ number_format($r['qty'], 0, ',', '.') }}</div>
                                    </div>
                                @endforeach
                                @if(count($topToday) < 1)
                                    <div class="text-sm text-gray-500">Belum ada data.</div>
                                @endif
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold">Top Menu Bulan Ini</div>
                                <a class="text-xs font-semibold underline" href="{{ route('admin.reports.index', ['period' => 'month']) }}">Lihat</a>
                            </div>
                            <div class="mt-3 space-y-2">
                                @foreach($topMonth as $r)
                                    <div class="flex items-center justify-between text-sm rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                        <div class="font-semibold">{{ $r['product_name'] }}</div>
                                        <div class="text-xs text-gray-600">Qty: {{ number_format($r['qty'], 0, ',', '.') }}</div>
                                    </div>
                                @endforeach
                                @if(count($topMonth) < 1)
                                    <div class="text-sm text-gray-500">Belum ada data.</div>
                                @endif
                            </div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold">Top Menu Tahun Ini</div>
                                <a class="text-xs font-semibold underline" href="{{ route('admin.reports.index', ['period' => 'year']) }}">Lihat</a>
                            </div>
                            <div class="mt-3 space-y-2">
                                @foreach($topYear as $r)
                                    <div class="flex items-center justify-between text-sm rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
                                        <div class="font-semibold">{{ $r['product_name'] }}</div>
                                        <div class="text-xs text-gray-600">Qty: {{ number_format($r['qty'], 0, ',', '.') }}</div>
                                    </div>
                                @endforeach
                                @if(count($topYear) < 1)
                                    <div class="text-sm text-gray-500">Belum ada data.</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 text-xs text-gray-500">
                        Catatan: Jika nanti ada flow pembayaran real (paid/unpaid), kita bisa tambah filter “hanya paid”.
                    </div>


        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            function reportsPage(initialChart, initialProducts) {
                return {
                    period: @js($period),
                    chart: (initialChart || { labels: [], net: [], orders: [] }),
                    products: (initialProducts || []),

                    onPeriodChange(e) {
                        // keep form simple; inputs are handled by Blade visibility
                    },

                    exportSalesUrl() {
                        const url = new URL(@js(route('admin.reports.export.sales')), window.location.origin);
                        url.searchParams.set('period', this.period || 'today');

                        const date = document.querySelector('input[name=date]')?.value;
                        const month = document.querySelector('input[name=month]')?.value;
                        const year = document.querySelector('input[name=year]')?.value;
                        const start = document.querySelector('input[name=start]')?.value;
                        const end = document.querySelector('input[name=end]')?.value;

                        if ((this.period || '') === 'date' && date) url.searchParams.set('date', date);
                        if ((this.period || '') === 'month' && month) url.searchParams.set('month', month);
                        if ((this.period || '') === 'year' && year) url.searchParams.set('year', year);
                        if ((this.period || '') === 'range') {
                            if (start) url.searchParams.set('start', start);
                            if (end) url.searchParams.set('end', end);
                        }
                        return url.toString();
                    },

                    exportProductsUrl() {
                        const url = new URL(@js(route('admin.reports.export.products')), window.location.origin);
                        url.searchParams.set('period', this.period || 'today');

                        const date = document.querySelector('input[name=date]')?.value;
                        const month = document.querySelector('input[name=month]')?.value;
                        const year = document.querySelector('input[name=year]')?.value;
                        const start = document.querySelector('input[name=start]')?.value;
                        const end = document.querySelector('input[name=end]')?.value;

                        if ((this.period || '') === 'date' && date) url.searchParams.set('date', date);
                        if ((this.period || '') === 'month' && month) url.searchParams.set('month', month);
                        if ((this.period || '') === 'year' && year) url.searchParams.set('year', year);
                        if ((this.period || '') === 'range') {
                            if (start) url.searchParams.set('start', start);
                            if (end) url.searchParams.set('end', end);
                        }
                        return url.toString();
                    },

                    init() {
                        this.renderCharts();
                    },

                    renderCharts() {
                        const labels = this.chart.labels || [];
                        const net = this.chart.net || [];
                        const orders = this.chart.orders || [];

                        const ctxRev = document.getElementById('chartRevenue');
                        if (ctxRev) {
                            new Chart(ctxRev, {
                                type: 'line',
                                data: {
                                    labels,
                                    datasets: [{
                                        label: 'Net (Rp)',
                                        data: net,
                                        borderColor: '#0f172a',
                                        backgroundColor: 'rgba(15,23,42,0.08)',
                                        fill: true,
                                        tension: 0.35,
                                    }],
                                },
                                options: {
                                    responsive: true,
                                    plugins: { legend: { display: false } },
                                    scales: {
                                        y: {
                                            ticks: {
                                                callback: (v) => {
                                                    try { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); } catch(e) { return v; }
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        }

                        const ctxOrd = document.getElementById('chartOrders');
                        if (ctxOrd) {
                            new Chart(ctxOrd, {
                                type: 'bar',
                                data: {
                                    labels,
                                    datasets: [{
                                        label: 'Orders',
                                        data: orders,
                                        backgroundColor: 'rgba(16,185,129,0.25)',
                                        borderColor: 'rgba(16,185,129,0.9)',
                                        borderWidth: 1,
                                    }],
                                },
                                options: {
                                    responsive: true,
                                    plugins: { legend: { display: false } },
                                }
                            });
                        }

                        const top = (this.products || []).slice(0, 10);
                        const topLabels = top.map(r => r.product_name);
                        const topQty = top.map(r => Number(r.qty || 0));

                        const ctxTop = document.getElementById('chartTopProducts');
                        if (ctxTop) {
                            new Chart(ctxTop, {
                                type: 'bar',
                                data: {
                                    labels: topLabels,
                                    datasets: [{
                                        label: 'Qty',
                                        data: topQty,
                                        backgroundColor: 'rgba(59,130,246,0.20)',
                                        borderColor: 'rgba(59,130,246,0.9)',
                                        borderWidth: 1,
                                    }],
                                },
                                options: {
                                    responsive: true,
                                    indexAxis: 'y',
                                    plugins: { legend: { display: false } },
                                }
                            });
                        }
                    },
                }
            }
        </script>
    </div>
</div>
@endsection