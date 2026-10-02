import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Wallet, CheckCircle, XCircle, Clock,
  Search, AlertCircle, RefreshCw, ReceiptText
} from 'lucide-react';
import { cn } from '@/lib/utils';
import PayoutKycReview from './PayoutKycReview';
import {
  approveAdminManualCheckout,
  approveWithdrawal,
  getAdminManualCheckouts,
  getAdminPayoutQueue,
  getPlatformFinanceSummary,
  createPlatformPayout,
  markWithdrawalFailed,
  markWithdrawalPaid,
  rejectAdminManualCheckout,
  rejectWithdrawal,
} from '@/lib/hellomApi';

interface WithdrawalRequest {
  id: number;
  external_ref?: string;
  account_name?: string;
  account_number_masked?: string;
  bank_code?: string;
  amount: number;
  created_at: string;
  status: 'pending' | 'processing' | 'paid' | 'failed' | 'rejected' | 'cancelled';
  actions?: {
    can_approve: boolean;
    can_reject: boolean;
    can_mark_paid: boolean;
    can_mark_failed: boolean;
  };
}

interface ManualCheckoutRequest {
  id: number;
  intent_token: string;
  status: string;
  amount: number;
  currency: string;
  created_at: string;
  organization?: {
    id: number;
    name: string;
  };
  user?: {
    id: number;
    name: string;
    email: string;
  };
  app?: {
    slug: string;
    name: string;
  };
  plan?: {
    slug: string;
    name: string;
  };
}

type QueueStatus = 'pending' | 'processing' | 'failed';

interface PlatformFinanceSummary {
  range: { days: number; start_at: string; end_at: string };
  xendit_balance?: { available_balance: number; pending_balance: number; currency: string; captured_at: string | null };
  platform_revenue?: {
    total_revenue: number;
    revenue_count: number;
    by_category: Record<string, number>;
    withdrawable_revenue: number;
    pending_payouts: number;
  };
  user_deposits?: { total_deposits: number; active_users_count: number };
  organization_wallets?: {
    total_available: number;
    total_pending: number;
    total_inflow: number;
    total_outflow: number;
  };
  platform_payouts?: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    total_paid_amount: number;
    total_pending_amount: number;
  };
  user_withdrawals?: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    total_pending_amount: number;
  };
}

export default function FinanceManagement() {
  const [statusFilter, setStatusFilter] = useState<QueueStatus>('pending');
  const [withdrawals, setWithdrawals] = useState<WithdrawalRequest[]>([]);
  const [manualCheckouts, setManualCheckouts] = useState<ManualCheckoutRequest[]>([]);
  const [manualTotal, setManualTotal] = useState(0);
  const [searchTerm, setSearchTerm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadingManual, setLoadingManual] = useState(false);
  const [actingManualId, setActingManualId] = useState<number | null>(null);
  const [summary, setSummary] = useState({ pending_count: 0, processing_count: 0, failed_count: 0, paid_count: 0 });
  const [platformSummary, setPlatformSummary] = useState<PlatformFinanceSummary | null>(null);
  const [showPayoutModal, setShowPayoutModal] = useState(false);
  const [payoutForm, setPayoutForm] = useState({
    amount: '',
    bank_code: '',
    account_number: '',
    account_holder_name: '',
  });

  const loadQueue = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await getAdminPayoutQueue({ status: statusFilter, limit: 100 });
      const normalized = (result.items || [])
        .map((item) => ({
          id: Number(item.id),
          external_ref: typeof item.external_ref === 'string' ? item.external_ref : undefined,
          account_name: typeof item.account_name === 'string' ? item.account_name : undefined,
          account_number_masked: typeof item.account_number_masked === 'string' ? item.account_number_masked : undefined,
          bank_code: typeof item.bank_code === 'string' ? item.bank_code : undefined,
          amount: Number(item.amount || 0),
          created_at: String(item.created_at || '-'),
          status: (String(item.status || 'pending') as WithdrawalRequest['status']),
          actions: item.actions as WithdrawalRequest['actions'],
        }))
        .filter((item) => Number.isFinite(item.id));
      setWithdrawals(normalized);
      setSummary(result.summary || { pending_count: 0, processing_count: 0, failed_count: 0, paid_count: 0 });
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat payout queue';
      setError(message);
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  const loadManualCheckouts = async () => {
    setLoadingManual(true);
    setError(null);
    try {
      const result = await getAdminManualCheckouts({ limit: 100 });
      setManualCheckouts(result.items || []);
      setManualTotal(result.total ?? (result.items || []).length);
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat checkout manual';
      setError(message);
    } finally {
      setLoadingManual(false);
    }
  };

  const loadPlatformSummary = async () => {
    try {
      const result = await getPlatformFinanceSummary({ days: 30 });
      setPlatformSummary(result);
    } catch (loadError) {
      console.error('Failed to load platform summary:', loadError);
      // Optional block: the page works without the platform summary.
    }
  };

  useEffect(() => {
    void loadQueue();
  }, [loadQueue]);

  useEffect(() => {
    void Promise.all([loadManualCheckouts(), loadPlatformSummary()]);
  }, []);

  const reloadAll = async () => {
    await Promise.all([loadQueue(), loadManualCheckouts(), loadPlatformSummary()]);
  };

  const runAction = async (action: () => Promise<unknown>) => {
    try {
      setError(null);
      await action();
      await loadQueue();
    } catch (actionError) {
      const message = actionError instanceof Error ? actionError.message : 'Aksi gagal';
      setError(message);
    }
  };

  const runManualAction = async (intentId: number, action: () => Promise<unknown>) => {
    setActingManualId(intentId);
    try {
      await action();
      await loadManualCheckouts();
    } catch (error) {
      console.error('Manual action failed:', error);
    } finally {
      setActingManualId(null);
    }
  };

  const formatCurrency = (amount: number | undefined | null) => `Rp ${Number(amount || 0).toLocaleString('id-ID')}`;

  const filteredManualCheckouts = useMemo(() => {
    const query = searchTerm.trim().toLowerCase();
    if (!query) return manualCheckouts;

    return manualCheckouts.filter((item) => {
      const haystack = [
        item.id,
        item.intent_token,
        item.organization?.name,
        item.organization?.id,
        item.user?.name,
        item.user?.email,
        item.app?.name,
        item.plan?.name,
      ].join(' ').toLowerCase();

      return haystack.includes(query);
    });
  }, [manualCheckouts, searchTerm]);

  const filteredWithdrawals = useMemo(() => {
    const query = searchTerm.trim().toLowerCase();
    if (!query) return withdrawals;

    return withdrawals.filter((item) => {
      const haystack = [
        item.id,
        item.external_ref,
        item.account_name,
        item.account_number_masked,
        item.bank_code,
        item.status,
      ].join(' ').toLowerCase();

      return haystack.includes(query);
    });
  }, [withdrawals, searchTerm]);

  const handleCreatePayout = async () => {
    try {
      const amount = parseInt(payoutForm.amount);
      if (isNaN(amount) || amount < 10000) {
        alert('Minimum payout amount is 10,000 IDR');
        return;
      }

      await createPlatformPayout({
        amount,
        bank_code: payoutForm.bank_code,
        account_number: payoutForm.account_number,
        account_holder_name: payoutForm.account_holder_name,
      });

      alert('Platform payout created successfully!');
      setShowPayoutModal(false);
      setPayoutForm({ amount: '', bank_code: '', account_number: '', account_holder_name: '' });
      await loadPlatformSummary();
    } catch (error) {
      console.error('Failed to create payout:', error);
      alert('Failed to create payout. Please try again.');
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Keuangan Platform</h1>
          <p className="text-zinc-500">Pembayaran langganan manual, verifikasi KTP penjual, dan pendapatan platform.</p>
        </div>
        <div className="flex gap-2 flex-wrap">
          <button
            onClick={() => void reloadAll()}
            className="px-3 py-2 rounded-lg text-sm font-medium border border-zinc-200 bg-white hover:bg-zinc-50"
          >
            <RefreshCw className={`w-4 h-4 ${loading || loadingManual ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      <PayoutKycReview />

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">{error}</div>
      )}

      <div className="bg-white p-4 rounded-xl border border-zinc-200 shadow-sm flex items-center gap-4">
        <Search className="w-4 h-4 text-zinc-400" />
        <input
          type="text"
          placeholder="Cari ID, nama rekening, organisasi, aplikasi, atau email"
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="flex-1 bg-transparent text-sm text-zinc-900 placeholder:text-zinc-400 outline-none"
        />
      </div>

      <div className="bg-white rounded-xl border border-zinc-200 shadow-sm overflow-hidden">
        <div className="px-6 py-4 border-b border-zinc-200 flex items-center justify-between">
          <div>
            <h2 className="font-bold text-zinc-900">Pembayaran langganan manual</h2>
            <p className="text-sm text-zinc-500">Transfer bank/QRIS manual yang menunggu dicek dan disetujui.</p>
          </div>
          <div className="text-sm font-semibold text-zinc-600">{filteredManualCheckouts.length} antrean{manualTotal > manualCheckouts.length ? ` (100 terbaru dari ${manualTotal})` : ''}</div>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-zinc-50 border-b border-zinc-200">
              <tr>
                <th className="px-6 py-4 font-medium text-zinc-500">Intent</th>
                <th className="px-6 py-4 font-medium text-zinc-500">Organisasi</th>
                <th className="px-6 py-4 font-medium text-zinc-500">Pengguna</th>
                <th className="px-6 py-4 font-medium text-zinc-500">Aplikasi / Paket</th>
                <th className="px-6 py-4 font-medium text-zinc-500">Nominal</th>
                <th className="px-6 py-4 font-medium text-zinc-500">Tanggal</th>
                <th className="px-6 py-4 font-medium text-zinc-500 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {filteredManualCheckouts.map((item) => (
                <tr key={item.id} className="hover:bg-zinc-50">
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="font-mono text-xs text-zinc-500">#{item.id}</span>
                      <span className="text-xs text-zinc-400">{item.intent_token}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="font-medium text-zinc-900">{item.organization?.name || '-'}</span>
                      <span className="text-xs text-zinc-500">Organisasi #{item.organization?.id || '-'}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="font-medium text-zinc-900">{item.user?.name || '-'}</span>
                      <span className="text-xs text-zinc-500">{item.user?.email || '-'}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex flex-col">
                      <span className="font-medium text-zinc-900">{item.app?.name || '-'}</span>
                      <span className="text-xs text-zinc-500">{item.plan?.name || '-'}</span>
                    </div>
                  </td>
                  <td className="px-6 py-4 font-bold text-zinc-900">
                    {item.currency} {Number(item.amount || 0).toLocaleString('id-ID')}
                  </td>
                  <td className="px-6 py-4 text-zinc-500 text-xs">{item.created_at}</td>
                  <td className="px-6 py-4 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => { if (window.confirm(`Setujui pembayaran ${item.organization?.name || ''}? Akses aplikasi langsung aktif. Pastikan dana sudah masuk.`)) void runManualAction(item.id, () => approveAdminManualCheckout(item.id)); }}
                        disabled={actingManualId === item.id}
                        className="px-3 py-2 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-70"
                      >
                        {actingManualId === item.id ? 'Memproses…' : 'Setujui'}
                      </button>
                      <button
                        onClick={() => { if (window.confirm('Tolak pembayaran ini? Pembeli akan diberi tahu.')) void runManualAction(item.id, () => rejectAdminManualCheckout(item.id)); }}
                        disabled={actingManualId === item.id}
                        className="px-3 py-2 text-xs font-semibold rounded-lg bg-white border border-red-200 text-red-600 hover:bg-red-50 disabled:opacity-70"
                      >
                        Tolak
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {filteredManualCheckouts.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-zinc-500">
                    {loadingManual ? 'Memuat antrean checkout manual...' : 'Tidak ada checkout manual yang cocok dengan filter saat ini.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Platform Finance Summary */}
      {platformSummary && (
        <div className="mt-8 space-y-6">
          <div className="rounded-3xl border border-zinc-200 bg-[linear-gradient(135deg,rgba(255,248,230,0.9),rgba(255,255,255,1),rgba(237,247,255,0.85))] p-5 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div className="space-y-2">
                <div className="inline-flex w-fit items-center gap-2 rounded-full border border-zinc-200 bg-white/90 px-3 py-1 text-xs font-semibold text-zinc-700">
                  <ReceiptText className="h-3.5 w-3.5" />
                  {platformSummary.range.days} hari terakhir
                </div>
                <div>
                  <h2 className="text-lg font-semibold text-zinc-950">Ringkasan pendapatan platform</h2>
                  <p className="text-sm text-zinc-600">
                    Saldo Xendit, pendapatan dari checkout yang terkonfirmasi, dan pergerakan dompet organisasi.
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-3">
                <div className="rounded-2xl border border-white/80 bg-white/90 px-4 py-3 text-sm shadow-sm">
                  <p className="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Sinkron Xendit terakhir</p>
                  <p className="mt-1 font-semibold text-zinc-900">
                    {platformSummary.xendit_balance?.captured_at
                      ? new Date(platformSummary.xendit_balance.captured_at).toLocaleString('id-ID')
                      : 'Belum ada snapshot'}
                  </p>
                </div>
                <button
                  onClick={() => setShowPayoutModal(true)}
                  className="rounded-2xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700"
                >
                  Tarik pendapatan
                </button>
              </div>
            </div>
          </div>

          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="rounded-2xl border border-sky-200 bg-sky-50/90 p-5 shadow-sm">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">Saldo Xendit</p>
              <p className="mt-3 text-2xl font-bold text-sky-950">
                {formatCurrency(platformSummary.xendit_balance?.available_balance)}
              </p>
              <p className="mt-2 text-xs text-sky-800/80">
                Pending {formatCurrency(platformSummary.xendit_balance?.pending_balance)}
              </p>
            </div>
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50/90 p-5 shadow-sm">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Pendapatan platform</p>
              <p className="mt-3 text-2xl font-bold text-emerald-950">
                {formatCurrency(platformSummary.platform_revenue?.total_revenue)}
              </p>
              <p className="mt-2 text-xs text-emerald-800/80">
                {platformSummary.platform_revenue?.revenue_count || 0} transaksi revenue tercatat
              </p>
            </div>
            <div className="rounded-2xl border border-violet-200 bg-violet-50/90 p-5 shadow-sm">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-violet-700">Saldo dompet organisasi</p>
              <p className="mt-3 text-2xl font-bold text-violet-950">
                {formatCurrency(platformSummary.organization_wallets?.total_available)}
              </p>
              <p className="mt-2 text-xs text-violet-800/80">
                Menunggu settle {formatCurrency(platformSummary.organization_wallets?.total_pending)}
              </p>
            </div>
            <div className="rounded-2xl border border-amber-200 bg-amber-50/90 p-5 shadow-sm">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Pendapatan bisa ditarik</p>
              <p className="mt-3 text-2xl font-bold text-amber-950">
                {formatCurrency(platformSummary.platform_revenue?.withdrawable_revenue)}
              </p>
              <p className="mt-2 text-xs text-amber-800/80">
                Payout tertunda {formatCurrency(platformSummary.platform_revenue?.pending_payouts)}
              </p>
            </div>
          </div>

          <div className="grid gap-4 xl:grid-cols-3">
            <div className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
              <h3 className="mb-3 text-sm font-semibold text-zinc-900">Payout platform</h3>
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-zinc-600">Menunggu:</span>
                  <span className="font-medium text-zinc-900">{platformSummary.platform_payouts?.pending_count || 0}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-zinc-600">Diproses:</span>
                  <span className="font-medium text-zinc-900">{platformSummary.platform_payouts?.processing_count || 0}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-zinc-600">Dibayar:</span>
                  <span className="font-medium text-zinc-900">{formatCurrency(platformSummary.platform_payouts?.total_paid_amount)}</span>
                </div>
              </div>
            </div>

            <div className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
              <h3 className="mb-3 text-sm font-semibold text-zinc-900">Penarikan pengguna</h3>
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-zinc-600">Menunggu:</span>
                  <span className="font-medium text-zinc-900">{platformSummary.user_withdrawals?.pending_count || 0}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-zinc-600">Diproses:</span>
                  <span className="font-medium text-zinc-900">{platformSummary.user_withdrawals?.processing_count || 0}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-zinc-600">Nominal menunggu:</span>
                  <span className="font-medium text-zinc-900">{formatCurrency(platformSummary.user_withdrawals?.total_pending_amount)}</span>
                </div>
              </div>
            </div>

            <div className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
              <h3 className="mb-3 text-sm font-semibold text-zinc-900">Aktivitas dompet</h3>
              <div className="space-y-2 text-sm">
                <div className="flex justify-between gap-3">
                  <span className="text-zinc-600">Dana masuk dompet organisasi:</span>
                  <span className="font-medium text-zinc-900">{formatCurrency(platformSummary.organization_wallets?.total_inflow)}</span>
                </div>
                <div className="flex justify-between gap-3">
                  <span className="text-zinc-600">Dana keluar dompet organisasi:</span>
                  <span className="font-medium text-zinc-900">{formatCurrency(platformSummary.organization_wallets?.total_outflow)}</span>
                </div>
                <div className="flex justify-between gap-3">
                  <span className="text-zinc-600">Total deposit pengguna:</span>
                  <span className="font-medium text-zinc-900">{formatCurrency(platformSummary.user_deposits?.total_deposits)}</span>
                </div>
                <div className="flex justify-between gap-3">
                  <span className="text-zinc-600">Pengguna dengan deposit:</span>
                  <span className="font-medium text-zinc-900">{platformSummary.user_deposits?.active_users_count || 0}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      <details className="rounded-xl border border-zinc-200 bg-white shadow-sm" open={summary.pending_count + summary.processing_count > 0}>
        <summary className="cursor-pointer select-none px-6 py-4">
          <span className="font-bold text-zinc-900">Penarikan saldo dompet (lama)</span>
          <span className="ml-2 text-sm text-zinc-500">Penarikan saldo top-up sudah ditutup; sisa permintaan lama diselesaikan di sini. Penarikan hasil penjualan ada di Keuangan Penjual.</span>
        </summary>
        <div className="space-y-4 border-t border-zinc-200 p-4">
          <div className="flex flex-wrap gap-2">
              {(['pending', 'processing', 'failed'] as QueueStatus[]).map((status) => (
                <button
                  key={status}
                  onClick={() => setStatusFilter(status)}
                  className={cn(
                    'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                    statusFilter === status ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-600 border border-zinc-200 hover:bg-zinc-50'
                  )}
                >
                  {({ pending: 'Menunggu', processing: 'Diproses', failed: 'Gagal' } as Record<string, string>)[status] ?? status}
                </button>
              ))}
          </div>
          <div className="grid sm:grid-cols-4 gap-4">
            <div className="rounded-2xl border border-amber-200 bg-amber-50/80 p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-700">Menunggu</p>
              <p className="mt-2 text-2xl font-bold text-amber-950">{summary.pending_count}</p>
              <p className="mt-1 text-xs text-amber-800/80">Menunggu review payout</p>
            </div>
            <div className="rounded-2xl border border-sky-200 bg-sky-50/80 p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-sky-700">Diproses</p>
              <p className="mt-2 text-2xl font-bold text-sky-950">{summary.processing_count}</p>
              <p className="mt-1 text-xs text-sky-800/80">Sedang diproses gateway</p>
            </div>
            <div className="rounded-2xl border border-rose-200 bg-rose-50/80 p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-rose-700">Gagal</p>
              <p className="mt-2 text-2xl font-bold text-rose-950">{summary.failed_count}</p>
              <p className="mt-1 text-xs text-rose-800/80">Perlu investigasi owner</p>
            </div>
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Dibayar</p>
              <p className="mt-2 text-2xl font-bold text-emerald-950">{summary.paid_count}</p>
              <p className="mt-1 text-xs text-emerald-800/80">Sudah selesai dibayar</p>
            </div>
          </div>

          <div className="bg-white rounded-xl border border-zinc-200 shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-zinc-200 flex items-center justify-between">
              <div>
                <h2 className="font-bold text-zinc-900">Antrean penarikan</h2>
                <p className="text-sm text-zinc-500">Setujui, tandai sudah dibayar, atau tandai gagal.</p>
              </div>
              <div className="inline-flex items-center gap-2 rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700">
                <ReceiptText className="w-3.5 h-3.5" />
                {statusFilter}
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-zinc-50 border-b border-zinc-200">
                  <tr>
                    <th className="px-6 py-4 font-medium text-zinc-500">ID</th>
                    <th className="px-6 py-4 font-medium text-zinc-500">Nama rekening</th>
                    <th className="px-6 py-4 font-medium text-zinc-500">Nominal</th>
                    <th className="px-6 py-4 font-medium text-zinc-500">Rekening</th>
                    <th className="px-6 py-4 font-medium text-zinc-500">Tanggal</th>
                    <th className="px-6 py-4 font-medium text-zinc-500">Status</th>
                    <th className="px-6 py-4 font-medium text-zinc-500 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100">
                  {filteredWithdrawals.map((req) => (
                    <tr key={req.id} className="hover:bg-zinc-50">
                      <td className="px-6 py-4 font-mono text-xs text-zinc-500">{req.id}</td>
                      <td className="px-6 py-4 font-medium text-zinc-900">{req.account_name || '-'}</td>
                      <td className="px-6 py-4 font-bold text-zinc-900">Rp {req.amount.toLocaleString('id-ID')}</td>
                      <td className="px-6 py-4">
                        <div className="flex flex-col text-xs">
                          <span className="font-bold text-zinc-700">{req.bank_code || '-'}</span>
                          <span className="font-mono text-zinc-500">{req.account_number_masked || '-'}</span>
                          <span className="text-zinc-500">{req.external_ref || '-'}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-zinc-500 text-xs">{req.created_at}</td>
                      <td className="px-6 py-4">
                        <span className={cn(
                          'px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize flex items-center gap-1.5 w-fit',
                          req.status === 'pending' ? 'bg-yellow-50 text-yellow-700 border-yellow-200' :
                          req.status === 'paid' ? 'bg-green-50 text-green-700 border-green-200' :
                          req.status === 'processing' ? 'bg-blue-50 text-blue-700 border-blue-200' :
                          'bg-red-50 text-red-700 border-red-200'
                        )}>
                          {req.status === 'pending' && <Clock className="w-3 h-3" />}
                          {req.status === 'paid' && <CheckCircle className="w-3 h-3" />}
                          {(req.status === 'rejected' || req.status === 'failed') && <XCircle className="w-3 h-3" />}
                          {req.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          {req.actions?.can_approve && (
                            <button
                              onClick={() => void runAction(() => approveWithdrawal(req.id))}
                              className="p-2 text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                              title="Setujui"
                            >
                              <CheckCircle className="w-5 h-5" />
                            </button>
                          )}
                          {req.actions?.can_mark_paid && (
                            <button
                              onClick={() => void runAction(() => markWithdrawalPaid(req.id, `manual_${Date.now()}`, 'Marked from admin UI'))}
                              className="p-2 text-emerald-700 hover:bg-emerald-50 rounded-lg transition-colors"
                              title="Tandai sudah dibayar"
                            >
                              <Wallet className="w-5 h-5" />
                            </button>
                          )}
                          {req.actions?.can_mark_failed && (
                            <button
                              onClick={() => void runAction(() => markWithdrawalFailed(req.id, 'Marked failed from admin UI'))}
                              className="p-2 text-orange-600 hover:bg-orange-50 rounded-lg transition-colors"
                              title="Tandai gagal"
                            >
                              <AlertCircle className="w-5 h-5" />
                            </button>
                          )}
                          {req.actions?.can_reject && (
                            <button
                              onClick={() => void runAction(() => rejectWithdrawal(req.id, 'Rejected from admin UI'))}
                              className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                              title="Tolak"
                            >
                              <XCircle className="w-5 h-5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                  {filteredWithdrawals.length === 0 && (
                    <tr>
                      <td colSpan={7} className="px-6 py-12 text-center text-zinc-500">
                        Tidak ada penarikan untuk filter ini.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </details>

      {/* Platform Payout Modal */}
      {showPayoutModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-3xl border border-white/70 bg-white p-6 shadow-2xl">
            <h3 className="mb-2 text-lg font-semibold text-zinc-950">Tarik pendapatan platform</h3>
            <p className="mb-5 text-sm text-zinc-600">
              Tarik revenue platform ke rekening owner. Pastikan nominal tidak melebihi saldo revenue yang bisa dicairkan.
            </p>

            <div className="space-y-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-zinc-800">
                  Nominal (Rp)
                </label>
                <input
                  type="number"
                  value={payoutForm.amount}
                  onChange={(e) => setPayoutForm({...payoutForm, amount: e.target.value})}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-zinc-900 placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                  placeholder="10000"
                  min="10000"
                />
                <p className="mt-1 text-xs text-zinc-500">
                  Bisa ditarik: {formatCurrency(platformSummary?.platform_revenue?.withdrawable_revenue)}
                </p>
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-zinc-800">
                  Kode bank
                </label>
                <input
                  type="text"
                  value={payoutForm.bank_code}
                  onChange={(e) => setPayoutForm({...payoutForm, bank_code: e.target.value.toUpperCase()})}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-zinc-900 placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                  placeholder="BCA"
                  maxLength={3}
                />
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-zinc-800">
                  Account Number
                </label>
                <input
                  type="text"
                  value={payoutForm.account_number}
                  onChange={(e) => setPayoutForm({...payoutForm, account_number: e.target.value})}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-zinc-900 placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                  placeholder="1234567890"
                />
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-zinc-800">
                  Account Holder Name
                </label>
                <input
                  type="text"
                  value={payoutForm.account_holder_name}
                  onChange={(e) => setPayoutForm({...payoutForm, account_holder_name: e.target.value})}
                  className="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-zinc-900 placeholder:text-zinc-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                  placeholder="John Doe"
                />
              </div>
            </div>

            <div className="mt-6 flex gap-3">
              <button
                onClick={() => setShowPayoutModal(false)}
                className="flex-1 rounded-xl border border-zinc-300 px-4 py-2.5 font-medium text-zinc-700 transition-colors hover:bg-zinc-50"
              >
                Cancel
              </button>
              <button
                onClick={handleCreatePayout}
                className="flex-1 rounded-xl bg-emerald-600 px-4 py-2.5 font-semibold text-white transition-colors hover:bg-emerald-700"
              >
                Create Payout
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
