import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ArrowRight, Building2, CreditCard, ShoppingBag, Users, type LucideIcon } from 'lucide-react';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import {
  getAdminDashboardStats,
  getAdminManualCheckouts,
  getAdminPayoutProfiles,
  getAdminProductPurchases,
  getAdminSellerFinanceSummary,
  type AdminDashboardStats,
  type AdminSellerFinanceSummary,
} from '@/lib/hellomApi';

const growthRanges = [7, 30, 90] as const;
type GrowthRange = (typeof growthRanges)[number];

const formatDay = (isoDate: string) =>
  new Date(`${isoDate}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const number = (value: number | undefined) => (value ?? 0).toLocaleString('id-ID');

type RecentPurchase = {
  id: number;
  payment_status?: string;
  payment_gateway?: string | null;
  amount_paid?: number;
  user?: { name?: string | null } | null;
  product?: { name?: string | null } | null;
};

const PURCHASE_STATUS: Record<string, string> = { paid: 'Lunas', pending: 'Menunggu', failed: 'Gagal', refunded: 'Direfund' };

function StatCard({ title, value, hint, icon: Icon }: { title: string; value: string; hint: string; icon: LucideIcon }) {
  return (
    <div className="admin-stat">
      <div className="admin-stat-label"><span>{title}</span><Icon className="h-4 w-4" aria-hidden="true" /></div>
      <p className="admin-stat-value">{value}</p>
      <p className="admin-stat-hint">{hint}</p>
    </div>
  );
}

/** Super admin home: platform figures and everything that waits for an admin. */
export default function AdminDashboard() {
  const [error, setError] = useState<string | null>(null);
  const [adminStats, setAdminStats] = useState<AdminDashboardStats | null>(null);
  const [growthDays, setGrowthDays] = useState<GrowthRange>(30);
  const [growthLoading, setGrowthLoading] = useState(true);
  const [sellerFinance, setSellerFinance] = useState<AdminSellerFinanceSummary | null>(null);
  const [pending, setPending] = useState({ manualCheckouts: 0, manualPurchases: 0, kyc: 0 });
  const [recentPurchases, setRecentPurchases] = useState<RecentPurchase[]>([]);
  const [loading, setLoading] = useState(true);

  const chartData = (adminStats?.growth ?? []).map((point) => ({
    name: formatDay(point.date),
    users: point.users,
    organizations: point.organizations,
  }));

  useEffect(() => {
    let cancelled = false;
    // Each block is independent: one failing endpoint must not blank the whole page.
    Promise.allSettled([
      getAdminSellerFinanceSummary(),
      getAdminManualCheckouts({ limit: 1 }),
      getAdminProductPurchases({ status: 'pending', payment_gateway: 'manual', per_page: 1 }),
      getAdminPayoutProfiles('pending'),
      getAdminProductPurchases({ per_page: 5 }),
    ]).then(([finance, manual, manualPurchases, kyc, recent]) => {
      if (cancelled) return;
      if (finance.status === 'fulfilled') setSellerFinance(finance.value);
      setPending({
        manualCheckouts: manual.status === 'fulfilled' ? Number(manual.value.total ?? manual.value.items.length) : 0,
        manualPurchases: manualPurchases.status === 'fulfilled' ? Number((manualPurchases.value as { meta?: { total?: number } }).meta?.total || 0) : 0,
        kyc: kyc.status === 'fulfilled' ? ((kyc.value as { items?: unknown[] }).items || []).length : 0,
      });
      if (recent.status === 'fulfilled') setRecentPurchases(((recent.value as { data?: RecentPurchase[] }).data) || []);
      const failed = [finance, manual, manualPurchases, kyc, recent].filter((result) => result.status === 'rejected').length;
      setError(failed > 0 ? `${failed} bagian ringkasan gagal dimuat. Coba muat ulang halaman.` : null);
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    let cancelled = false;
    setGrowthLoading(true);
    getAdminDashboardStats({ days: growthDays })
      .then((stats) => { if (!cancelled) setAdminStats(stats); })
      .catch(() => { if (!cancelled) setError('Statistik platform gagal dimuat.'); })
      .finally(() => { if (!cancelled) setGrowthLoading(false); });
    return () => { cancelled = true; };
  }, [growthDays]);

  const todo = [
    { label: 'Pembayaran langganan manual', count: pending.manualCheckouts, to: '/admin/finance' },
    { label: 'Pembelian produk manual', count: pending.manualPurchases, to: '/admin/products/purchases' },
    { label: 'Penarikan saldo penjual', count: sellerFinance?.withdrawals_open ?? 0, to: '/admin/keuangan-penjual', warn: (sellerFinance?.withdrawals_over_sla ?? 0) > 0 ? `${sellerFinance?.withdrawals_over_sla} lewat SLA` : null },
    { label: 'Refund ke pembeli', count: sellerFinance?.refunds_open ?? 0, to: '/admin/keuangan-penjual' },
    { label: 'Verifikasi KTP & rekening', count: pending.kyc, to: '/admin/finance' },
  ];
  const statValue = (value: number | undefined) => (adminStats ? number(value) : '…');

  return (
    <div className="admin-overview">
      <div className="admin-page-heading">
        <div><p className="admin-eyebrow">Platform overview</p>
        <h1 className="text-2xl font-bold text-zinc-900">Ringkasan</h1>
        <p className="text-zinc-500">Kondisi platform Hellom dan hal yang menunggu tindakan admin.</p></div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">
          <AlertCircle className="h-4 w-4" /> {error}
        </div>
      )}

      <div className="admin-stats">
        <StatCard title="Organisasi" value={statValue(adminStats?.organizations.total)} hint={`+${number(adminStats?.organizations.new_in_period)} dalam ${growthDays} hari`} icon={Building2} />
        <StatCard title="Pengguna" value={statValue(adminStats?.users.total)} hint={`+${number(adminStats?.users.new_in_period)} dalam ${growthDays} hari`} icon={Users} />
        <StatCard title="Langganan aktif" value={statValue(adminStats?.subscriptions.active)} hint={`${number(adminStats?.paid_entitlements)} akses aplikasi berbayar`} icon={CreditCard} />
        <StatCard
          title="Pendapatan Hellom Page"
          value={sellerFinance ? rupiah(sellerFinance.hellom_net) : '…'}
          hint={sellerFinance ? `Biaya layanan bersih dari ${number(sellerFinance.paid_orders)} pesanan` : 'Memuat…'}
          icon={ShoppingBag}
        />
      </div>

      <div className="admin-overview-grid">
        <div className="admin-panel admin-tasks">
          <h3 className="font-bold text-zinc-900">Perlu tindakan</h3>
          <ul className="mt-4 divide-y divide-zinc-100">
            {todo.map((item) => (
              <li key={item.label}>
                <Link to={item.to} className="admin-task-link">
                  <span className="text-zinc-700">
                    {item.label}
                    {item.warn && <span className="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">{item.warn}</span>}
                  </span>
                  <span className="flex items-center gap-2">
                    <span className={item.count > 0 ? 'admin-task-count' : 'text-xs text-zinc-400'}>
                      {loading ? '…' : item.count > 0 ? item.count : 'Tidak ada'}
                    </span>
                    <ArrowRight className="h-4 w-4 text-zinc-400" />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </div>

      <div className="admin-panel admin-growth">
        <div className="admin-panel-heading">
          <div>
            <h3 className="font-bold text-zinc-900">Pertumbuhan</h3>
            <p className="text-xs text-zinc-500">
              {growthLoading
                ? 'Memuat…'
                : `${number(adminStats?.users.new_in_period)} pengguna & ${number(adminStats?.organizations.new_in_period)} organisasi baru`}
            </p>
          </div>
          <select
            aria-label="Rentang waktu pertumbuhan"
            value={growthDays}
            onChange={(event) => setGrowthDays(Number(event.target.value) as GrowthRange)}
            className="rounded-lg border-zinc-200 text-sm text-zinc-600 focus:border-yellow-400 focus:ring-yellow-400"
          >
            {growthRanges.map((days) => (
              <option key={days} value={days}>{days} hari terakhir</option>
            ))}
          </select>
        </div>
        <div className="relative h-[240px] sm:h-[280px] w-full min-w-0">
          {!growthLoading && chartData.length === 0 && (
            <div className="absolute inset-0 z-10 flex items-center justify-center text-sm text-zinc-500">Data pertumbuhan belum tersedia.</div>
          )}
          <ResponsiveContainer width="100%" height="100%" minWidth={0}>
            <AreaChart data={chartData}>
              <defs>
                <linearGradient id="colorUsers" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#d89d1b" stopOpacity={0.3} />
                  <stop offset="95%" stopColor="#d89d1b" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f4f4f5" />
              <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#71717a', fontSize: 12 }} minTickGap={16} />
              <YAxis width={34} axisLine={false} tickLine={false} tick={{ fill: '#71717a', fontSize: 12 }} allowDecimals={false} />
              <Tooltip
                contentStyle={{ backgroundColor: '#fff', borderRadius: '8px', border: '1px solid #e4e4e7', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                itemStyle={{ color: '#18181b', fontSize: '12px', fontWeight: 600 }}
              />
              <Area type="monotone" dataKey="users" name="Pengguna baru" stroke="#d89d1b" strokeWidth={2} fillOpacity={1} fill="url(#colorUsers)" />
              <Area type="monotone" dataKey="organizations" name="Organisasi baru" stroke="#71717a" strokeWidth={2} fillOpacity={0} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
        <div className="mt-3 flex gap-4 text-xs text-zinc-600">
          <span className="flex items-center gap-1.5"><span className="h-0.5 w-4 bg-[#d89d1b]" /> Pengguna baru</span>
          <span className="flex items-center gap-1.5"><span className="h-0.5 w-4 bg-[#71717a]" /> Organisasi baru</span>
        </div>
      </div>
      </div>
        <div className="admin-panel">
          <div className="admin-panel-heading">
            <h3 className="font-bold text-zinc-900">Pembelian produk terbaru</h3>
            <Link to="/admin/products/purchases" className="inline-flex min-h-11 items-center text-xs font-semibold text-zinc-600">Semua pembelian</Link>
          </div>
          {recentPurchases.length === 0 ? <p className="rounded-lg bg-zinc-50 p-5 text-center text-sm text-zinc-500">{loading ? 'Memuat…' : 'Belum ada pembelian produk digital.'}</p> : <>
            <div className="admin-recent-desktop overflow-x-auto">
              <table className="w-full text-left"><thead><tr><th>Produk / Pembeli</th><th>Metode</th><th>Status</th><th className="text-right">Nominal</th></tr></thead>
                <tbody className="divide-y divide-zinc-100">{recentPurchases.map(item => <tr key={item.id}>
                  <td><p className="font-medium text-zinc-900">{item.product?.name || '—'}</p><p className="mt-1 text-xs text-zinc-500">{item.user?.name || '—'}</p></td>
                  <td className="text-zinc-500">{item.payment_gateway || '—'}</td>
                  <td><span className="admin-status" data-status={item.payment_status}>{PURCHASE_STATUS[item.payment_status ?? ''] ?? item.payment_status ?? '—'}</span></td>
                  <td className="whitespace-nowrap text-right font-medium tabular-nums">{rupiah(Number(item.amount_paid || 0))}</td>
                </tr>)}</tbody>
              </table>
            </div>
            <div className="admin-recent-mobile">{recentPurchases.map(item => <article key={item.id}>
              <div className="flex items-start justify-between gap-3"><p className="text-sm font-semibold">{item.product?.name || '—'}</p><span className="admin-status shrink-0" data-status={item.payment_status}>{PURCHASE_STATUS[item.payment_status ?? ''] ?? item.payment_status ?? '—'}</span></div>
              <p className="mt-1 text-xs text-zinc-500">{item.user?.name || '—'}</p>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs"><span className="text-zinc-500">{item.payment_gateway || '—'}</span><span className="font-semibold tabular-nums">{rupiah(Number(item.amount_paid || 0))}</span></div>
            </article>)}</div>
          </>}
        </div>
    </div>
  );
}
