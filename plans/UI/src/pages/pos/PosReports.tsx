import { useState, useEffect } from 'react';
import {
  LineChart, Line, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip,
  ResponsiveContainer, PieChart, Pie, Cell, Legend
} from 'recharts';
import { Download, Calendar, TrendingUp, TrendingDown } from 'lucide-react';
import { getPosReportSummary, getPosReportProducts, getPosReportDaily, exportPosReport } from '@/lib/hellomApi';

// Format harga Indonesia
const formatRp = (amount: number) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(amount);

// Format tanggal pendek
const formatDateShort = (dateStr: string) =>
  new Date(dateStr).toLocaleDateString('id-ID', {
    day: 'numeric', month: 'short'
  });

// Warna chart
const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'];

const PosReports = () => {
  // State filter
  const [preset, setPreset] = useState<'today'|'7days'|'30days'|'month'|'custom'>('today');
  const [startDate, setStartDate] = useState(new Date().toISOString().split('T')[0]);
  const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);
  const [isLoading, setIsLoading] = useState(false);
  const [isExporting, setIsExporting] = useState(false);

  // State data
  const [summary, setSummary] = useState(null);
  const [dailyData, setDailyData] = useState([]);
  const [topProducts, setTopProducts] = useState([]);
  const [topCategories, setTopCategories] = useState([]);
  const [peakHours, setPeakHours] = useState([]);
  const [chartMode, setChartMode] = useState<'revenue'|'orders'>('revenue');

  // Set preset tanggal
  const handlePreset = (p: string) => {
    const today = new Date();
    const fmt = (d: Date) => d.toISOString().split('T')[0];

    setPreset(p as any);
    switch(p) {
      case 'today':
        setStartDate(fmt(today));
        setEndDate(fmt(today));
        break;
      case '7days':
        const d7 = new Date(today);
        d7.setDate(d7.getDate() - 6);
        setStartDate(fmt(d7));
        setEndDate(fmt(today));
        break;
      case '30days':
        const d30 = new Date(today);
        d30.setDate(d30.getDate() - 29);
        setStartDate(fmt(d30));
        setEndDate(fmt(today));
        break;
      case 'month':
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        setStartDate(fmt(firstDay));
        setEndDate(fmt(today));
        break;
    }
  };

  // Load semua data
  const loadReports = async () => {
    setIsLoading(true);
    try {
      const params = `start_date=${startDate}&end_date=${endDate}`;

      const [summaryRes, dailyRes, productsRes] = await Promise.all([
        getPosReportSummary({ start_date: startDate, end_date: endDate }),
        getPosReportDaily({ start_date: startDate, end_date: endDate }),
        getPosReportProducts({ start_date: startDate, end_date: endDate }),
      ]);

      setSummary(summaryRes.summary);
      setDailyData(dailyRes.daily);
      setTopProducts(productsRes.top_products);
      setTopCategories(productsRes.top_categories);
      setPeakHours(summaryRes.peak_hours);

    } catch (err) {
      console.error('Gagal load laporan:', err);
    } finally {
      setIsLoading(false);
    }
  };

  // Export Excel
  const handleExport = async () => {
    setIsExporting(true);
    try {
      const response = await exportPosReport({ start_date: startDate, end_date: endDate });

      const blob = await response.blob();
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
  }, []);

  // Kartu ringkasan
  const SummaryCard = ({ icon, label, value, change, color }) => (
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
            Rekap pendapatan dan analisis penjualan
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

      {/* FILTER BAR */}
      <div className="bg-white rounded-2xl p-4 shadow-sm
        border border-gray-100 mb-6">
        <div className="flex flex-wrap gap-2 mb-4">
          {[
            { key: 'today', label: 'Hari Ini' },
            { key: '7days', label: '7 Hari' },
            { key: '30days', label: '30 Hari' },
            { key: 'month', label: 'Bulan Ini' },
            { key: 'custom', label: 'Custom' },
          ].map(p => (
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
          <button
            onClick={loadReports}
            disabled={isLoading}
            className="px-5 py-2 bg-[#111111] hover:bg-[#2a241d]
              text-white rounded-xl text-sm font-semibold transition disabled:opacity-50"
          >
            🔍 Tampilkan
          </button>
        </div>
      </div>

      {isLoading ? (
        <div className="text-center py-20 text-gray-400">
          Memuat laporan...
        </div>
      ) : (
        <>
          {/* KARTU RINGKASAN */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <SummaryCard
              icon="💰"
              label="Total Pendapatan"
              value={formatRp(summary?.total_revenue || 0)}
              change={summary?.revenue_change}
              color="bg-amber-100"
            />
            <SummaryCard
              icon="📋"
              label="Completed Orders"
              value={`${summary?.total_orders || 0} pesanan`}
              change={summary?.orders_change}
              color="bg-green-50"
            />
            <SummaryCard
              icon="🧾"
              label="Average Per Order"
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
                  Orders
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
                  formatter={(value: any) => [
                    chartMode === 'revenue'
                      ? formatRp(value) : `${value} pesanan`,
                    chartMode === 'revenue' ? 'Revenue' : 'Orders'
                  ]}
                  labelFormatter={formatDateShort}
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
        </>
      )}
    </div>
  );
};

export default PosReports;
