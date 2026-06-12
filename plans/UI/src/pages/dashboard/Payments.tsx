import { useEffect, useMemo, useState } from 'react';
import {
  ArrowDownLeft,
  ArrowUpRight,
  BadgeCheck,
  Building2,
  CreditCard,
  Lock,
  QrCode,
  RefreshCw,
  ShieldCheck,
  Sparkles,
  TicketPercent,
  Wallet,
} from 'lucide-react';
import SubscriptionModal from '@/components/SubscriptionModal';
import {
  createWalletTopupSession,
  getCatalogApps,
  getPaymentGatewayStatus,
  getWalletOverview,
  getWalletTransactions,
  getPayoutPolicy,
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
  const [bankCode, setBankCode] = useState('BCA');
  const [accountNumber, setAccountNumber] = useState('1234567890');
  const [accountName, setAccountName] = useState('Demo Owner');

  const [selectedApp, setSelectedApp] = useState<{ name: string; slug: string } | null>(null);

  const lockedApps = useMemo(
    () => catalogApps.filter((item) => !item.entitlement.allowed),
    [catalogApps]
  );
  const canManagePayouts = ['owner', 'admin', 'super_admin'].includes(requesterRole);
  const gatewayReady = Boolean(gatewayStatus?.is_ready);
  const gatewayEnvironment = gatewayStatus?.mode === 'production' ? 'Production' : 'Sandbox';
  const providerLabel = gatewayStatus?.active_provider === 'ipaymu' ? 'iPaymu' : 'Xendit';

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

        if (cancelled) {
          return;
        }

        setQrisEstimatedFee(qrisPolicy.estimation.fee_amount);
        setQrisEstimatedNet(qrisPolicy.estimation.net_amount);
        setVaEstimatedFee(vaPolicy.estimation.fee_amount);
        setVaEstimatedNet(vaPolicy.estimation.net_amount);
        setWithdrawEstimatedFee(withdrawPolicy.estimation.fee_amount);
        setWithdrawEstimatedNet(withdrawPolicy.estimation.net_amount);
      } catch {
        if (cancelled) {
          return;
        }

        setQrisEstimatedFee(null);
        setQrisEstimatedNet(null);
        setVaEstimatedFee(null);
        setVaEstimatedNet(null);
        setWithdrawEstimatedFee(null);
        setWithdrawEstimatedNet(null);
      }
    };

    void loadEstimates();

    return () => {
      cancelled = true;
    };
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
        notes: 'dashboard_payments_withdrawal',
      });
      setWithdrawAmount('');
      setSuccessMessage('Permintaan withdrawal berhasil dikirim dan masuk antrean review.');
      await loadPage();
    } catch (submitError) {
      const message = submitError instanceof Error ? submitError.message : 'Gagal request withdrawal';
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
        await walletTopupMock({
          amount,
          source,
          notes: 'gateway_not_ready_wallet_topup_simulation',
        });
        await loadPage();
        setSuccessMessage(`Gateway belum siap penuh, jadi sistem memakai simulasi aman untuk ${source.toUpperCase()}. Begitu kredensial ${providerLabel} lengkap, tombol ini akan membuat payment session sungguhan.`);
        return;
      }

      const result = await createWalletTopupSession({ amount, channel: source });
      if (result.payment_url) {
        window.open(result.payment_url, '_blank', 'noopener,noreferrer');
      }
      setSuccessMessage(`Link pembayaran ${source.toUpperCase()} untuk ${formatCurrency(amount)} berhasil dibuat. Saldo akan masuk otomatis setelah pembayaran sukses.`);
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
          <h1 className="text-2xl font-bold text-amber-900">Fitur wallet member sedang disembunyikan</h1>
          <p className="mt-3 text-sm leading-6 text-amber-800">
            Owner sedang mematikan wallet/e-wallet untuk seluruh pembeli. Checkout langganan tetap berjalan dari modal aktivasi aplikasi dengan gateway {providerLabel}.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6 p-4 md:p-8">
      <div className="rounded-3xl border border-zinc-200 bg-gradient-to-br from-zinc-950 via-zinc-900 to-zinc-800 p-6 text-white shadow-xl shadow-zinc-950/10">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
          <div className="max-w-3xl">
            <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-amber-200">
              <Sparkles className="h-3.5 w-3.5" />
              Wallet, deposit, dan checkout
            </div>
            <h1 className="mt-4 text-3xl font-bold tracking-tight text-white">Pembayaran member Hellom yang siap lanjut ke {providerLabel}</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-zinc-300">
              Wallet tetap menjadi saldo utama untuk auto-renew dan payout. Di halaman ini member juga bisa melihat kesiapan gateway, top up via channel, dan aktivasi aplikasi berbayar yang masih terkunci.
            </p>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Saldo tersedia</p>
              <p className="mt-2 text-3xl font-bold text-white">{formatCurrency(availableBalance)}</p>
              <p className="mt-1 text-xs text-zinc-400">Pending {formatCurrency(pendingBalance)}</p>
            </div>
            <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Gateway status</p>
              <p className="mt-2 text-lg font-bold text-white">
                {gatewayReady ? `${providerLabel} ${gatewayEnvironment}` : 'Belum aktif penuh'}
              </p>
              <p className="mt-1 text-xs text-zinc-400">
                {gatewayStatus?.webhook.callback_token_configured
                  ? 'Webhook callback sudah diverifikasi'
                  : 'Token callback masih perlu dipasang'}
              </p>
            </div>
          </div>
        </div>
      </div>

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
            {tab === 'overview' ? 'Overview' : tab === 'deposit' ? 'Deposit & Checkout' : 'Withdraw'}
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
            <div className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
          )}
          {successMessage && (
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{successMessage}</div>
          )}
        </div>
      )}

      {activeTab === 'overview' && (
        <div className="space-y-6">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {[
              { label: 'Total masuk', value: totalIn, icon: ArrowDownLeft, tone: 'emerald' },
              { label: 'Total keluar', value: totalOut, icon: ArrowUpRight, tone: 'rose' },
              { label: 'App terkunci', value: lockedApps.length, icon: Lock, tone: 'amber' },
              { label: 'Metode siap', value: gatewayStatus ? Object.values(gatewayStatus.supports).filter(Boolean).length : 0, icon: ShieldCheck, tone: 'sky' },
            ].map((card) => (
              <div key={card.label} className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div className="flex items-center gap-3">
                  <div
                    className={cn(
                      'flex h-12 w-12 items-center justify-center rounded-2xl',
                      card.tone === 'emerald' && 'bg-emerald-100 text-emerald-700',
                      card.tone === 'rose' && 'bg-rose-100 text-rose-700',
                      card.tone === 'amber' && 'bg-amber-100 text-amber-700',
                      card.tone === 'sky' && 'bg-sky-100 text-sky-700'
                    )}
                  >
                    <card.icon className="h-5 w-5" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-zinc-500">{card.label}</p>
                    <p className="text-2xl font-bold text-zinc-950">
                      {card.label === 'App terkunci' || card.label === 'Metode siap'
                        ? card.value.toLocaleString('id-ID')
                        : formatCurrency(card.value)}
                    </p>
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <section className="rounded-3xl border border-zinc-200 bg-white shadow-sm">
              <div className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                <div>
                  <h2 className="text-lg font-bold text-zinc-950">Riwayat transaksi wallet</h2>
                  <p className="text-sm text-zinc-500">Ledger terbaru untuk deposit, debit langganan, dan settlement.</p>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="min-w-full">
                  <thead className="bg-zinc-50">
                    <tr className="text-left text-xs font-bold uppercase tracking-wide text-zinc-500">
                      <th className="px-6 py-3">Type</th>
                      <th className="px-6 py-3">Tanggal</th>
                      <th className="px-6 py-3">Amount</th>
                      <th className="px-6 py-3">Direction</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-100">
                    {transactions.map((trx) => (
                      <tr key={String(trx.id)} className="hover:bg-zinc-50">
                        <td className="px-6 py-4 text-sm font-semibold text-zinc-900">{String(trx.type || '-')}</td>
                        <td className="px-6 py-4 text-sm text-zinc-500">{String(trx.created_at || '-')}</td>
                        <td className={cn(
                          'px-6 py-4 text-sm font-bold',
                          String(trx.direction) === 'credit' ? 'text-emerald-700' : 'text-zinc-900'
                        )}>
                          {String(trx.direction) === 'credit' ? '+' : '-'} {formatCurrency(Number(trx.amount || 0))}
                        </td>
                        <td className="px-6 py-4 text-sm text-zinc-500 capitalize">{String(trx.direction || '-')}</td>
                      </tr>
                    ))}
                    {!loading && transactions.length === 0 && (
                      <tr>
                        <td colSpan={4} className="px-6 py-10 text-center text-sm text-zinc-500">Belum ada transaksi.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </section>

            <section className="space-y-4">
              <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 className="text-lg font-bold text-zinc-950">Kesiapan integrasi</h2>
                <div className="mt-4 space-y-3 text-sm">
                  <div className="flex items-start gap-3 rounded-2xl bg-zinc-50 p-3">
                    <BadgeCheck className={cn('mt-0.5 h-4 w-4', canManagePayouts ? 'text-emerald-600' : 'text-amber-600')} />
                    <div>
                      <p className="font-semibold text-zinc-900">Hak akses payout mengikuti role organisasi</p>
                      <p className="text-zinc-500">
                        {canManagePayouts
                          ? `Akun ini terdeteksi sebagai ${requesterRole.replace('_', ' ')} dan dapat mengajukan atau meninjau payout sesuai izin.`
                          : 'Akun ini hanya dapat melihat saldo dan histori; withdrawal tetap dibatasi untuk owner, admin, atau super admin.'}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-start gap-3 rounded-2xl bg-zinc-50 p-3">
                    <BadgeCheck className={cn('mt-0.5 h-4 w-4', gatewayReady ? 'text-emerald-600' : 'text-amber-600')} />
                    <div>
                      <p className="font-semibold text-zinc-900">{providerLabel} mode {gatewayStatus?.mode || 'sandbox'}</p>
                      <p className="text-zinc-500">
                        {gatewayReady
                          ? `Kredensial ${providerLabel} sudah terbaca dari backend. Deposit member akan memakai payment session gateway aktif sesuai environment.`
                          : 'Gateway belum lengkap, jadi halaman ini hanya akan memakai simulasi aman sampai kredensial owner atau super admin disimpan.'}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-start gap-3 rounded-2xl bg-zinc-50 p-3">
                    <BadgeCheck className="mt-0.5 h-4 w-4 text-emerald-600" />
                    <div>
                      <p className="font-semibold text-zinc-900">Webhook route sudah dipasang</p>
                      <p className="break-all text-zinc-500">{gatewayStatus?.webhook.path || '/api/v1/hellom/webhooks/xendit'}</p>
                    </div>
                  </div>
                </div>
              </div>

              <div className="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 className="text-lg font-bold text-zinc-950">Aplikasi berbayar yang masih terkunci</h2>
                <div className="mt-4 space-y-3">
                  {lockedApps.map((item) => (
                    <div key={item.app.slug} className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                      <div className="flex items-start justify-between gap-4">
                        <div>
                          <p className="font-semibold text-zinc-950">{item.app.name}</p>
                          <p className="mt-1 text-sm text-zinc-500">
                            {item.cta.recommended_plan
                              ? `${item.cta.recommended_plan.name} | ${formatCurrency(item.cta.recommended_plan.price)}`
                              : 'Belum ada plan rekomendasi'}
                          </p>
                        </div>
                        <button
                          onClick={() => setSelectedApp({ name: item.app.name, slug: item.app.slug })}
                          className="rounded-2xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800"
                        >
                          Aktivasi
                        </button>
                      </div>
                    </div>
                  ))}
                  {lockedApps.length === 0 && (
                    <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-4 text-sm text-zinc-500">
                      Semua aplikasi yang tampil di catalog sudah aktif untuk organisasi ini.
                    </div>
                  )}
                </div>
              </div>
            </section>
          </div>
        </div>
      )}

      {activeTab === 'deposit' && (
        <div className="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
          <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 className="text-xl font-bold text-zinc-950">Top up saldo dan checkout langsung</h2>
            <p className="mt-2 text-sm text-zinc-500">
              Member dapat memilih jalur deposit dulu ke wallet atau langsung aktivasi aplikasi berbayar dari dashboard.
            </p>

            <div className="mt-6 grid gap-4 md:grid-cols-2">
              <article className="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
                    <QrCode className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="font-bold text-zinc-950">QRIS Deposit</h3>
                    <p className="text-sm text-zinc-500">Top up cepat untuk saldo wallet member.</p>
                  </div>
                </div>
                <div className="mt-5 space-y-2 text-sm text-zinc-600">
                  <p>Estimasi fee: {qrisEstimatedFee !== null ? formatCurrency(qrisEstimatedFee) : '-'}</p>
                  <p>Saldo bersih estimasi: {qrisEstimatedNet !== null ? formatCurrency(qrisEstimatedNet) : '-'}</p>
                  <p>Environment: {gatewayEnvironment}</p>
                </div>
                <button
                  onClick={() => void handleTopupSession(100000, 'qris')}
                  disabled={submitting}
                  className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
                >
                  <Wallet className="h-4 w-4" />
                  Buka deposit QRIS 100k
                </button>
              </article>

              <article className="rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 text-sky-700">
                    <Building2 className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="font-bold text-zinc-950">Virtual Account</h3>
                    <p className="text-sm text-zinc-500">Cocok untuk deposit nominal tetap dan invoice fallback.</p>
                  </div>
                </div>
                <div className="mt-5 space-y-3">
                  <label className="space-y-2 text-sm">
                    <span className="font-medium text-zinc-700">Simulasi nominal</span>
                    <input
                      type="number"
                      min="10000"
                      value={depositAmount}
                      onChange={(event) => setDepositAmount(Number(event.target.value) || 0)}
                      className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                    />
                  </label>
                  <div className="rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-600">
                    Fee estimasi {vaEstimatedFee !== null ? formatCurrency(vaEstimatedFee) : '-'} | Dana masuk {vaEstimatedNet !== null ? formatCurrency(vaEstimatedNet) : '-'}
                  </div>
                  <button
                    onClick={() => void handleTopupSession(depositAmount, 'va')}
                    disabled={submitting || depositAmount < 10000}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-100 disabled:opacity-60"
                  >
                    <CreditCard className="h-4 w-4" />
                    Buka deposit VA
                  </button>
                </div>
              </article>
            </div>
          </section>

          <section className="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 className="text-xl font-bold text-zinc-950">Checkout aplikasi terkunci</h2>
            <p className="mt-2 text-sm text-zinc-500">Kasus seperti POS kini langsung diarahkan ke flow aktivasi dari dashboard member.</p>
            <div className="mt-5 space-y-3">
              {lockedApps.map((item) => (
                <div key={item.app.slug} className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <p className="font-semibold text-zinc-950">{item.app.name}</p>
                      <p className="mt-1 text-sm text-zinc-500">
                        Rekomendasi: {item.cta.recommended_plan?.name || 'Plan belum disiapkan'}
                      </p>
                    </div>
                    <button
                      onClick={() => setSelectedApp({ name: item.app.name, slug: item.app.slug })}
                      className="rounded-2xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800"
                    >
                      Bayar sekarang
                    </button>
                  </div>
                </div>
              ))}
              {lockedApps.length === 0 && (
                <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-4 text-sm text-zinc-500">
                  Tidak ada aplikasi terkunci untuk akun ini.
                </div>
              )}
            </div>

            <div className="mt-6 rounded-3xl border border-amber-200 bg-amber-50 p-4">
              <div className="flex items-start gap-3">
                <TicketPercent className="mt-0.5 h-5 w-5 text-amber-700" />
                <div>
                  <p className="font-semibold text-amber-900">Promo code sudah siap dipakai saat subscribe</p>
                  <p className="mt-1 text-sm text-amber-800">
                    Modal subscribe sekarang membaca plan dari backend dan siap menerima diskon tanpa teks putih yang menyilaukan.
                  </p>
                </div>
              </div>
            </div>
          </section>
        </div>
      )}

      {activeTab === 'withdraw' && (
        <div className="mx-auto max-w-3xl rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm md:p-8">
          <div className="text-center">
            <p className="text-sm font-medium uppercase tracking-wide text-zinc-500">Saldo yang bisa ditarik</p>
            <h2 className="mt-3 text-4xl font-bold text-zinc-950">{formatCurrency(availableBalance)}</h2>
            <p className="mt-2 text-sm text-zinc-500">Withdrawal akan tetap mengikuti kebijakan fee dan settlement backend.</p>
          </div>

          {!canManagePayouts && (
            <div className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              Role akun ini adalah <span className="font-semibold">{requesterRole.replace('_', ' ')}</span>. Withdrawal hanya bisa diajukan oleh owner, admin, atau super admin organisasi.
            </div>
          )}

          <form onSubmit={handleWithdraw} className="mt-8 space-y-5">
            <label className="space-y-2 text-sm">
              <span className="font-semibold text-zinc-700">Nominal withdrawal</span>
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
                <span className="font-semibold text-zinc-700">Bank</span>
                <input
                  value={bankCode}
                  onChange={(event) => setBankCode(event.target.value)}
                  disabled={!canManagePayouts}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="font-semibold text-zinc-700">No. rekening</span>
                <input
                  value={accountNumber}
                  onChange={(event) => setAccountNumber(event.target.value)}
                  disabled={!canManagePayouts}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                />
              </label>
              <label className="space-y-2 text-sm">
                <span className="font-semibold text-zinc-700">Nama rekening</span>
                <input
                  value={accountName}
                  onChange={(event) => setAccountName(event.target.value)}
                  disabled={!canManagePayouts}
                  className="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-zinc-900 outline-none transition focus:border-amber-400"
                />
              </label>
            </div>

            <div className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
              Estimasi fee payout bank: {withdrawEstimatedFee !== null ? formatCurrency(withdrawEstimatedFee) : '-'} | Dana bersih: {withdrawEstimatedNet !== null ? formatCurrency(withdrawEstimatedNet) : '-'}
            </div>

            <button
              type="submit"
              disabled={submitting || !canManagePayouts || !withdrawAmount || Number(withdrawAmount) > availableBalance}
              className="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-60"
            >
              <ArrowUpRight className="h-4 w-4" />
              {submitting ? 'Memproses...' : 'Request withdrawal'}
            </button>
          </form>

          <div className="mt-8 rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
            <h3 className="text-sm font-bold uppercase tracking-wide text-zinc-700">Antrean withdrawal terbaru</h3>
            <div className="mt-4 space-y-3">
              {pendingWithdrawals.map((item) => (
                <div key={String(item.id)} className="flex items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white px-4 py-3">
                  <div>
                    <p className="text-sm font-semibold text-zinc-900">
                      {formatCurrency(Number(item.amount || 0))} ke {String(item.bank_code || '-')}
                    </p>
                    <p className="text-xs text-zinc-500">{String(item.created_at || '-')}</p>
                  </div>
                  <span className="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold capitalize text-zinc-700">
                    {String(item.status || 'pending')}
                  </span>
                </div>
              ))}
              {pendingWithdrawals.length === 0 && (
                <div className="rounded-2xl border border-dashed border-zinc-300 bg-white px-4 py-4 text-sm text-zinc-500">
                  Belum ada withdrawal yang sedang diproses.
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
    </div>
  );
}
