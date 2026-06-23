import { useEffect, useMemo, useState } from 'react';
import {
  ArrowDownLeft,
  ArrowUpRight,
  Building2,
  CreditCard,
  Lock,
  QrCode,
  RefreshCw,
  Sparkles,
  Wallet,
} from 'lucide-react';
import SubscriptionModal from '@/components/SubscriptionModal';
import GatewayPaymentFrame from '@/components/GatewayPaymentFrame';
import {
  createWalletTopupSession,
  getCatalogApps,
  getPaymentGatewayStatus,
  getWalletOverview,
  getWalletTransactions,
  getPayoutPolicy,
  pollWalletBalance,
  reconcileCheckout,
  requestWithdrawal,
  walletTopupMock,
} from '@/lib/hellomApi';
import { cn } from '@/lib/utils';

type TabMode = 'overview' | 'deposit' | 'withdraw';

type AppCard = {
  app: { slug: string; name: string };
  entitlement: {
    status: string;
    allowed: boolean;
    current_plan?: { slug: string; name: string; type: string; price: number } | null;
  };
  cta: {
    type: string;
    label: string;
    target: string;
    recommended_plan?: { slug: string; name: string; type: string; price: number } | null;
  };
};

function formatCurrency(value: number) {
  return `Rp ${value.toLocaleString('id-ID')}`;
}

const txTypeLabel = (type: string) => {
  const map: Record<string, string> = {
    wallet_topup: 'Top Up Saldo',
    subscription_debit: 'Pembayaran Langganan',
    wallet_debit: 'Pembayaran',
    wallet_credit: 'Kredit Saldo',
    subscription_renewal: 'Perpanjangan',
    refund: 'Refund',
    manual_topup: 'Top Up Manual',
  };
  return map[type] || (type || '-').replace(/_/g, ' ');
};

export default function Payments() {
  const [activeTab, setActiveTab] = useState<TabMode>('overview');
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [requesterRole, setRequesterRole] = useState('member');

  const [availableBalance, setAvailableBalance] = useState(0);
  const [pendingBalance, setPendingBalance] = useState(0);
  const [totalIn, setTotalIn] = useState(0);
  const [totalOut, setTotalOut] = useState(0);
  const [transactions, setTransactions] = useState<Array<Record<string, unknown>>>([]);
  const [pendingWithdrawals, setPendingWithdrawals] = useState<Array<Record<string, unknown>>>([]);
  const [catalogApps, setCatalogApps] = useState<AppCard[]>([]);

  const [gatewayStatus, setGatewayStatus] = useState<{
    provider: string;
    active_provider: 'xendit' | 'ipaymu';
    mode: 'sandbox' | 'production';
    is_ready: boolean;
    member_wallet_enabled: boolean;
    supports: Record<string, boolean>;
    webhook: { path: string; callback_token_configured: boolean };
  } | null>(null);

  const [depositAmount, setDepositAmount] = useState(250000);
  const [qrisEstimatedFee, setQrisEstimatedFee] = useState<number | null>(null);
  const [qrisEstimatedNet, setQrisEstimatedNet] = useState<number | null>(null);
  const [vaEstimatedFee, setVaEstimatedFee] = useState<number | null>(null);
  const [vaEstimatedNet, setVaEstimatedNet] = useState<number | null>(null);
  const [withdrawEstimatedFee, setWithdrawEstimatedFee] = useState<number | null>(null);
  const [withdrawEstimatedNet, setWithdrawEstimatedNet] = useState<number | null>(null);

  const [withdrawAmount, setWithdrawAmount] = useState('');
  const [bankCode, setBankCode] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [accountName, setAccountName] = useState('');

  const [selectedApp, setSelectedApp] = useState<{ name: string; slug: string } | null>(null);
  const [gatewayPaymentUrl, setGatewayPaymentUrl] = useState<string | null>(null);
  const [gatewayBalanceSnapshot, setGatewayBalanceSnapshot] = useState<number | null>(null);

  const lockedApps = useMemo(
    () => catalogApps.filter((item) => !item.entitlement.allowed),
    [catalogApps]
  );
  const canManagePayouts = ['owner', 'admin', 'super_admin'].includes(requesterRole);
  const gatewayReady = Boolean(gatewayStatus?.is_ready);

  const loadPage = async () => {
    setLoading(true);
    setError(null);
    try {
      const [overview, trx, catalog, gateway] = await Promise.all([
        getWalletOverview(),
        getWalletTransactions({ limit: 20 }),
        getCatalogApps().catch(() => ({ items: [] as AppCard[] })),
        getPaymentGatewayStatus().catch(() => null),
      ]);

      setRequesterRole(String((overview as { requester_role?: string }).requester_role || 'member'));
      setAvailableBalance(overview.wallet.available_balance || 0);
      setPendingBalance(overview.wallet.pending_balance || 0);
      setTotalIn(overview.wallet.total_in || 0);
      setTotalOut(overview.wallet.total_out || 0);
      setTransactions(trx.items || []);
      setPendingWithdrawals(overview.pending_withdrawals || []);
      setCatalogApps((catalog.items || []) as AppCard[]);
      setGatewayStatus(gateway);
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat halaman pembayaran';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadPage();
  }, []);

  // Handle the browser return from an iPaymu gateway redirect: verify + activate
  // without relying on the inbound webhook (which can't reach local/sandbox servers).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('ipaymu_return') !== '1') return;

    const intent = params.get('intent');
    const cancelled = params.get('cancel') === '1';
    const trxId = params.get('trx_id') || params.get('transaction_id') || params.get('tid') || undefined;
    window.history.replaceState({}, '', '/dashboard/payments');

    if (cancelled || !intent) {
      if (cancelled) setError('Pembayaran dibatalkan.');
      return;
    }

    setSuccessMessage('Memeriksa status pembayaran...');
    reconcileCheckout(intent, trxId)
      .then((res) => {
        setSuccessMessage(
          res.active
            ? 'Pembayaran berhasil dikonfirmasi! Akses aplikasi sudah aktif.'
            : 'Pembayaran sedang diproses. Akses akan aktif begitu pembayaran terkonfirmasi.'
        );
        void loadPage();
      })
      .catch(() => setError('Gagal memverifikasi pembayaran. Silakan muat ulang halaman.'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    let cancelled = false;

    const loadEstimates = async () => {
      try {
        const [qrisPolicy, vaPolicy, withdrawPolicy] = await Promise.all([
          getPayoutPolicy({ channel: 'qris', amount: 100000 }),
          getPayoutPolicy({ channel: 'va', amount: depositAmount }),
          getPayoutPolicy(
            Number(withdrawAmount) > 0
              ? { channel: 'va', amount: Number(withdrawAmount) }
              : { channel: 'va' }
          ),
        ]);

        if (cancelled) return;

        setQrisEstimatedFee(qrisPolicy.estimation.fee_amount);
        setQrisEstimatedNet(qrisPolicy.estimation.net_amount);
        setVaEstimatedFee(vaPolicy.estimation.fee_amount);
        setVaEstimatedNet(vaPolicy.estimation.net_amount);
        setWithdrawEstimatedFee(withdrawPolicy.estimation.fee_amount);
        setWithdrawEstimatedNet(withdrawPolicy.estimation.net_amount);
      } catch {
        if (cancelled) return;
        setQrisEstimatedFee(null);
        setQrisEstimatedNet(null);
        setVaEstimatedFee(null);
        setVaEstimatedNet(null);
        setWithdrawEstimatedFee(null);
        setWithdrawEstimatedNet(null);
      }
    };

    void loadEstimates();
    return () => { cancelled = true; };
  }, [depositAmount, withdrawAmount]);

  const handleWithdraw = async (event: React.FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    setSuccessMessage(null);

    try {
      await requestWithdrawal({
        amount: Number(withdrawAmount),
        bank_code: bankCode,
        account_number: accountNumber,
        account_name: accountName,
        notes: '',
      });
      setWithdrawAmount('');
      setSuccessMessage('Permintaan penarikan berhasil dikirim dan sedang dalam proses review.');
      await loadPage();
    } catch (submitError) {
      const message = submitError instanceof Error ? submitError.message : 'Gagal mengajukan penarikan';
      setError(message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleTopupSession = async (amount: number, source: string) => {
    setSubmitting(true);
    setError(null);
    setSuccessMessage(null);

    try {
      if (!gatewayReady) {
        await walletTopupMock({ amount, source, notes: '' });
        await loadPage();
        setSuccessMessage('Permintaan deposit sedang diproses. Saldo Anda akan diperbarui setelah konfirmasi.');
        return;
      }

      const result = await createWalletTopupSession({ amount, channel: source });
      if (result.payment_url) {
        setGatewayBalanceSnapshot(availableBalance);
        setGatewayPaymentUrl(result.payment_url as string);
      }
      setSuccessMessage(`Sesi pembayaran dibuat. Selesaikan pembayaran untuk menambah saldo.`);
    } catch (topupError) {
      const message = topupError instanceof Error ? topupError.message : 'Top up gagal';
      setError(message);
    } finally {
      setSubmitting(false);
    }
  };

  if (!loading && !gatewayStatus?.member_wallet_enabled) {
    return (
      <div className="mx-auto max-w-4xl space-y-6 p-4 md:p-8">
        <div className="rounded-3xl border border-amber-200 bg-amber-50 p-6">
          <h1 className="text-2xl font-bold text-amber-900">Dompet Digital Tidak Tersedia</h1>
          <p className="mt-3 text-sm leading-6 text-amber-800">
            Fitur dompet digital sedang tidak aktif. Anda tetap bisa mengaktifkan aplikasi berbayar melalui metode pembayaran lain.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
      {/* Hero — dark banner */}
      <div className="rounded-3xl border border-zinc-200 bg-gradient-to-br from-zinc-950 via-zinc-900 to-zinc-800 p-5 text-white shadow-xl shadow-zinc-950/10 sm:p-6">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
          <div className="max-w-2xl">
            <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-amber-200">
              <Sparkles className="h-3.5 w-3.5" />
              Dompet Digital Hellom
            </div>
            <h1 className="mt-4 text-2xl font-bold tracking-tight text-white sm:text-3xl">
              Kelola Saldo & Pembayaran
            </h1>
            <p className="mt-2 text-sm leading-6 text-zinc-300">
              Top up saldo untuk perpanjangan otomatis, atau gunakan saldo langsung untuk mengaktifkan aplikasi berbayar.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-3 sm:w-auto">
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Saldo tersedia</p>
              <p className="mt-2 text-2xl font-bold text-white sm:text-3xl">{formatCurrency(availableBalance)}</p>
              {pendingBalance > 0 && (
                <p className="mt-1 text-xs text-zinc-400">Menunggu: {formatCurrency(pendingBalance)}</p>
              )}
            </div>
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</p>
              <p className="mt-2 text-base font-bold text-white">
                {gatewayReady ? 'Siap' : 'Dalam Konfigurasi'}
              </p>
              <p className="mt-1 text-xs text-zinc-400">
                {gatewayReady ? 'Pembayaran aktif' : 'Hubungi dukungan'}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex flex-wrap items-center gap-2">
        {(['overview', 'deposit', 'withdraw'] as TabMode[]).map((tab) => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab)}
            className={cn(
              'rounded-2xl px-4 py-2.5 text-sm font-semibold transition',
              activeTab === tab
                ? 'bg-zinc-950 text-white'
                : 'border border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50'
            )}
          >
            {tab === 'overview' ? 'Ringkasan' : tab === 'deposit' ? 'Isi Saldo' : 'Tarik Saldo'}
          </button>
        ))}
        <button
          onClick={() => void loadPage()}
          className="ml-auto rounded-2xl border border-zinc-200 bg-white p-2.5 text-zinc-700 transition hover:bg-zinc-50"
          title="Refresh"
        >
          <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
        </button>
      </div>

      {(error || successMessage) && (
        <div className="space-y-3">
          {error && (
            <div className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {error}
            </div>
          )}
          {successMessage && (
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
              {successMessage}
            </div>
          )}
        </div>
      )}

      {/* ── Overview ── */}
      {activeTab === 'overview' && (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {[
              { label: 'Total masuk', value: totalIn, icon: ArrowDownLeft, tone: 'emerald', isCurrency: true },
              { label: 'Total keluar', value: totalOut, icon: ArrowUpRight, tone: 'rose', isCurrency: true },
              { label: 'Aplikasi terkunci', value: lockedApps.length, icon: Lock, tone: 'amber', isCurrency: false },
            ].map((card) => (
              <div key={card.label} className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div className="flex items-center gap-3">
                  <div
                    className={cn(
                      'flex h-12 w-12 items-center justify-center rounded-2xl',
                      card.tone === 'emerald' && 'bg-emerald-100 text-emerald-700',
                      card.tone === 'rose' && 'bg-rose-100 text-rose-700',
                      card.tone === 'amber' && 'bg-amber-100 text-amber-700'
                    )}
                  >
                    <card.icon className="h-5 w-5" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-zinc-500">{card.label}</p>
                    <p className="text-xl font-bold text-zinc-950 sm:text-2xl">
                      {card.isCurrency ? formatCurrency(card.value) : card.value.toLocaleString('id-ID')}
                    </p>
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div className="grid gap-6 xl:grid-cols-[1.4fr_0.6fr]">
            {/* Transaction history */}
            <section className="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm">
              <div className="border-b border-zinc-200 px-6 py-4">
                <h2 className="text-lg font-bold text-zinc-950">Riwayat Transaksi</h2>
                <p className="text-sm text-zinc-500">Seluruh aktivitas saldo dompet digital Anda.</p>
              </div>
              <div className="overflow-x-auto">
                <table className="min-w-full">
                  <thead className="bg-zinc-50">
                    <tr className="text-left text-xs font-bold uppercase tracking-wide text-zinc-500">
                      <th className="px-6 py-3">Keterangan</th>
                      <th className="px-6 py-3">Tanggal</th>
                      <th className="px-6 py-3">Nominal</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-100">
                    {transactions.map((trx) => {
                      const isCredit = String(trx.direction) === 'credit';
                      const dateStr = String(trx.created_at || '-').split('T')[0];
                      return (
                        <tr key={String(trx.id)} className="hover:bg-zinc-50">
                          <td className="px-6 py-4 text-sm font-semibold text-zinc-900">
                            {txTypeLabel(String(trx.type || ''))}
                          </td>
                          <td className="px-6 py-4 text-sm text-zinc-500">{dateStr}</td>
                          <td className={cn(
                            'px-6 py-4 text-sm font-bold',
                            isCredit ? 'text-emerald-700' : 'text-zinc-800'
                          )}>
                            {isCredit ? '+' : '-'} {formatCurrency(Number(trx.amount || 0))}
                          </td>
                        </tr>
                      );
                    })}
                    {!loading && transactions.length === 0 && (
                      <tr>
                        <td colSpan={3} className="px-6 py-10 text-center text-sm text-zinc-500">
                          Belum ada transaksi.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </section>

            {/* Locked apps */}
            <section className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
              <h2 className="text-lg font-bold text-zinc-950">Aplikasi Terkunci</h2>
              <p className="mt-1 text-sm text-zinc-500">
                Aktifkan untuk mendapatkan akses penuh.
              </p>
              <div className="mt-4 space-y-3">
                {lockedApps.map((item) => (
                  <div key={item.app.slug} className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <p className="font-semibold text-zinc-950">{item.app.name}</p>
                        <p className="mt-1 text-sm text-zinc-500">
                          {item.cta.recommended_plan
                            ? `${item.cta.recommended_plan.name} — ${formatCurrency(item.cta.recommended_plan.price)}`
                            : 'Lihat pilihan paket'}
                        </p>
                      </div>
                      <button
                        onClick={() => setSelectedApp({ name: item.app.name, slug: item.app.slug })}
                        className="rounded-2xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800"
                      >
                        Aktifkan
                      </button>
                    </div>
                  </div>
                ))}
                {lockedApps.length === 0 && (
                  <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-4 text-sm text-zinc-500">
                    Semua aplikasi sudah aktif.
                  </div>
                )}
              </div>
            </section>
          </div>
        </div>
      )}

      {/* ── Isi Saldo (Deposit) ── */}
      {activeTab === 'deposit' && (
        <div className="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
          <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 className="text-xl font-bold text-zinc-950">Isi Saldo</h2>
            <p className="mt-2 text-sm text-zinc-500">
              Pilih metode deposit untuk menambahkan saldo dompet digital Anda.
            </p>

            <div className="mt-6 grid gap-4 md:grid-cols-2">
              {/* QRIS */}
              <article className="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
                    <QrCode className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="font-bold text-zinc-950">QRIS</h3>
                    <p className="text-sm text-zinc-500">Scan & bayar instan dari aplikasi dompet manapun.</p>
                  </div>
                </div>
                <div className="mt-5 space-y-1.5 text-sm text-zinc-600">
                  {qrisEstimatedFee !== null && qrisEstimatedFee > 0 && (
                    <p>Biaya layanan: {formatCurrency(qrisEstimatedFee)}</p>
                  )}
                  {qrisEstimatedNet !== null && (
                    <p className="font-medium text-zinc-800">
                      Saldo masuk: {formatCurrency(qrisEstimatedNet)}
                    </p>
                  )}
                </div>
                <button
                  onClick={() => void handleTopupSession(100000, 'qris')}
                  disabled={submitting}
                  className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
                >
                  <Wallet className="h-4 w-4" />
                  Deposit via QRIS (Rp 100.000)
                </button>
              </article>

              {/* VA */}
              <article className="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 text-sky-700">
                    <Building2 className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="font-bold text-zinc-950">Transfer Bank</h3>
                    <p className="text-sm text-zinc-500">Cocok untuk nominal besar via transfer ATM/mobile banking.</p>
                  </div>
                </div>
                <div className="mt-5 space-y-3">
                  <label className="space-y-2 text-sm">
                    <span className="font-medium text-zinc-700">Jumlah deposit (Rp)</span>
                    <input
                      type="number"
                      min="10000"
                      value={depositAmount}
                      onChange={(event) => setDepositAmount(Number(event.target.value) || 0)}
                      className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    />
                  </label>
                  {(vaEstimatedFee !== null || vaEstimatedNet !== null) && (
                    <div className="rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-600">
                      {vaEstimatedFee !== null && vaEstimatedFee > 0 && (
                        <span>Biaya: {formatCurrency(vaEstimatedFee)} · </span>
                      )}
                      {vaEstimatedNet !== null && (
                        <span>Saldo masuk: {formatCurrency(vaEstimatedNet)}</span>
                      )}
                    </div>
                  )}
                  <button
                    onClick={() => void handleTopupSession(depositAmount, 'va')}
                    disabled={submitting || depositAmount < 10000}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-100 disabled:opacity-60"
                  >
                    <CreditCard className="h-4 w-4" />
                    Deposit via Transfer Bank
                  </button>
                </div>
              </article>
            </div>
          </section>

          {/* Locked apps in deposit tab */}
          <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 className="text-xl font-bold text-zinc-950">Aktifkan Aplikasi</h2>
            <p className="mt-2 text-sm text-zinc-500">
              Bayar langsung untuk mengaktifkan aplikasi yang belum aktif.
            </p>
            <div className="mt-5 space-y-3">
              {lockedApps.map((item) => (
                <div key={item.app.slug} className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <p className="font-semibold text-zinc-950">{item.app.name}</p>
                      <p className="mt-1 text-sm text-zinc-500">
                        {item.cta.recommended_plan?.name || 'Lihat pilihan paket'}
                      </p>
                    </div>
                    <button
                      onClick={() => setSelectedApp({ name: item.app.name, slug: item.app.slug })}
                      className="rounded-2xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800"
                    >
                      Bayar
                    </button>
                  </div>
                </div>
              ))}
              {lockedApps.length === 0 && (
                <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-4 text-sm text-zinc-500">
                  Tidak ada aplikasi yang perlu diaktifkan.
                </div>
              )}
            </div>
          </section>
        </div>
      )}

      {/* ── Tarik Saldo (Withdraw) ── */}
      {activeTab === 'withdraw' && (
        <div className="mx-auto max-w-3xl rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm md:p-8">
          <div className="text-center">
            <p className="text-sm font-medium uppercase tracking-wide text-zinc-500">Saldo yang bisa ditarik</p>
            <h2 className="mt-3 text-4xl font-bold text-zinc-950">{formatCurrency(availableBalance)}</h2>
            <p className="mt-2 text-sm text-zinc-500">Penarikan akan diproses sesuai ketentuan yang berlaku.</p>
          </div>

          {!canManagePayouts && (
            <div className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              Akun Anda belum memiliki akses untuk melakukan penarikan saldo. Hubungi pemilik organisasi untuk informasi lebih lanjut.
            </div>
          )}

          <form onSubmit={handleWithdraw} className="mt-8 space-y-5">
            <label className="space-y-2 text-sm">
              <span className="font-semibold text-zinc-700">Jumlah penarikan</span>
              <div className="relative">
                <span className="absolute left-4 top-3.5 text-sm font-semibold text-zinc-500">Rp</span>
                <input
                  type="number"
                  required
                  min="10000"
                  max={availableBalance}
                  value={withdrawAmount}
                  onChange={(event) => setWithdrawAmount(event.target.value)}
                  disabled={!canManagePayouts}
                  className="w-full rounded-2xl border border-zinc-300 py-3 pl-12 pr-4 text-lg font-semibold text-zinc-950 outline-none transition focus:border-amber-400"
                  placeholder="0"
                />
              </div>
            </label>

            <div className="grid gap-4 md:grid-cols-3">
              <label className="space-y-2 text-sm">
                <span className="font-semibold text-zinc-700">Kode Bank</span>
                <input
                  value={bankCode}
                  onChange={(event) => setBankCode(event.target.value)}
                  disabled={!canManagePayouts}
                  placeholder="BCA"
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="font-semibold text-zinc-700">No. rekening</span>
                <input
                  value={accountNumber}
                  onChange={(event) => setAccountNumber(event.target.value)}
                  disabled={!canManagePayouts}
                  placeholder="1234567890"
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="font-semibold text-zinc-700">Nama rekening</span>
                <input
                  value={accountName}
                  onChange={(event) => setAccountName(event.target.value)}
                  disabled={!canManagePayouts}
                  placeholder="Nama pemilik rekening"
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                />
              </label>
            </div>

            {(withdrawEstimatedFee !== null || withdrawEstimatedNet !== null) && (
              <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
                {withdrawEstimatedFee !== null && withdrawEstimatedFee > 0 && (
                  <span>Biaya transfer: {formatCurrency(withdrawEstimatedFee)} · </span>
                )}
                {withdrawEstimatedNet !== null && (
                  <span>Dana bersih diterima: {formatCurrency(withdrawEstimatedNet)}</span>
                )}
              </div>
            )}

            <button
              type="submit"
              disabled={submitting || !canManagePayouts || !withdrawAmount || Number(withdrawAmount) > availableBalance}
              className="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
            >
              <ArrowUpRight className="h-4 w-4" />
              {submitting ? 'Memproses...' : 'Ajukan Penarikan'}
            </button>
          </form>

          <div className="mt-8 rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
            <h3 className="text-sm font-bold uppercase tracking-wide text-zinc-700">Penarikan yang Sedang Diproses</h3>
            <div className="mt-4 space-y-3">
              {pendingWithdrawals.map((item) => (
                <div key={String(item.id)} className="flex items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white px-4 py-3">
                  <div>
                    <p className="text-sm font-semibold text-zinc-900">
                      {formatCurrency(Number(item.amount || 0))} ke {String(item.bank_code || '-')}
                    </p>
                    <p className="text-xs text-zinc-500">
                      {String(item.created_at || '-').split('T')[0]}
                    </p>
                  </div>
                  <span className="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold capitalize text-zinc-700">
                    {String(item.status || 'pending') === 'pending' ? 'Menunggu' : String(item.status || '')}
                  </span>
                </div>
              ))}
              {pendingWithdrawals.length === 0 && (
                <div className="rounded-2xl border border-dashed border-zinc-300 bg-white px-4 py-4 text-sm text-zinc-500">
                  Tidak ada penarikan yang sedang diproses.
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {selectedApp && (
        <SubscriptionModal
          isOpen={Boolean(selectedApp)}
          onClose={() => setSelectedApp(null)}
          appName={selectedApp.name}
          appSlug={selectedApp.slug}
          onSuccess={() => {
            void loadPage();
            setSelectedApp(null);
          }}
        />
      )}

      {/* Gateway top-up iframe — stays inside Hellom */}
      {gatewayPaymentUrl && (
        <GatewayPaymentFrame
          paymentUrl={gatewayPaymentUrl}
          title="Top Up Saldo"
          onClose={() => {
            setGatewayPaymentUrl(null);
            setGatewayBalanceSnapshot(null);
          }}
          onPaid={() => {
            setGatewayPaymentUrl(null);
            setGatewayBalanceSnapshot(null);
            setSuccessMessage('Top up berhasil! Saldo kamu sudah bertambah.');
            void loadPage();
          }}
          pollFn={
            gatewayBalanceSnapshot !== null
              ? async () => {
                  const current = await pollWalletBalance();
                  return current > (gatewayBalanceSnapshot ?? 0);
                }
              : undefined
          }
        />
      )}
    </div>
  );
}
