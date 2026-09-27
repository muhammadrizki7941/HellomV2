import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import {
  Activity,
  ArrowRight,
  BarChart3,
  Clock3,
  CreditCard,
  DollarSign,
  Package2,
  Receipt,
  ShoppingCart,
  Store,
  Table2,
  TrendingUp,
} from 'lucide-react';
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {
  getPosOrders,
  getPosProducts,
  getPosReportDaily,
  getPosReportProducts,
  getPosReportSummary,
  getPosTables,
} from '@/lib/hellomApi';

type DashboardSummary = {
  total_revenue: number;
  total_orders: number;
  avg_order_value: number;
  total_items_sold: number;
  revenue_change: number;
  orders_change: number;
};

type DailyPoint = {
  date: string;
  total_orders: number;
  total_revenue: number;
  avg_order: number;
};

type TopProduct = {
  product_name: string;
  product_id: number;
  total_qty: number;
  total_revenue: number;
  avg_price: number;
  order_count: number;
};

type PaymentBreakdown = {
  payment_method: string;
  count: number;
  total: number;
};

type PeakHour = {
  hour: number;
  count: number;
  total: number;
};

type PosOrder = {
  id: number;
  order_number: string;
  customer_name: string;
  table: {
    id: number;
    code: string;
    name: string;
  } | null;
  table_label: string;
  service_type: string;
  status: string;
  payment_status: string;
  total_amount: number;
  created_at: string;
  updated_at: string;
  items_count: number;
};

type DashboardData = {
  todaySummary: DashboardSummary;
  monthSummary: DashboardSummary;
  trend: DailyPoint[];
  topProducts: TopProduct[];
  paymentBreakdown: PaymentBreakdown[];
  peakHours: PeakHour[];
  orders: PosOrder[];
  productsCount: number;
  activeProductsCount: number;
  totalTablesCount: number;
  activeTablesCount: number;
};

const ACTIVE_ORDER_STATUSES = ['new', 'accepted', 'preparing', 'prepared'];

const formatCurrency = (amount: number) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(amount);

const formatCompactCurrency = (amount: number) =>
  new Intl.NumberFormat('id-ID', {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(amount);

const formatDate = (value: string) =>
  new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
  }).format(new Date(value));

const formatTime = (value: string) =>
  new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));

const startOfMonth = () => {
  const date = new Date();
  return new Date(date.getFullYear(), date.getMonth(), 1);
};

const toInputDate = (value: Date) => value.toISOString().split('T')[0];

const today = toInputDate(new Date());
const monthStart = toInputDate(startOfMonth());
const last7DaysStart = toInputDate(new Date(Date.now() - 6 * 24 * 60 * 60 * 1000));

function statusLabel(status: string) {
  switch (status) {
    case 'new':
      return 'Baru';
    case 'accepted':
      return 'Diterima';
    case 'preparing':
      return 'Diproses';
    case 'prepared':
      return 'Siap';
    case 'completed':
      return 'Selesai';
    case 'cancelled':
      return 'Dibatalkan';
    default:
      return status;
  }
}

function statusTone(status: string) {
  switch (status) {
    case 'completed':
      return 'bg-amber-100 text-amber-900 ring-amber-200';
    case 'cancelled':
      return 'bg-rose-50 text-rose-700 ring-rose-200';
    case 'prepared':
      return 'bg-amber-50 text-amber-700 ring-amber-200';
    default:
      return 'bg-zinc-100 text-zinc-800 ring-zinc-200';
  }
}

type OutletBreakdownRow = {
  outlet_id: number;
  name: string;
  is_primary: boolean;
  total_orders: number;
  total_revenue: number;
};

export default function PosAdminDashboard() {
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dashboard, setDashboard] = useState<DashboardData | null>(null);
  // Owner-only cross-outlet aggregate view.
  const [isOwner, setIsOwner] = useState(false);
  const [scopeAll, setScopeAll] = useState(false);
  const [outletBreakdown, setOutletBreakdown] = useState<OutletBreakdownRow[]>([]);

  const loadDashboard = async (scopeAllArg = scopeAll) => {
    setIsLoading(true);
    setError(null);

    try {
      const scope = scopeAllArg ? 'all' : undefined;
      const [
        todayReport,
        monthReport,
        trendReport,
        topProductsReport,
        ordersResponse,
        productsResponse,
        tablesResponse,
      ] = await Promise.all([
        getPosReportSummary({ start_date: today, end_date: today, scope }),
        getPosReportSummary({ start_date: monthStart, end_date: today, scope }),
        getPosReportDaily({ start_date: last7DaysStart, end_date: today, scope }),
        getPosReportProducts({ start_date: monthStart, end_date: today, limit: 5, scope }),
        getPosOrders(),
        getPosProducts(),
        getPosTables(),
      ]);

      setIsOwner(Boolean((monthReport as any).is_owner));
      setOutletBreakdown(
        Array.isArray((monthReport as any).outlet_breakdown) ? (monthReport as any).outlet_breakdown : []
      );

      setDashboard({
        todaySummary: todayReport.summary,
        monthSummary: monthReport.summary,
        trend: trendReport.daily,
        topProducts: topProductsReport.top_products,
        paymentBreakdown: todayReport.payment_breakdown,
        peakHours: todayReport.peak_hours,
        orders: ordersResponse.orders,
        productsCount: productsResponse.products.length,
        activeProductsCount: productsResponse.products.filter((product) => product.is_available).length,
        totalTablesCount: tablesResponse.tables.length,
        activeTablesCount: tablesResponse.tables.filter((table) => table.is_active).length,
      });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Gagal memuat dashboard POS.';
      setError(message);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadDashboard(scopeAll);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scopeAll]);

  const activeOrders = useMemo(
    () => dashboard?.orders.filter((order) => ACTIVE_ORDER_STATUSES.includes(order.status)) ?? [],
    [dashboard]
  );

  const recentOrders = useMemo(
    () =>
      [...(dashboard?.orders ?? [])]
        .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
        .slice(0, 6),
    [dashboard]
  );

  const topPaymentMethod = useMemo(() => {
    const first = dashboard?.paymentBreakdown[0];
    if (!first) return null;

    return {
      name: first.payment_method ? first.payment_method.replaceAll('_', ' ') : 'Tidak diketahui',
      total: first.total,
      count: first.count,
    };
  }, [dashboard]);

  const peakHour = useMemo(() => dashboard?.peakHours[0] ?? null, [dashboard]);

  if (isLoading) {
    return (
      <div className="flex min-h-[70vh] items-center justify-center">
        <div className="space-y-4 text-center">
          <div className="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-zinc-200 border-t-amber-400" />
          <div>
            <p className="text-lg font-semibold text-zinc-900">Memuat dashboard POS</p>
            <p className="text-sm text-zinc-500">Menarik ringkasan order, penjualan, dan performa hari ini.</p>
          </div>
        </div>
      </div>
    );
  }

  if (error || !dashboard) {
    return (
      <div className="rounded-3xl border border-rose-200 bg-rose-50 p-8 text-center">
        <p className="text-lg font-semibold text-rose-800">Dashboard belum bisa dimuat</p>
        <p className="mt-2 text-sm text-rose-700">{error ?? 'Terjadi kesalahan yang tidak diketahui.'}</p>
        <button
          onClick={() => loadDashboard()}
          className="mt-5 rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-800"
        >
          Coba lagi
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {isOwner && (
        <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3">
          <span className="text-sm font-semibold text-amber-900">Tampilan ringkasan:</span>
          <div className="inline-flex rounded-xl border border-amber-300 bg-white p-1">
            <button
              onClick={() => setScopeAll(false)}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${!scopeAll ? 'bg-amber-400 text-[#111111]' : 'text-amber-700'}`}
            >
              Outlet Aktif
            </button>
            <button
              onClick={() => setScopeAll(true)}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition ${scopeAll ? 'bg-amber-400 text-[#111111]' : 'text-amber-700'}`}
            >
              Semua Outlet
            </button>
          </div>
          <span className="text-xs text-amber-700">
            {scopeAll
              ? 'Penjualan & tren menggabungkan semua outlet. Widget operasional (order berjalan, produk, meja) tetap mengikuti outlet aktif.'
              : 'Hanya outlet yang sedang aktif. Pilih "Semua Outlet" untuk gabungan semua cabang.'}
          </span>
        </div>
      )}

      <section className="overflow-hidden rounded-[28px] bg-zinc-950 text-white">
        <div className="grid gap-6 px-6 py-7 lg:grid-cols-[1.7fr_1fr] lg:px-8">
          <div className="space-y-4">
            <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-amber-200">
              <Activity className="h-3.5 w-3.5" />
              Ringkasan operasional real-time
            </div>
            <div>
              <h1 className="text-3xl font-semibold tracking-tight">Dashboard POS</h1>
              <p className="mt-2 max-w-2xl text-sm text-zinc-300">
                Gambaran singkat performa outlet hari ini, aktivitas order yang masih berjalan, dan sinyal penjualan yang
                perlu dipantau admin.
              </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-zinc-400">Pendapatan Hari Ini</p>
                <p className="mt-3 text-2xl font-semibold">{formatCurrency(dashboard.todaySummary.total_revenue)}</p>
                <p className="mt-2 text-xs text-amber-200">
                  {dashboard.todaySummary.revenue_change >= 0 ? '+' : ''}
                  {dashboard.todaySummary.revenue_change}% dibanding periode sebelumnya
                </p>
              </div>

              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-zinc-400">Active Orders</p>
                <p className="mt-3 text-2xl font-semibold">{activeOrders.length}</p>
                <p className="mt-2 text-xs text-zinc-300">
                  {dashboard.todaySummary.total_orders} order selesai tercatat hari ini
                </p>
              </div>

              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-xs uppercase tracking-[0.18em] text-zinc-400">Rata-rata Order</p>
                <p className="mt-3 text-2xl font-semibold">{formatCurrency(dashboard.todaySummary.avg_order_value)}</p>
                <p className="mt-2 text-xs text-zinc-300">
                  {dashboard.todaySummary.total_items_sold} item terjual hari ini
                </p>
              </div>
            </div>
          </div>

          <div className="grid gap-3 self-start">
            <QuickActionCard
              to="/pos/orders"
              title="Pantau order masuk"
              description="Lihat antrean order baru dan proses pesanan yang sedang berjalan."
              icon={<ShoppingCart className="h-5 w-5" />}
            />
            <QuickActionCard
              to="/pos/menu"
              title="Perbarui menu aktif"
              description="Cek produk yang tampil hari ini dan pastikan stok jual tetap aman."
              icon={<Package2 className="h-5 w-5" />}
            />
            <QuickActionCard
              to="/pos/reports"
              title="Buka laporan lengkap"
              description="Masuk ke analitik penjualan dan export laporan untuk pembukuan."
              icon={<BarChart3 className="h-5 w-5" />}
            />
          </div>
        </div>
      </section>

      {scopeAll && outletBreakdown.length > 0 && (() => {
        const grandRevenue = outletBreakdown.reduce((s, o) => s + (o.total_revenue || 0), 0);
        const grandOrders = outletBreakdown.reduce((s, o) => s + (o.total_orders || 0), 0);
        const ranked = [...outletBreakdown].sort((a, b) => (b.total_revenue || 0) - (a.total_revenue || 0));
        return (
          <section className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-bold text-zinc-900">Ringkasan per Outlet</h2>
            <p className="mb-4 text-sm text-zinc-500">Penjualan semua cabang bulan ini ({dashboard.monthSummary.total_orders} order selesai).</p>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-zinc-100 text-left text-zinc-500">
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
                      <tr key={o.outlet_id} className="border-b border-zinc-50 last:border-0">
                        <td className="py-2.5 pr-3 font-medium text-zinc-900">
                          {o.name}
                          {o.is_primary && <span className="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">Utama</span>}
                        </td>
                        <td className="py-2.5 px-3 text-right text-zinc-700">{orders}</td>
                        <td className="py-2.5 px-3 text-right font-semibold text-zinc-900">{formatCurrency(revenue)}</td>
                        <td className="py-2.5 px-3 text-right text-zinc-700">{formatCurrency(aov)}</td>
                        <td className="py-2.5 pl-3 text-right">
                          <div className="flex items-center justify-end gap-2">
                            <div className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-zinc-100 sm:block">
                              <div className="h-full rounded-full bg-amber-400" style={{ width: `${share}%` }} />
                            </div>
                            <span className="font-medium text-zinc-700">{share}%</span>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot>
                  <tr className="border-t border-zinc-200 font-semibold text-zinc-900">
                    <td className="py-2.5 pr-3">Total semua outlet</td>
                    <td className="py-2.5 px-3 text-right">{grandOrders}</td>
                    <td className="py-2.5 px-3 text-right">{formatCurrency(grandRevenue)}</td>
                    <td className="py-2.5 px-3 text-right">{formatCurrency(grandOrders > 0 ? Math.round(grandRevenue / grandOrders) : 0)}</td>
                    <td className="py-2.5 pl-3 text-right">100%</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </section>
        );
      })()}

      <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          title="Pendapatan Bulan Ini"
          value={formatCurrency(dashboard.monthSummary.total_revenue)}
          detail={`${dashboard.monthSummary.total_orders} order selesai`}
              icon={<DollarSign className="h-5 w-5" />}
          tone="amber"
        />
        <MetricCard
          title="Active Products"
          value={`${dashboard.activeProductsCount}/${dashboard.productsCount}`}
          detail="produk tersedia untuk dijual"
          icon={<Store className="h-5 w-5" />}
          tone="zinc"
        />
        <MetricCard
          title="Meja Aktif"
          value={`${dashboard.activeTablesCount}/${dashboard.totalTablesCount}`}
          detail="meja aktif untuk self-order"
          icon={<Table2 className="h-5 w-5" />}
          tone="amber"
        />
        <MetricCard
          title="Metode Bayar Utama"
          value={topPaymentMethod ? formatCompactCurrency(topPaymentMethod.total) : 'Belum ada'}
          detail={
            topPaymentMethod
              ? `${topPaymentMethod.name} • ${topPaymentMethod.count} transaksi`
              : 'Belum ada transaksi hari ini'
          }
          icon={<CreditCard className="h-5 w-5" />}
          tone="rose"
        />
      </section>

      <section className="grid gap-6 xl:grid-cols-[1.75fr_1fr]">
        <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <h2 className="text-lg font-semibold text-zinc-900">Tren 7 Hari Terakhir</h2>
              <p className="mt-1 text-sm text-zinc-500">Pergerakan pendapatan harian untuk membaca ritme penjualan outlet.</p>
            </div>
            <div className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">
              {dashboard.trend.length} hari terpantau
            </div>
          </div>

          <ResponsiveContainer width="100%" height={280} minWidth={0}>
            <AreaChart data={dashboard.trend}>
              <defs>
                <linearGradient id="salesArea" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#f59e0b" stopOpacity={0.35} />
                  <stop offset="95%" stopColor="#f59e0b" stopOpacity={0.02} />
                </linearGradient>
              </defs>
              <CartesianGrid stroke="#e4e4e7" strokeDasharray="3 3" vertical={false} />
              <XAxis dataKey="date" tickFormatter={formatDate} tick={{ fontSize: 12, fill: '#71717a' }} axisLine={false} tickLine={false} />
              <YAxis
                tickFormatter={(value) => `${Math.round(value / 1000)}k`}
                tick={{ fontSize: 12, fill: '#71717a' }}
                axisLine={false}
                tickLine={false}
              />
              <Tooltip
                formatter={(value: number) => [formatCurrency(value), 'Pendapatan']}
                labelFormatter={(label) => formatDate(label)}
                contentStyle={{
                  borderRadius: 16,
                  border: '1px solid #e4e4e7',
                  boxShadow: '0 12px 30px rgba(15, 23, 42, 0.08)',
                }}
              />
              <Area type="monotone" dataKey="total_revenue" stroke="#d97706" strokeWidth={3} fill="url(#salesArea)" />
            </AreaChart>
          </ResponsiveContainer>
        </div>

        <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h2 className="text-lg font-semibold text-zinc-900">Jam Paling Sibuk</h2>
              <p className="mt-1 text-sm text-zinc-500">Jam order tertinggi berdasarkan transaksi selesai hari ini.</p>
            </div>
            <Clock3 className="h-5 w-5 text-zinc-400" />
          </div>

          <div className="mt-5 space-y-4">
            {dashboard.peakHours.length > 0 ? (
              dashboard.peakHours.map((hour) => {
                const maxCount = dashboard.peakHours[0]?.count || 1;
                const width = Math.max((hour.count / maxCount) * 100, 8);

                return (
                  <div key={hour.hour} className="space-y-2">
                    <div className="flex items-center justify-between text-sm">
                      <p className="font-medium text-zinc-800">{String(hour.hour).padStart(2, '0')}:00</p>
                      <p className="text-zinc-500">{hour.count} order</p>
                    </div>
                    <div className="h-2 rounded-full bg-zinc-100">
                      <div className="h-2 rounded-full bg-amber-500" style={{ width: `${width}%` }} />
                    </div>
                    <p className="text-xs text-zinc-500">{formatCurrency(hour.total)} pendapatan pada jam ini</p>
                  </div>
                );
              })
            ) : (
              <EmptyHint text="Belum ada order selesai hari ini, jadi jam sibuk belum terbentuk." />
            )}

            <div className="rounded-2xl bg-zinc-50 p-4">
              <p className="text-xs uppercase tracking-[0.16em] text-zinc-400">Highlight</p>
              <p className="mt-2 text-sm text-zinc-700">
                {peakHour
                  ? `Puncak aktivitas ada sekitar pukul ${String(peakHour.hour).padStart(2, '0')}:00 dengan ${peakHour.count} order selesai.`
                  : 'Belum ada data puncak aktivitas untuk hari ini.'}
              </p>
            </div>
          </div>
        </div>
      </section>

      <section className="grid gap-6 xl:grid-cols-[1.2fr_1fr_1fr]">
        <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <h2 className="text-lg font-semibold text-zinc-900">Antrean & Order Terbaru</h2>
              <p className="mt-1 text-sm text-zinc-500">Bantu admin lihat order yang masih bergerak dan transaksi terakhir.</p>
            </div>
            <Link to="/pos/orders" className="inline-flex items-center gap-1 text-sm font-semibold text-amber-800 hover:text-[#111111]">
              Lihat semua
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>

          <div className="space-y-3">
            {recentOrders.length > 0 ? (
              recentOrders.map((order) => (
                <div key={order.id} className="rounded-2xl border border-zinc-200 p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-semibold text-zinc-900">{order.order_number}</p>
                      <p className="mt-1 text-sm text-zinc-500">
                        {order.customer_name || 'Walk-in'} • {order.table_label || order.table?.name || 'Tanpa meja'}
                      </p>
                    </div>
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${statusTone(order.status)}`}>
                      {statusLabel(order.status)}
                    </span>
                  </div>
                  <div className="mt-3 flex items-center justify-between text-sm">
                    <p className="text-zinc-500">
                      {order.items_count} item • {formatTime(order.created_at)}
                    </p>
                    <p className="font-semibold text-zinc-900">{formatCurrency(order.total_amount)}</p>
                  </div>
                </div>
              ))
            ) : (
              <EmptyHint text="Belum ada order yang bisa ditampilkan." />
            )}
          </div>
        </div>

        <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-start justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-zinc-900">Produk Paling Laku</h2>
              <p className="mt-1 text-sm text-zinc-500">Peringkat penjualan produk bulan berjalan.</p>
            </div>
            <TrendingUp className="h-5 w-5 text-zinc-400" />
          </div>

          <div className="space-y-4">
            {dashboard.topProducts.length > 0 ? (
              dashboard.topProducts.map((product, index) => {
                const strongest = dashboard.topProducts[0]?.total_qty || 1;
                const width = Math.max((product.total_qty / strongest) * 100, 10);

                return (
                  <div key={product.product_id || `${product.product_name}-${index}`} className="space-y-2">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="font-medium text-zinc-900">{product.product_name}</p>
                        <p className="text-xs text-zinc-500">{product.order_count} order • avg {formatCurrency(product.avg_price)}</p>
                      </div>
                      <p className="text-sm font-semibold text-zinc-800">{product.total_qty}x</p>
                    </div>
                    <div className="h-2 rounded-full bg-zinc-100">
                      <div className="h-2 rounded-full bg-amber-400" style={{ width: `${width}%` }} />
                    </div>
                    <p className="text-xs text-zinc-500">{formatCurrency(product.total_revenue)} pendapatan</p>
                  </div>
                );
              })
            ) : (
              <EmptyHint text="Belum ada produk terjual pada periode bulan ini." />
            )}
          </div>
        </div>

        <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-start justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-zinc-900">Catatan Hari Ini</h2>
              <p className="mt-1 text-sm text-zinc-500">Tiga sinyal penting untuk dibaca cepat sebelum pindah halaman.</p>
            </div>
            <Receipt className="h-5 w-5 text-zinc-400" />
          </div>

          <div className="space-y-3">
            <InsightCard
              title="Pendapatan hari ini"
              text={`Masuk ${formatCurrency(dashboard.todaySummary.total_revenue)} dari ${dashboard.todaySummary.total_orders} order selesai.`}
            />
            <InsightCard
              title="Produk aktif"
              text={`${dashboard.activeProductsCount} dari ${dashboard.productsCount} produk sedang tersedia untuk dipesan.`}
            />
            <InsightCard
              title="Antrean berjalan"
              text={
                activeOrders.length > 0
                  ? `${activeOrders.length} order masih aktif. Fokuskan tim ke order dengan status selain selesai.`
                  : 'Tidak ada order aktif saat ini. Outlet sedang relatif longgar.'
              }
            />
          </div>
        </div>
      </section>
    </div>
  );
}

function MetricCard({
  title,
  value,
  detail,
  icon,
  tone,
}: {
  title: string;
  value: string;
  detail: string;
  icon: ReactNode;
  tone: 'amber' | 'zinc' | 'gold' | 'rose';
}) {
  const tones = {
    amber: 'bg-amber-100 text-amber-900',
    zinc: 'bg-zinc-100 text-zinc-800',
    gold: 'bg-yellow-100 text-yellow-900',
    rose: 'bg-rose-50 text-rose-700',
  };

  return (
    <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
      <div className={`inline-flex rounded-2xl p-3 ${tones[tone]}`}>{icon}</div>
      <p className="mt-4 text-sm text-zinc-500">{title}</p>
      <p className="mt-2 text-2xl font-semibold text-zinc-900">{value}</p>
      <p className="mt-2 text-sm text-zinc-500">{detail}</p>
    </div>
  );
}

function QuickActionCard({
  to,
  title,
  description,
  icon,
}: {
  to: string;
  title: string;
  description: string;
  icon: ReactNode;
}) {
  return (
    <Link
      to={to}
      className="group rounded-2xl border border-white/10 bg-white/5 p-4 transition hover:border-white/20 hover:bg-white/10"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="rounded-xl bg-white/10 p-2 text-amber-200">{icon}</div>
        <ArrowRight className="h-4 w-4 text-zinc-500 transition group-hover:text-white" />
      </div>
      <p className="mt-4 font-semibold text-white">{title}</p>
      <p className="mt-1 text-sm text-zinc-300">{description}</p>
    </Link>
  );
}

function InsightCard({ title, text }: { title: string; text: string }) {
  return (
    <div className="rounded-2xl bg-zinc-50 p-4">
      <p className="text-sm font-semibold text-zinc-900">{title}</p>
      <p className="mt-1 text-sm leading-6 text-zinc-600">{text}</p>
    </div>
  );
}

function EmptyHint({ text }: { text: string }) {
  return (
    <div className="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-500">
      {text}
    </div>
  );
}
