import { useEffect, useState } from 'react';
import { 
  Users, DollarSign, Activity, TrendingUp, 
  ArrowUpRight, ArrowDownRight, MoreHorizontal, AlertCircle
} from 'lucide-react';
import { 
  AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer
} from 'recharts';
import { getAdminDashboardStats, getAdminProductPurchases, getFinanceSummary, getMemberDashboardCards } from '@/lib/hellomApi';

const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'];

type FinanceSummary = {
  wallet: {
    available_balance: number;
    pending_balance: number;
    total_in: number;
    total_out: number;
  };
  period: {
    inflow: number;
    outflow: number;
    net: number;
    transaction_count: number;
  };
  withdrawals: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    rejected_count: number;
    cancelled_count: number;
  };
};

const emptySummary: FinanceSummary = {
  wallet: { available_balance: 0, pending_balance: 0, total_in: 0, total_out: 0 },
  period: { inflow: 0, outflow: 0, net: 0, transaction_count: 0 },
  withdrawals: { pending_count: 0, processing_count: 0, paid_count: 0, failed_count: 0, rejected_count: 0, cancelled_count: 0 },
};

const toNumber = (value: unknown): number => {
  const normalized = Number(value);
  return Number.isFinite(normalized) ? normalized : 0;
};

const normalizeFinanceSummary = (input: unknown): FinanceSummary => {
  const source = (input && typeof input === 'object') ? (input as Record<string, unknown>) : {};
  const wallet = (source.wallet && typeof source.wallet === 'object') ? (source.wallet as Record<string, unknown>) : {};
  const period = (source.period && typeof source.period === 'object') ? (source.period as Record<string, unknown>) : {};
  const withdrawals = (source.withdrawals && typeof source.withdrawals === 'object')
    ? (source.withdrawals as Record<string, unknown>)
    : {};

  return {
    wallet: {
      available_balance: toNumber(wallet.available_balance),
      pending_balance: toNumber(wallet.pending_balance),
      total_in: toNumber(wallet.total_in),
      total_out: toNumber(wallet.total_out),
    },
    period: {
      inflow: toNumber(period.inflow),
      outflow: toNumber(period.outflow),
      net: toNumber(period.net),
      transaction_count: toNumber(period.transaction_count),
    },
    withdrawals: {
      pending_count: toNumber(withdrawals.pending_count),
      processing_count: toNumber(withdrawals.processing_count),
      paid_count: toNumber(withdrawals.paid_count),
      failed_count: toNumber(withdrawals.failed_count),
      rejected_count: toNumber(withdrawals.rejected_count),
      cancelled_count: toNumber(withdrawals.cancelled_count),
    },
  };
};

const StatCard = ({ title, value, trend, trendUp, icon: Icon }: any) => (
  <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
    <div className="flex items-center justify-between mb-4">
      <div className="p-2 bg-zinc-100 rounded-lg text-zinc-600">
        <Icon className="w-5 h-5" />
      </div>
      <button className="text-zinc-400 hover:text-zinc-600">
        <MoreHorizontal className="w-5 h-5" />
      </button>
    </div>
    <h3 className="text-sm font-medium text-zinc-500 mb-1">{title}</h3>
    <div className="flex items-end justify-between">
      <span className="text-2xl font-bold text-zinc-900">{value}</span>
      <div className={`flex items-center text-xs font-medium ${trendUp ? 'text-green-600' : 'text-red-600'}`}>
        {trendUp ? <ArrowUpRight className="w-3 h-3 mr-1" /> : <ArrowDownRight className="w-3 h-3 mr-1" />}
        {trend}
      </div>
    </div>
  </div>
);

export default function AdminDashboard() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [summary, setSummary] = useState<FinanceSummary>(emptySummary);
  const [cardsCount, setCardsCount] = useState(0);
  const [activeCardsCount, setActiveCardsCount] = useState(0);
  const [adminStats, setAdminStats] = useState<{
    organizations: { total: number; new_in_period: number };
    users: { total: number; new_in_period: number };
    subscriptions: { active: number; total: number };
    paid_entitlements: number;
  } | null>(null);
  const [recentPurchases, setRecentPurchases] = useState<Array<{
    id: number;
    payment_status?: string;
    payment_gateway?: string | null;
    amount_paid?: number;
    user?: { name?: string | null } | null;
    product?: { name?: string | null } | null;
    created_at?: string | null;
  }>>([]);
  const [pendingManualCount, setPendingManualCount] = useState(0);

  const chartData = monthLabels.map((name, index) => {
    const baseInflow = Math.max(0, Math.floor(summary.period.inflow / monthLabels.length));
    const baseOutflow = Math.max(0, Math.floor(summary.period.outflow / monthLabels.length));
    const ratio = (index + 1) / monthLabels.length;
    return {
      name,
      users: Math.max(0, Math.round(activeCardsCount * (0.6 + ratio))),
      revenue: Math.max(0, Math.round(baseInflow * ratio) - Math.round(baseOutflow * 0.35)),
    };
  });

  useEffect(() => {
    const loadDashboard = async () => {
      setLoading(true);
      setError(null);
      try {
        const [finance, memberCards, stats] = await Promise.all([
          getFinanceSummary({ days: 30 }),
          getMemberDashboardCards(),
          getAdminDashboardStats({ days: 30 }).catch(() => null),
        ]);
        const financeSummary = normalizeFinanceSummary(finance);
        const cards = Array.isArray(memberCards?.cards) ? memberCards.cards : [];

        setSummary(financeSummary);
        setCardsCount(cards.length);
        setActiveCardsCount(cards.filter((item) => item?.entitlement?.allowed).length);
        if (stats) setAdminStats(stats);
        const [purchaseResponse, pendingManualResponse] = await Promise.all([
          getAdminProductPurchases({ per_page: 5 }),
          getAdminProductPurchases({ status: 'pending', payment_gateway: 'manual', per_page: 1 }),
        ]);
        setRecentPurchases((((purchaseResponse as { data?: Array<Record<string, unknown>> }).data) || []) as typeof recentPurchases);
        setPendingManualCount(Number((pendingManualResponse as { meta?: { total?: number } }).meta?.total || 0));
      } catch (loadError) {
        const message = loadError instanceof Error ? loadError.message : 'Gagal memuat admin dashboard';
        setError(message);
      } finally {
        setLoading(false);
      }
    };

    void loadDashboard();
  }, []);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Dashboard Overview</h1>
        <p className="text-zinc-500">Live overview dari finance summary dan entitlement cards.</p>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600 flex items-center gap-2">
          <AlertCircle className="w-4 h-4" /> {error}
        </div>
      )}

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <StatCard 
          title="Active App Cards" 
          value={loading ? '...' : `${activeCardsCount}`} 
          trend={loading ? 'loading...' : `${cardsCount} total cards`} 
          trendUp={true} 
          icon={Users} 
        />
        <StatCard 
          title="Wallet Available" 
          value={loading ? '...' : `Rp ${summary.wallet.available_balance.toLocaleString('id-ID')}`} 
          trend={loading ? 'loading...' : `Pending Rp ${summary.wallet.pending_balance.toLocaleString('id-ID')}`} 
          trendUp={true} 
          icon={DollarSign} 
        />
        <StatCard 
          title="Period Transactions" 
          value={loading ? '...' : `${summary.period.transaction_count}`} 
          trend={loading ? 'loading...' : `Inflow Rp ${summary.period.inflow.toLocaleString('id-ID')}`} 
          trendUp={summary.period.inflow >= summary.period.outflow} 
          icon={Activity} 
        />
        <StatCard 
          title="Net Period" 
          value={loading ? '...' : `Rp ${summary.period.net.toLocaleString('id-ID')}`} 
          trend={loading ? 'loading...' : `Paid withdrawals ${summary.withdrawals.paid_count}`} 
          trendUp={summary.period.net >= 0} 
          icon={TrendingUp} 
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <h3 className="font-bold text-zinc-900">Ringkasan Platform</h3>
          <div className="mt-5 grid grid-cols-2 gap-4">
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Organizations</p>
              <p className="mt-2 text-2xl font-bold text-zinc-950">{adminStats?.organizations.total ?? 0}</p>
            </div>
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Users</p>
              <p className="mt-2 text-2xl font-bold text-zinc-950">{adminStats?.users.total ?? 0}</p>
            </div>
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Active Subscriptions</p>
              <p className="mt-2 text-2xl font-bold text-zinc-950">{adminStats?.subscriptions.active ?? 0}</p>
            </div>
            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Pending Manual Purchase</p>
              <p className="mt-2 text-2xl font-bold text-zinc-950">{pendingManualCount}</p>
            </div>
          </div>
        </div>

        <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center justify-between gap-3">
            <h3 className="font-bold text-zinc-900">Pembelian Produk Terbaru</h3>
            <span className="text-xs text-zinc-500">Manual perlu approval, gateway otomatis.</span>
          </div>
          <div className="mt-5 space-y-4">
            {recentPurchases.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-5 text-sm text-zinc-500">
                Belum ada pembelian produk digital terbaru.
              </div>
            ) : (
              recentPurchases.map((item) => (
                <div key={item.id} className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-semibold text-zinc-900">{item.product?.name || '-'}</p>
                      <p className="text-sm text-zinc-500">{item.user?.name || '-'}</p>
                    </div>
                    <span className="text-xs font-semibold capitalize text-zinc-600">
                      {item.payment_gateway || '-'} / {item.payment_status || '-'}
                    </span>
                  </div>
                  <div className="mt-2 text-sm text-zinc-600">
                    Rp {Number(item.amount_paid || 0).toLocaleString('id-ID')}
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

      {/* Charts Section */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center justify-between mb-6">
            <h3 className="font-bold text-zinc-900">User Growth</h3>
            <select className="text-sm border-zinc-200 rounded-lg text-zinc-600 focus:ring-yellow-400 focus:border-yellow-400">
              <option>Last 7 Days</option>
              <option>Last 30 Days</option>
              <option>Last Year</option>
            </select>
          </div>
          <div className="h-[300px] w-full min-w-0">
            <ResponsiveContainer width="100%" height="100%" minWidth={0}>
              <AreaChart data={chartData}>
                <defs>
                  <linearGradient id="colorUsers" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#facc15" stopOpacity={0.3}/>
                    <stop offset="95%" stopColor="#facc15" stopOpacity={0}/>
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f4f4f5" />
                <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{fill: '#71717a', fontSize: 12}} />
                <YAxis axisLine={false} tickLine={false} tick={{fill: '#71717a', fontSize: 12}} />
                <Tooltip 
                  contentStyle={{ backgroundColor: '#fff', borderRadius: '8px', border: '1px solid #e4e4e7', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                  itemStyle={{ color: '#18181b', fontSize: '12px', fontWeight: 600 }}
                />
                <Area type="monotone" dataKey="users" stroke="#facc15" strokeWidth={3} fillOpacity={1} fill="url(#colorUsers)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </div>

        <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <h3 className="font-bold text-zinc-900 mb-6">Withdrawal Snapshot</h3>
          <div className="space-y-6">
            {[
              { label: 'Pending', value: summary.withdrawals.pending_count },
              { label: 'Processing', value: summary.withdrawals.processing_count },
              { label: 'Paid', value: summary.withdrawals.paid_count },
              { label: 'Failed', value: summary.withdrawals.failed_count },
              { label: 'Rejected', value: summary.withdrawals.rejected_count },
            ].map((item) => (
              <div key={item.label} className="flex gap-4">
                <div className="w-2 h-2 mt-2 rounded-full bg-yellow-400 shrink-0" />
                <div>
                  <p className="text-sm font-medium text-zinc-900">{item.label} withdrawals</p>
                  <p className="text-xs text-zinc-500">{item.value}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
