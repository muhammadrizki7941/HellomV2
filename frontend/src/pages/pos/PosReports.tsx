import { useState, useEffect } from 'react';
import {
  BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip,
  ResponsiveContainer, PieChart, Pie, Cell
} from 'recharts';
import { Download, Calendar, TrendingUp, TrendingDown, ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react';
import {
  getPosReportSummary, getPosReportProducts, getPosReportDaily, getPosReportShifts,
  getPosReportTransactions, getPosReportMargin, exportPosReport,
  type PosReportDaily, type PosReportProducts, type PosReportSummary,
  type PosReportShift, type PosReportTransactions, type PosReportMargin,
} from '@/lib/hellomApi';

// Format harga Indonesia
const formatRp = (amount: number) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(amount);

// Format tanggal pendek
const formatDateShort = (dateStr: string) =>
  new Date(`${dateStr}T12:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric', month: 'short'
  });

const localDateString = (date = new Date()) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

const reportTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Jakarta';
const reportPresets: Array<{ key: 'today' | '7days' | '30days' | 'month' | 'custom'; label: string }> = [
  { key: 'today', label: 'Hari Ini' },
  { key: '7days', label: '7 Hari' },
  { key: '30days', label: '30 Hari' },
  { key: 'month', label: 'Bulan Ini' },
  { key: 'custom', label: 'Custom' },
];

const ReportValue = ({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) => (
  <div className={`flex items-center justify-between gap-4 ${strong ? 'border-t border-gray-100 pt-2 font-bold text-gray-950' : 'text-gray-700'}`}>
    <span>{label}</span>
    <span className="text-right">{value}</span>
  </div>
);

// Warna chart
const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'];

const PosReports = () => {
  type Preset = 'today' | '7days' | '30days' | 'month' | 'custom';
  // State filter
  const [preset, setPreset] = useState<Preset>('today');
  const [startDate, setStartDate] = useState(localDateString());
  const [endDate, setEndDate] = useState(localDateString());
  const [dateBasis, setDateBasis] = useState<'paid_at' | 'created_at'>('paid_at');
  const [isLoading, setIsLoading] = useState(false);
  const [isExporting, setIsExporting] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [transactionPage, setTransactionPage] = useState(1);
  const [shiftPage, setShiftPage] = useState(1);

  // State data
  const [summary, setSummary] = useState<PosReportSummary['summary'] | null>(null);
  const [dailyData, setDailyData] = useState<PosReportDaily['daily']>([]);
  const [topProducts, setTopProducts] = useState<PosReportProducts['top_products']>([]);
  const [topCategories, setTopCategories] = useState<PosReportProducts['top_categories']>([]);
  const [peakHours, setPeakHours] = useState<PosReportDaily['peak_hours']>([]);
  const [paymentBreakdown, setPaymentBreakdown] = useState<PosReportSummary['payment_breakdown']>([]);
  const [shifts, setShifts] = useState<PosReportShift | null>(null);
  const [transactions, setTransactions] = useState<PosReportTransactions | null>(null);
  const [margin, setMargin] = useState<PosReportMargin | null>(null);
  const [chartMode, setChartMode] = useState<'revenue'|'orders'>('revenue');
  // Owner-only cross-outlet aggregate view
  const [isOwner, setIsOwner] = useState(false);
  const [scopeAll, setScopeAll] = useState(false);
  const [outletBreakdown, setOutletBreakdown] = useState<PosReportSummary['outlet_breakdown']>([]);

  // Set preset tanggal
  const handlePreset = (p: Preset) => {
    const today = new Date();
    const fmt = (d: Date) => localDateString(d);

    setPreset(p);
    switch(p) {
      case 'today':
        setStartDate(fmt(today));
        setEndDate(fmt(today));
        break;
      case '7days': {
        const d7 = new Date(today);
        d7.setDate(d7.getDate() - 6);
        setStartDate(fmt(d7));
        setEndDate(fmt(today));
        break;
      }
      case '30days': {
        const d30 = new Date(today);
        d30.setDate(d30.getDate() - 29);
        setStartDate(fmt(d30));
        setEndDate(fmt(today));
        break;
      }
      case 'month': {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        setStartDate(fmt(firstDay));
        setEndDate(fmt(today));
        break;
      }
      case 'custom':
        break;
    }
  };

  // Load semua data
  const loadReports = async () => {
    setIsLoading(true);
    setLoadError('');
    try {
      const params = {
        start_date: startDate,
        end_date: endDate,
        date_basis: dateBasis,
        timezone: reportTimezone,
        scope: scopeAll ? 'all' : undefined,
      };
      const [summaryRes, dailyRes, productsRes, shiftsRes, transactionsRes, marginRes] = await Promise.all([
        getPosReportSummary(params),
        getPosReportDaily(params),
        getPosReportProducts(params),
        getPosReportShifts({ ...params, page: shiftPage, per_page: 15 }),
        getPosReportTransactions({ ...params, page: transactionPage, per_page: 15 }),
        getPosReportMargin(params),
      ]);

      setSummary(summaryRes.summary);
      setDailyData(dailyRes.daily);
      setTopProducts(productsRes.top_products);
      setTopCategories(productsRes.top_categories);
      setPeakHours(dailyRes.peak_hours);
      setPaymentBreakdown(summaryRes.payment_breakdown);
      setIsOwner(Boolean(summaryRes.is_owner));
      setOutletBreakdown(Array.isArray(summaryRes.outlet_breakdown) ? summaryRes.outlet_breakdown : []);
      setShifts(shiftsRes);
      setTransactions(transactionsRes);
      setMargin(marginRes);

    } catch (err) {
      console.error('Gagal load laporan:', err);
      setSummary(null);
      setLoadError(err instanceof Error ? err.message : 'Laporan gagal dimuat. Periksa koneksi lalu coba lagi.');
    } finally {
      setIsLoading(false);
    }
  };

  // Export Excel
  const handleExport = async () => {
    setIsExporting(true);
    try {
      const blob = await exportPosReport({
        start_date: startDate,
        end_date: endDate,
        date_basis: dateBasis,
        timezone: reportTimezone,
        scope: scopeAll ? 'all' : undefined,
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `Laporan-${startDate}-sampai-${endDate}.xlsx`;
      a.click();
      URL.revokeObjectURL(url);

    } catch (err) {
      alert('Gagal export laporan');
    } finally {
      setIsExporting(false);
    }
  };

  useEffect(() => {
    loadReports();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scopeAll, dateBasis, transactionPage, shiftPage]);

  const lowMarginProduct = margin?.products.find(
    (product) => product.tracked_quantity > 0
      && product.estimated_margin_pct !== null
      && product.estimated_margin_pct < 20
  );

  // Kartu ringkasan
  const SummaryCard = ({ icon, label, value, change, color }: {
    icon: string;
    label: string;
    value: string | number;
    change?: number;
    color: string;
  }) => (
    <div className="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
      <div className={`w-10 h-10 rounded-xl flex items-center
        justify-center text-xl mb-3 ${color}`}>
        {icon}
      </div>
      <div className="text-2xl font-bold text-gray-900 mb-1">{value}</div>
      <div className="text-sm text-gray-500 mb-2">{label}</div>
      {change !== undefined && (
        <div className={`text-xs font-semibold flex items-center gap-1
          ${change >= 0 ? 'text-green-600' : 'text-red-500'}`}>
          {change >= 0 ? <TrendingUp size={14} /> : <TrendingDown size={14} />}
          {Math.abs(change)}% vs periode sebelumnya
        </div>
      )}
    </div>
  );

  return (
    <div className="min-h-screen bg-gray-50 p-4 md:p-6">
      {/* HEADER */}
      <div className="flex flex-col md:flex-row md:items-center
        justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            📊 Laporan Keuangan
          </h1>
          <p className="text-gray-500 text-sm mt-1">
            Analisis penjualan, rekonsiliasi kas, dan estimasi margin usaha
          </p>
        </div>
        <button
          onClick={handleExport}
          disabled={isExporting}
          className="flex items-center gap-2 px-5 py-2.5
            bg-amber-400 hover:bg-amber-500 text-[#111111]
            rounded-xl font-semibold transition disabled:opacity-50"
        >
          <Download size={16} />
          {isExporting ? 'Mengekspor...' : 'Export Excel'}
        </button>
      </div>

      {/* OWNER-ONLY: scope toggle (semua outlet vs outlet aktif) */}
      {isOwner && (
        <div className="mb-6 flex flex-wrap items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3">
          <span className="text-sm font-semibold text-amber-900">Tampilan laporan:</span>
          <div className="inline-flex rounded-xl border border-amber-300 bg-white p-1">
            <button
              onClick={() => { setTransactionPage(1); setShiftPage(1); setScopeAll(false); }}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${!scopeAll ? 'bg-amber-400 text-[#111111]' : 'text-amber-700'}`}
            >
              Outlet Aktif
            </button>
            <button
              onClick={() => { setTransactionPage(1); setShiftPage(1); setScopeAll(true); }}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${scopeAll ? 'bg-amber-400 text-[#111111]' : 'text-amber-700'}`}
            >
              Semua Outlet
            </button>
          </div>
          <span className="text-xs text-amber-700">Hanya pemilik organisasi yang bisa melihat gabungan semua outlet.</span>
        </div>
      )}

      {/* FILTER BAR */}
      <div className="bg-white rounded-2xl p-4 shadow-sm
        border border-gray-100 mb-6">
        <div className="flex flex-wrap gap-2 mb-4">
          {reportPresets.map((p) => (
            <button
              key={p.key}
              onClick={() => handlePreset(p.key)}
              className={`px-4 py-2 rounded-xl text-sm font-semibold
                transition ${preset === p.key
                  ? 'bg-amber-400 text-[#111111]'
                  : 'bg-gray-100 text-gray-600 hover:bg-[#fff1c2]'
                }`}
            >
              {p.label}
            </button>
          ))}
        </div>

        {/* Date range + tombol tampilkan */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2">
            <Calendar size={16} className="text-gray-500" />
            <label className="text-sm text-gray-600">Dari:</label>
            <input
              type="date"
              value={startDate}
              onChange={e => { setStartDate(e.target.value); setPreset('custom'); }}
              className="border border-gray-300 rounded-lg px-3 py-1.5
                text-sm text-gray-900"
            />
          </div>
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600">Sampai:</label>
            <input
              type="date"
              value={endDate}
              onChange={e => { setEndDate(e.target.value); setPreset('custom'); }}
              className="border border-gray-300 rounded-lg px-3 py-1.5
                text-sm text-gray-900"
            />
          </div>
          <label className="flex min-w-0 flex-wrap items-center gap-2 text-sm text-gray-600">
            Dasar tanggal:
            <select
              value={dateBasis}
              onChange={e => setDateBasis(e.target.value as 'paid_at' | 'created_at')}
              className="min-w-0 max-w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900"
            >
              <option value="paid_at">Tanggal pembayaran</option>
              <option value="created_at">Tanggal order dibuat</option>
            </select>
          </label>
          <button
            onClick={() => {
              if (transactionPage !== 1 || shiftPage !== 1) {
                setTransactionPage(1);
                setShiftPage(1);
              }
              else loadReports();
            }}
            disabled={isLoading}
            className="px-5 py-2 bg-[#111111] hover:bg-[#2a241d]
              text-white rounded-xl text-sm font-semibold transition disabled:opacity-50"
          >
            🔍 Tampilkan
          </button>
        </div>
      </div>

      {loadError ? (
        <div role="alert" className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          <span>{loadError}</span>
          <button onClick={loadReports} className="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 font-semibold text-red-800 shadow-sm">
            <RefreshCw size={15} /> Coba lagi
          </button>
        </div>
      ) : isLoading ? (
        <div className="text-center py-20 text-gray-400">
          Memuat laporan...
        </div>
      ) : summary && (
        <>
          {/* KARTU RINGKASAN */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <SummaryCard
              icon="💰"
              label="Penjualan Bersih setelah Refund"
              value={formatRp(summary?.total_revenue || 0)}
              change={summary?.revenue_change ?? undefined}
              color="bg-amber-100"
            />
            <SummaryCard
              icon="📋"
              label="Pesanan Dibayar"
              value={`${summary?.total_orders || 0} pesanan`}
              change={summary?.orders_change ?? undefined}
              color="bg-green-50"
            />
            <SummaryCard
              icon="🧾"
              label="Rata-rata per Pesanan Dibayar"
              value={formatRp(summary?.avg_order_value || 0)}
              change={undefined}
              color="bg-yellow-50"
            />
            <SummaryCard
              icon="🍽️"
              label="Total Item Terjual"
              value={`${summary?.total_items_sold || 0} item`}
              change={undefined}
              color="bg-purple-50"
            />
          </div>

          <div className="grid gap-4 md:grid-cols-2 mb-6">
            <section className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
              <h2 className="mb-1 text-lg font-bold text-gray-900">Rekonsiliasi penjualan</h2>
              <p className="mb-4 text-xs text-gray-500">Basis laporan: {dateBasis === 'paid_at' ? 'tanggal pembayaran' : 'tanggal order'} · zona waktu {reportTimezone}. Refund mengurangi nilai penjualan order asal; uang kas fisik direkonsiliasi pada bagian tutup shift.</p>
              <div className="space-y-2 text-sm">
                <ReportValue label="Penjualan kotor sebelum diskon" value={formatRp(summary.gross_sales)} />
                <ReportValue label="Diskon (promo, poin, manual)" value={`− ${formatRp(summary.discount_total)}`} />
                <ReportValue label="Penjualan setelah diskon" value={formatRp(summary.net_sales)} />
                <ReportValue label="Pajak / service / pembulatan" value={formatRp(summary.tax_total + summary.service_total + summary.rounding_total)} />
                <ReportValue label="Refund" value={`− ${formatRp(summary.refunded_total)}`} />
                <ReportValue label="Penjualan bersih setelah refund" value={formatRp(summary.net_collected)} strong />
                <div className="border-t border-gray-100 pt-2 text-xs text-gray-500">
                  {summary.refunded_orders} pesanan direfund · {summary.unpaid_orders} belum dibayar · {summary.cancelled_orders} dibatalkan
                </div>
              </div>
            </section>
            <section className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
              <h2 className="mb-4 text-lg font-bold text-gray-900">Penerimaan per metode pembayaran</h2>
              <div className="space-y-3">
                {paymentBreakdown.map((row) => (
                  <div key={row.payment_method || 'unknown'} className="flex items-center justify-between gap-4 text-sm">
                    <span className="capitalize text-gray-700">{(row.payment_method || 'Tidak diketahui').replaceAll('_', ' ')}</span>
                    <span className="text-right font-semibold text-gray-900">{formatRp(row.total)} <span className="font-normal text-gray-500">· {row.count} transaksi</span></span>
                  </div>
                ))}
                {paymentBreakdown.length === 0 && <p className="py-2 text-sm text-gray-400">Belum ada transaksi dibayar pada periode ini.</p>}
              </div>
              <p className="mt-4 text-xs text-gray-500">Nilai memakai jumlah tagihan final dikurangi refund; uang kembalian tunai tidak dihitung sebagai penjualan. Laporan ini untuk manajemen usaha, bukan pengganti laporan pajak/pembukuan akuntansi.</p>
            </section>
          </div>

          <section className="mb-6 rounded-2xl border border-blue-100 bg-blue-50 p-5">
            <h2 className="mb-2 font-bold text-blue-950">Insight periode ini</h2>
            <div className="space-y-1 text-sm text-blue-900">
              {summary.revenue_change !== null && summary.revenue_change <= -10
                ? <p>Pendapatan bersih turun {Math.abs(summary.revenue_change)}% dibanding periode sebelumnya. Tinjau jam ramai, produk, dan metode pembayaran di bawah.</p>
                : summary.revenue_change !== null && summary.revenue_change >= 10
                  ? <p>Pendapatan bersih naik {summary.revenue_change}% dibanding periode sebelumnya.</p>
                  : <p>{summary.revenue_change === null ? 'Belum ada data periode sebelumnya untuk perbandingan.' : 'Pendapatan bersih relatif stabil dibanding periode sebelumnya.'}</p>}
              {margin?.gross_margin_pct !== null && margin?.gross_margin_pct !== undefined
                ? <p>Estimasi margin produk setelah HPP: {margin.gross_margin_pct}% dengan cakupan biaya {margin.coverage_pct}% dari unit terjual.</p>
                : <p>Belum cukup data HPP terhubung untuk membuat estimasi margin yang dapat dipercaya.</p>}
              {lowMarginProduct && <p>Evaluasi harga jual atau resep <strong>{lowMarginProduct.product_name}</strong>: estimasi margin {lowMarginProduct.estimated_margin_pct}% pada periode ini.</p>}
            </div>
          </section>

          {/* RINCIAN PER OUTLET (owner aggregate) */}
          {scopeAll && outletBreakdown.length > 0 && (() => {
            const grandRevenue = outletBreakdown.reduce((sum, o) => sum + (o.total_revenue || 0), 0);
            const grandOrders = outletBreakdown.reduce((sum, o) => sum + (o.total_orders || 0), 0);
            const ranked = [...outletBreakdown].sort((a, b) => (b.total_revenue || 0) - (a.total_revenue || 0));
            return (
              <div className="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 mb-6">
                <h2 className="text-lg font-bold text-gray-900 mb-1">Rincian per Outlet</h2>
                <p className="mb-4 text-sm text-gray-500">Perbandingan penjualan semua cabang untuk periode terpilih.</p>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-gray-500 border-b border-gray-100">
                        <th className="py-2 pr-3 font-medium">Outlet</th>
                        <th className="py-2 px-3 font-medium text-right">Pesanan</th>
                        <th className="py-2 px-3 font-medium text-right">Pendapatan</th>
                        <th className="py-2 px-3 font-medium text-right">Rata-rata/Order</th>
                        <th className="py-2 pl-3 font-medium text-right">Kontribusi</th>
                      </tr>
                    </thead>
                    <tbody>
                      {ranked.map((o) => {
                        const revenue = o.total_revenue || 0;
                        const orders = o.total_orders || 0;
                        const aov = orders > 0 ? Math.round(revenue / orders) : 0;
                        const share = grandRevenue > 0 ? Math.round((revenue / grandRevenue) * 100) : 0;
                        return (
                          <tr key={o.outlet_id} className="border-b border-gray-50 last:border-0">
                            <td className="py-2.5 pr-3 font-medium text-gray-900">
                              {o.name}
                              {o.is_primary && <span className="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">Utama</span>}
                            </td>
                            <td className="py-2.5 px-3 text-right text-gray-700">{orders}</td>
                            <td className="py-2.5 px-3 text-right font-semibold text-gray-900">{formatRp(revenue)}</td>
                            <td className="py-2.5 px-3 text-right text-gray-700">{formatRp(aov)}</td>
                            <td className="py-2.5 pl-3 text-right">
                              <div className="flex items-center justify-end gap-2">
                                <div className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-gray-100 sm:block">
                                  <div className="h-full rounded-full bg-amber-400" style={{ width: `${share}%` }} />
                                </div>
                                <span className="font-medium text-gray-700">{share}%</span>
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                    <tfoot>
                      <tr className="border-t border-gray-200 font-semibold text-gray-900">
                        <td className="py-2.5 pr-3">Total semua outlet</td>
                        <td className="py-2.5 px-3 text-right">{grandOrders}</td>
                        <td className="py-2.5 px-3 text-right">{formatRp(grandRevenue)}</td>
                        <td className="py-2.5 px-3 text-right">{formatRp(grandOrders > 0 ? Math.round(grandRevenue / grandOrders) : 0)}</td>
                        <td className="py-2.5 pl-3 text-right">100%</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            );
          })()}

          {/* GRAFIK HARIAN */}
          <div className="bg-white rounded-2xl p-5 shadow-sm
            border border-gray-100 mb-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold text-gray-900">
                Tren Penjualan
              </h2>
              <div className="flex gap-2">
                <button
                  onClick={() => setChartMode('revenue')}
                  className={`px-3 py-1 rounded-lg text-xs font-semibold
                    ${chartMode === 'revenue'
                      ? 'bg-amber-400 text-[#111111]'
                      : 'bg-gray-100 text-gray-600'}`}
                >
                  Pendapatan
                </button>
                <button
                  onClick={() => setChartMode('orders')}
                  className={`px-3 py-1 rounded-lg text-xs font-semibold
                    ${chartMode === 'orders'
                      ? 'bg-amber-400 text-[#111111]'
                      : 'bg-gray-100 text-gray-600'}`}
                >
                  Pesanan
                </button>
              </div>
            </div>
            <ResponsiveContainer width="100%" height={250} minWidth={0}>
              <BarChart data={dailyData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis
                  dataKey="date"
                  tick={{ fontSize: 11 }}
                  tickFormatter={formatDateShort}
                />
                <YAxis
                  tick={{ fontSize: 11 }}
                  tickFormatter={v => chartMode === 'revenue'
                    ? `${(v/1000).toFixed(0)}rb` : v}
                />
                <Tooltip
                  formatter=                                    {(value: number | string | Array<number | string> | undefined) => [
                    chartMode === 'revenue'
                      ? formatRp(Number(value ?? 0)) : `${value ?? 0} pesanan`,
                    chartMode === 'revenue' ? 'Revenue' : 'Orders'
                  ]}
                  labelFormatter={(label) => formatDateShort(String(label))}
                />
                <Bar
                  dataKey={chartMode === 'revenue'
                    ? 'total_revenue' : 'total_orders'}
                  fill="#f59e0b"
                  radius={[4, 4, 0, 0]}
                />
              </BarChart>
            </ResponsiveContainer>
          </div>

          <div className="grid md:grid-cols-2 gap-6 mb-6">
            {/* PRODUK TERLARIS */}
            <div className="bg-white rounded-2xl p-5 shadow-sm
              border border-gray-100">
              <h2 className="text-lg font-bold text-gray-900 mb-4">
                🏆 Produk Terlaris
              </h2>
              <div className="space-y-3">
                {topProducts.slice(0, 8).map((p, i) => {
                  const maxQty = topProducts[0]?.total_qty || 1;
                  const pct = Math.round((p.total_qty / maxQty) * 100);
                  return (
                    <div key={i}>
                      <div className="flex justify-between text-sm mb-1">
                        <span className="font-medium text-gray-800 flex gap-2">
                          <span className={`w-5 h-5 rounded-full flex
                            items-center justify-center text-xs font-bold
                            ${i === 0 ? 'bg-yellow-400 text-white'
                              : i === 1 ? 'bg-gray-300 text-gray-700'
                              : i === 2 ? 'bg-orange-400 text-white'
                              : 'bg-gray-100 text-gray-500'}`}>
                            {i + 1}
                          </span>
                          {p.product_name}
                        </span>
                        <span className="text-gray-500">
                          {p.total_qty}x · {formatRp(p.total_revenue)}
                        </span>
                      </div>
                      <div className="w-full bg-gray-100 rounded-full h-1.5">
                        <div
                          className="bg-amber-400 h-1.5 rounded-full"
                          style={{ width: `${pct}%` }}
                        />
                      </div>
                    </div>
                  );
                })}
                {topProducts.length === 0 && (
                  <p className="text-gray-400 text-center py-4">
                    Belum ada data produk
                  </p>
                )}
              </div>
            </div>

            {/* KATEGORI & JAM TERSIBUK */}
            <div className="space-y-6">
              {/* Kategori Terlaris */}
              <div className="bg-white rounded-2xl p-5 shadow-sm
                border border-gray-100">
                <h2 className="text-lg font-bold text-gray-900 mb-4">
                  📂 Kategori Terlaris
                </h2>
                {topCategories.length > 0 ? (
                  <div className="flex gap-4 items-center">
                    <PieChart width={120} height={120}>
                      <Pie
                        data={topCategories}
                        cx={55} cy={55}
                        innerRadius={35} outerRadius={55}
                        dataKey="total_revenue"
                      >
                        {topCategories.map((_, i) => (
                          <Cell key={i} fill={COLORS[i % COLORS.length]} />
                        ))}
                      </Pie>
                    </PieChart>
                    <div className="flex-1 space-y-2">
                      {topCategories.map((c, i) => (
                        <div key={i} className="flex items-center gap-2">
                          <div className="w-3 h-3 rounded-full flex-shrink-0"
                            style={{ backgroundColor: COLORS[i % COLORS.length] }}
                          />
                          <span className="text-sm text-gray-700 flex-1">
                            {c.category_name}
                          </span>
                          <span className="text-xs text-gray-500">
                            {formatRp(c.total_revenue)}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                ) : (
                  <p className="text-gray-400 text-center py-4">
                    Belum ada data kategori
                  </p>
                )}
              </div>

              {/* Jam Tersibuk */}
              <div className="bg-white rounded-2xl p-5 shadow-sm
                border border-gray-100">
                <h2 className="text-lg font-bold text-gray-900 mb-4">
                  ⏰ Jam Tersibuk
                </h2>
                <div className="space-y-2">
                  {peakHours.map((h, i) => (
                    <div key={i} className="flex items-center gap-3">
                      <span className="text-sm text-gray-600 w-16">
                        {String(h.hour).padStart(2,'0')}:00
                      </span>
                      <div className="flex-1 bg-gray-100 rounded-full h-2">
                        <div
                          className="bg-orange-400 h-2 rounded-full"
                          style={{
                            width: `${(h.count / peakHours[0]?.count) * 100}%`
                          }}
                        />
                      </div>
                      <span className="text-sm text-gray-500 w-16 text-right">
                        {h.count} order
                      </span>
                    </div>
                  ))}
                  {peakHours.length === 0 && (
                    <p className="text-gray-400 text-center py-2">
                      Belum ada data
                    </p>
                  )}
                </div>
              </div>
            </div>
          </div>

          <section className="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-lg font-bold text-gray-900">Estimasi laba setelah HPP & margin produk</h2>
              <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800">
                HPP tercatat untuk {margin?.coverage_pct ?? 0}% unit
              </span>
            </div>
            <p className="mb-4 text-xs text-gray-500">{margin?.disclaimer || 'Tautkan produk POS dengan Kalkulator HPP untuk melihat estimasi.'}</p>
            {margin?.estimated_profit === null || margin?.estimated_profit === undefined ? (
              <p className="rounded-xl bg-gray-50 p-4 text-sm text-gray-600">
                Belum tersedia estimasi laba. Pastikan produk POS sudah ditautkan ke Kalkulator HPP dan biaya produk sudah dihitung. Omzet bukan laba.
              </p>
            ) : (
              <>
                <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
                  <div className="rounded-xl bg-emerald-50 p-3">
                    <p className="text-xs text-emerald-800">Estimasi laba kotor</p>
                    <p className="mt-1 font-bold text-emerald-950">{formatRp(margin.estimated_profit)}</p>
                  </div>
                  <div className="rounded-xl bg-amber-50 p-3">
                    <p className="text-xs text-amber-800">Estimasi margin</p>
                    <p className="mt-1 font-bold text-amber-950">{margin.gross_margin_pct ?? 0}%</p>
                  </div>
                  <div className="col-span-2 rounded-xl bg-gray-50 p-3 md:col-span-1">
                    <p className="text-xs text-gray-600">Cakupan data HPP</p>
                    <p className="mt-1 font-bold text-gray-900">{margin.tracked_units} dari {margin.total_units} unit</p>
                  </div>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[620px] text-sm">
                    <thead><tr className="border-b text-left text-xs text-gray-500">
                      <th className="py-2">Produk</th><th className="py-2 text-right">Terjual</th>
                      <th className="py-2 text-right">Penjualan bersih item</th><th className="py-2 text-right">Estimasi HPP</th>
                      <th className="py-2 text-right">Laba kotor</th><th className="py-2 text-right">Margin</th>
                    </tr></thead>
                    <tbody>{margin.products.slice(0, 10).map((product) => (
                      <tr key={product.product_name} className="border-b border-gray-50">
                        <td className="py-2.5 font-medium text-gray-900">{product.product_name}</td>
                        <td className="py-2.5 text-right text-gray-700">{product.quantity} <span className="text-xs text-gray-400">({product.tracked_quantity} ber-HPP)</span></td>
                        <td className="py-2.5 text-right">{formatRp(product.net_product_sales)}</td>
                        <td className="py-2.5 text-right">{product.tracked_quantity ? formatRp(product.estimated_cost) : 'Belum ada HPP'}</td>
                        <td className="py-2.5 text-right font-semibold">{product.tracked_quantity ? formatRp(product.estimated_profit) : '—'}</td>
                        <td className={`py-2.5 text-right font-semibold ${(product.estimated_margin_pct ?? 100) < 20 ? 'text-red-600' : 'text-gray-800'}`}>
                          {product.estimated_margin_pct === null ? '—' : `${product.estimated_margin_pct}%`}
                        </td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              </>
            )}
          </section>

          <section className="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-bold text-gray-900">Tutup shift & rekonsiliasi kas</h2>
                <p className="text-xs text-gray-500">Shift yang ditutup di periode ini; selisih = kas dihitung − kas yang diharapkan.</p>
              </div>
              <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">
                {shifts?.summary.closed_shifts ?? 0} ditutup · {shifts?.summary.open_shifts ?? 0} masih terbuka
              </span>
              <div className="flex items-center gap-2">
                <button aria-label="Halaman shift sebelumnya" disabled={shiftPage <= 1 || isLoading} onClick={() => setShiftPage(page => Math.max(1, page - 1))} className="rounded-lg border p-2 disabled:opacity-40"><ChevronLeft size={16} /></button>
                <span className="text-xs text-gray-600">Halaman {shifts?.pagination.current_page ?? 1} / {shifts?.pagination.last_page ?? 1}</span>
                <button aria-label="Halaman shift berikutnya" disabled={shiftPage >= (shifts?.pagination.last_page ?? 1) || isLoading} onClick={() => setShiftPage(page => page + 1)} className="rounded-lg border p-2 disabled:opacity-40"><ChevronRight size={16} /></button>
              </div>
            </div>
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
              <ReportValue label="Penjualan tunai" value={formatRp(shifts?.summary.cash_sales ?? 0)} />
              <ReportValue label="Kas yang diharapkan" value={formatRp(shifts?.summary.expected_cash ?? 0)} />
              <ReportValue label="Kas dihitung" value={formatRp(shifts?.summary.closing_cash ?? 0)} />
              <ReportValue label="Selisih kas" value={formatRp(shifts?.summary.difference_cash ?? 0)} strong />
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead><tr className="border-b text-left text-xs text-gray-500">
                  <th className="py-2">Kasir / Shift</th><th className="py-2">Status</th>
                  <th className="py-2 text-right">Kas Awal</th><th className="py-2 text-right">Tunai Masuk</th>
                  <th className="py-2 text-right">Seharusnya</th><th className="py-2 text-right">Dihitung</th>
                  <th className="py-2 text-right">Selisih</th>
                </tr></thead>
                <tbody>{shifts?.shifts.map((shift) => (
                  <tr key={shift.id} className="border-b border-gray-50">
                    <td className="py-2.5 font-medium text-gray-900">{shift.staff_name}{shift.outlet_name && <span className="ml-1 font-normal text-gray-500">· {shift.outlet_name}</span>}<span className="block text-xs font-normal text-gray-500">{shift.shift_title || 'Shift'} · {shift.closed_at ? new Date(shift.closed_at).toLocaleString('id-ID') : 'Belum ditutup'}</span></td>
                    <td className="py-2.5"><span className={`rounded-full px-2 py-1 text-xs ${shift.status === 'closed' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-800'}`}>{shift.status === 'closed' ? 'Ditutup' : 'Terbuka'}</span></td>
                    <td className="py-2.5 text-right">{formatRp(shift.opening_cash)}</td>
                    <td className="py-2.5 text-right">{formatRp(shift.cash_sales)}</td>
                    <td className="py-2.5 text-right">{shift.expected_cash === null ? '—' : formatRp(shift.expected_cash)}</td>
                    <td className="py-2.5 text-right">{shift.closing_cash === null ? '—' : formatRp(shift.closing_cash)}</td>
                    <td className={`py-2.5 text-right font-semibold ${shift.difference_cash && shift.difference_cash !== 0 ? 'text-red-600' : 'text-gray-800'}`}>{shift.difference_cash === null ? '—' : formatRp(shift.difference_cash)}</td>
                  </tr>
                ))}</tbody>
              </table>
              {shifts?.shifts.length === 0 && <p className="py-5 text-center text-sm text-gray-400">Belum ada catatan tutup shift pada periode ini.</p>}
            </div>
            {(shifts?.summary.open_shifts ?? 0) > 0 && <p className="mt-3 text-xs text-amber-800">Ada sesi kas yang belum ditutup; rekonsiliasi shift tersebut belum final.</p>}
          </section>

          <section className="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-bold text-gray-900">Rincian transaksi</h2>
                <p className="text-xs text-gray-500">{transactions?.pagination.total ?? 0} transaksi lunas/refund · klik satu order untuk melihat item.</p>
              </div>
              <div className="flex items-center gap-2">
                <button aria-label="Halaman sebelumnya" disabled={transactionPage <= 1 || isLoading} onClick={() => setTransactionPage(page => Math.max(1, page - 1))} className="rounded-lg border p-2 disabled:opacity-40"><ChevronLeft size={16} /></button>
                <span className="text-xs text-gray-600">Halaman {transactions?.pagination.current_page ?? 1} / {transactions?.pagination.last_page ?? 1}</span>
                <button aria-label="Halaman berikutnya" disabled={transactionPage >= (transactions?.pagination.last_page ?? 1) || isLoading} onClick={() => setTransactionPage(page => page + 1)} className="rounded-lg border p-2 disabled:opacity-40"><ChevronRight size={16} /></button>
              </div>
            </div>
            <div className="space-y-2">
              {transactions?.transactions.map((transaction) => (
                <details key={transaction.id} className="rounded-xl border border-gray-100 px-4 py-3">
                  <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm">
                    <span className="font-semibold text-gray-900">#{transaction.order_number} · {transaction.customer_name}</span>
                    <span className="text-gray-500">{transaction.payment_method.replaceAll('_', ' ')} · {transaction.report_at ? new Date(transaction.report_at).toLocaleString('id-ID') : 'Waktu tidak tersedia'}</span>
                    <span className={`font-semibold ${transaction.net_collected < 0 ? 'text-red-600' : 'text-gray-900'}`}>{formatRp(transaction.net_collected)}{transaction.refund_amount > 0 ? ' setelah refund' : ''}</span>
                  </summary>
                  <div className="mt-3 border-t border-gray-100 pt-3">
                    <div className="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                      <span>Subtotal {formatRp(transaction.subtotal_amount)}</span><span>Diskon {formatRp(transaction.discount_amount)}</span>
                      <span>Pajak {formatRp(transaction.tax_amount)}</span><span>Tagihan {formatRp(transaction.final_amount)}</span>
                      <span>Status: {transaction.payment_status === 'refunded' ? 'Direfund' : 'Dibayar'}</span>
                    </div>
                    {transaction.items.map((item, index) => (
                      <div key={`${transaction.id}-${index}`} className="flex justify-between gap-3 py-1 text-sm text-gray-700">
                        <span>{item.product_name} × {item.quantity}</span><span>{formatRp(item.line_total)}</span>
                      </div>
                    ))}
                  </div>
                </details>
              ))}
              {transactions?.transactions.length === 0 && <p className="py-5 text-center text-sm text-gray-400">Tidak ada transaksi lunas pada periode ini.</p>}
            </div>
          </section>
        </>
      )}
    </div>
  );
};

export default PosReports;
