import { useEffect, useState } from 'react';
import {
  ShoppingCart, ArrowRight,
  DollarSign, Users, Wallet,
  Plus, Bell, Clock, CreditCard, Sparkles, Tag
} from 'lucide-react';
import { Link } from 'react-router-dom';
import SubscriptionModal from '@/components/SubscriptionModal';
import TopUpModal from '@/components/TopUpModal';
import OnboardingTips from '@/components/consumer/OnboardingTips';
import { cn } from '@/lib/utils';
import {
  createWalletTopupSession,
  getAutoRenewPreview,
  getCatalogApps,
  getConsumerNotifications,
  getLandingBuilderStats,
  getPaymentGatewayStatus,
  getWalletOverview,
  getWalletTransactions,
} from '@/lib/hellomApi';

type Announcement = {
  id: number | string;
  title?: string;
  message?: string;
  body?: string;
  type?: string;
};

const txTypeLabel = (type: string) => {
  const map: Record<string, string> = {
    wallet_topup: 'Top Up Saldo',
    subscription_debit: 'Pembayaran Langganan',
    wallet_debit: 'Pembayaran',
    wallet_credit: 'Kredit Saldo',
    subscription_renewal: 'Perpanjangan Langganan',
    refund: 'Refund',
    manual_topup: 'Top Up Manual',
  };
  return map[type] || type.replace(/_/g, ' ');
};

export default function DashboardHome() {
  const [stats, setStats] = useState({ visitors: 0, sales: 0, revenue: 0 });
  const [walletBalance, setWalletBalance] = useState(0);
  const [walletPending, setWalletPending] = useState(0);
  const [walletError, setWalletError] = useState<string | null>(null);
  const [gatewayStatus, setGatewayStatus] = useState<{
    mode: 'sandbox' | 'production';
    is_ready: boolean;
    member_wallet_enabled: boolean;
    active_provider: 'xendit' | 'ipaymu';
  } | null>(null);
  const [historyRows, setHistoryRows] = useState<
    Array<{ id: string; item: string; date: string; amount: number; status: string; direction: string }>
  >([]);
  const [autoRenewInfo, setAutoRenewInfo] = useState({ dueCount: 0, minimumTopupRequired: 0 });
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isTopUpOpen, setIsTopUpOpen] = useState(false);
  const [selectedApp, setSelectedApp] = useState<{ name: string; icon: any; slug?: string } | null>(null);
  const [lockedApps, setLockedApps] = useState<Array<{ slug: string; name: string; price: number }>>([]);
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);

  const loadWallet = async () => {
    try {
      const [overview, preview, landingStats, transactions, catalog, gateway, notifs] = await Promise.all([
        getWalletOverview(),
        getAutoRenewPreview({ days: 30, include_overdue: true, limit: 50 }),
        getLandingBuilderStats(),
        getWalletTransactions({ limit: 10 }),
        getCatalogApps().catch(() => ({ items: [] as any[] })),
        getPaymentGatewayStatus().catch(() => null),
        getConsumerNotifications().catch(() => null),
      ]);

      setWalletBalance(overview.wallet.available_balance || 0);
      setWalletPending(overview.wallet.pending_balance || 0);

      setAutoRenewInfo({
        dueCount: preview.summary.total_due_count,
        minimumTopupRequired: preview.summary.minimum_topup_required,
      });

      const items = transactions.items || [];
      const creditItems = items.filter((item) => String(item.direction) === 'credit');
      const totalRevenue = creditItems.reduce((acc, item) => acc + Number(item.amount || 0), 0);

      setStats({
        visitors: landingStats.views_count || 0,
        sales: creditItems.length,
        revenue: totalRevenue,
      });

      setGatewayStatus(
        gateway
          ? {
              mode: (gateway.mode as 'sandbox' | 'production') ?? 'sandbox',
              is_ready: Boolean(gateway.is_ready),
              member_wallet_enabled: Boolean(gateway.member_wallet_enabled),
              active_provider: (gateway.active_provider as 'xendit' | 'ipaymu') ?? 'xendit',
            }
          : null
      );

      setHistoryRows(
        items.slice(0, 8).map((item) => ({
          id: String(item.id),
          item: txTypeLabel(String(item.type || 'transaksi')),
          date: String(item.created_at || '-').split('T')[0],
          amount: Number(item.amount || 0),
          status: String(item.direction) === 'credit' ? 'Masuk' : 'Keluar',
          direction: String(item.direction),
        }))
      );

      setLockedApps(
        (catalog.items || [])
          .filter((item: any) => !item.entitlement?.allowed)
          .map((item: any) => ({
            slug: String(item.app?.slug || ''),
            name: String(item.app?.name || 'Aplikasi'),
            price: Number(item.cta?.recommended_plan?.price || 0),
          }))
      );

      // Load real announcements from backend
      if (notifs) {
        const raw = notifs as any;
        const list = Array.isArray(raw)
          ? raw
          : (raw?.data || raw?.notifications || raw?.items || []);
        setAnnouncements(list as Announcement[]);
      }

      setWalletError(null);
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat data';
      setWalletError(message);
    }
  };

  useEffect(() => {
    void loadWallet();
  }, []);

  const handleOpenModal = (appName: string, appIcon: any, appSlug?: string) => {
    setSelectedApp({ name: appName, icon: appIcon, slug: appSlug });
    setIsModalOpen(true);
  };

  const handleDeposit = () => {
    if (!gatewayStatus?.member_wallet_enabled) {
      setWalletError('Fitur wallet sedang dinonaktifkan oleh penyedia layanan.');
      return;
    }
    setIsTopUpOpen(true);
  };

  const handleTopUp = async (payload: { amount: number; channel: string }) => {
    if (!gatewayStatus?.member_wallet_enabled) {
      throw new Error('Fitur wallet sedang tidak tersedia.');
    }
    if (!gatewayStatus?.is_ready) {
      throw new Error('Sistem pembayaran sedang dalam konfigurasi. Coba beberapa saat lagi.');
    }

    const result = await createWalletTopupSession({ amount: payload.amount, channel: payload.channel });

    if (!result.payment_url) {
      throw new Error('Halaman pembayaran tidak dapat dibuat. Coba lagi atau hubungi dukungan.');
    }

    window.open(result.payment_url, '_blank', 'noopener,noreferrer');
    await loadWallet();

    return {
      referenceId: result.reference_id,
      paymentUrl: result.payment_url,
      channel: result.channel,
      amount: result.amount,
    };
  };

  return (
    <div className="space-y-8">
      <OnboardingTips />

      <div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Dashboard</h1>
          <p className="text-zinc-500">Ringkasan bisnis dan status langganan Anda.</p>
        </div>
        {gatewayStatus?.member_wallet_enabled && (
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white px-4 py-2 shadow-sm">
              <div className="rounded-md bg-yellow-100 p-1.5 text-yellow-700">
                <Wallet className="h-4 w-4" />
              </div>
              <div>
                <p className="text-xs font-medium text-zinc-500">Saldo</p>
                <p className="text-sm font-bold text-zinc-900">Rp {walletBalance.toLocaleString('id-ID')}</p>
                {walletPending > 0 && (
                  <p className="text-[11px] text-zinc-400">
                    Pending: Rp {walletPending.toLocaleString('id-ID')}
                  </p>
                )}
              </div>
              <button
                onClick={handleDeposit}
                className="ml-2 rounded-md bg-zinc-900 p-1.5 text-white transition-colors hover:bg-zinc-800"
                title="Top Up Saldo"
              >
                <Plus className="h-4 w-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Error */}
      {walletError && (
        <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">
          {walletError}
        </div>
      )}

      {/* Wallet disabled notice */}
      {gatewayStatus !== null && !gatewayStatus.member_wallet_enabled && (
        <div className="rounded-lg border border-amber-100 bg-amber-50 p-3 text-sm text-amber-800">
          Pembayaran saldo digital sedang tidak tersedia. Anda tetap bisa mengaktifkan aplikasi melalui pembayaran langsung.
        </div>
      )}

      {/* Announcements — only shown when API returns data */}
      {announcements.length > 0 && (
        <div className="grid gap-3">
          {announcements.map((item) => {
            const isOffer = item.type === 'offer' || item.type === 'promo';
            return (
              <div
                key={item.id}
                className={cn(
                  'flex items-start gap-4 rounded-xl border p-4',
                  isOffer
                    ? 'border-purple-100 bg-gradient-to-r from-purple-50 to-white'
                    : 'border-blue-100 bg-blue-50'
                )}
              >
                <div
                  className={cn(
                    'shrink-0 rounded-lg p-2',
                    isOffer ? 'bg-purple-100 text-purple-600' : 'bg-blue-100 text-blue-600'
                  )}
                >
                  {isOffer ? <Tag className="h-5 w-5" /> : <Bell className="h-5 w-5" />}
                </div>
                <div>
                  {item.title && (
                    <h3
                      className={cn(
                        'text-sm font-bold',
                        isOffer ? 'text-purple-900' : 'text-blue-900'
                      )}
                    >
                      {item.title}
                    </h3>
                  )}
                  {(item.message || item.body) && (
                    <p
                      className={cn(
                        'mt-1 text-sm',
                        isOffer ? 'text-purple-700' : 'text-blue-700'
                      )}
                    >
                      {item.message || item.body}
                    </p>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Locked app upsell */}
      {lockedApps.length > 0 && (
        <div className="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 via-white to-orange-50 p-5 shadow-sm">
          <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
              <div className="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-amber-800">
                <Sparkles className="h-3.5 w-3.5" />
                Akses terkunci
              </div>
              <h3 className="mt-3 text-lg font-bold text-zinc-900">
                Buka {lockedApps[0].name}
              </h3>
              <p className="mt-1 text-sm text-zinc-600">
                Klik tombol aktivasi untuk melihat pilihan paket dan form pembayaran.
              </p>
            </div>
            <button
              onClick={() => handleOpenModal(lockedApps[0].name, ShoppingCart, lockedApps[0].slug)}
              className="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-900 px-5 py-3 text-sm font-bold text-white transition-colors hover:bg-zinc-800"
            >
              Aktifkan <ArrowRight className="h-4 w-4" />
            </button>
          </div>
          {lockedApps[0].price > 0 && (
            <p className="mt-3 text-xs text-zinc-500">
              Harga mulai dari Rp {lockedApps[0].price.toLocaleString('id-ID')}.
            </p>
          )}
        </div>
      )}

      {/* Stats */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        {[
          { label: 'Pengunjung Landing', value: stats.visitors.toLocaleString(), icon: Users, color: 'bg-blue-100 text-blue-600' },
          { label: 'Transaksi Masuk', value: stats.sales.toLocaleString(), icon: ShoppingCart, color: 'bg-green-100 text-green-600' },
          { label: 'Total Pendapatan', value: `Rp ${stats.revenue.toLocaleString('id-ID')}`, icon: DollarSign, color: 'bg-yellow-100 text-yellow-600' },
        ].map((stat) => (
          <div key={stat.label} className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div className="mb-2 flex items-center gap-3">
              <div className={`rounded-lg p-2 ${stat.color}`}>
                <stat.icon className="h-5 w-5" />
              </div>
              <span className="text-sm font-medium text-zinc-500">{stat.label}</span>
            </div>
            <p className="text-2xl font-bold text-zinc-900">{stat.value}</p>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
        {/* Transaction history */}
        <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm lg:col-span-2">
          <div className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
            <h3 className="flex items-center gap-2 font-bold text-zinc-900">
              <Clock className="h-4 w-4" /> Riwayat Transaksi
            </h3>
            <Link to="/dashboard/payments" className="text-sm text-zinc-500 hover:text-zinc-900">
              Lihat Semua
            </Link>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-zinc-200 bg-zinc-50">
                <tr>
                  <th className="px-6 py-3 font-medium text-zinc-500">Keterangan</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Tanggal</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Nominal</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100">
                {historyRows.map((item) => (
                  <tr key={item.id} className="hover:bg-zinc-50">
                    <td className="px-6 py-4 font-medium text-zinc-900">{item.item}</td>
                    <td className="px-6 py-4 text-zinc-500">{item.date}</td>
                    <td className={cn(
                      'px-6 py-4 font-mono text-sm font-medium',
                      item.direction === 'credit' ? 'text-emerald-700' : 'text-zinc-700'
                    )}>
                      {item.direction === 'credit' ? '+' : '-'}{' '}
                      {item.amount > 0 ? `Rp ${item.amount.toLocaleString('id-ID')}` : '-'}
                    </td>
                    <td className="px-6 py-4">
                      <span className={cn(
                        'rounded px-2 py-1 text-xs font-medium',
                        item.direction === 'credit'
                          ? 'bg-green-100 text-green-700'
                          : 'bg-zinc-100 text-zinc-600'
                      )}>
                        {item.status}
                      </span>
                    </td>
                  </tr>
                ))}
                {historyRows.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-6 py-10 text-center text-zinc-500">
                      Belum ada riwayat transaksi.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Auto-renewal card */}
        {gatewayStatus?.member_wallet_enabled && (
          <div className="space-y-6">
            <div className="relative overflow-hidden rounded-xl bg-zinc-900 p-6 text-white shadow-lg">
              <div className="relative z-10">
                <h3 className="text-lg font-bold">Perpanjangan Otomatis</h3>
                <p className="mb-6 mt-1 text-sm text-zinc-400">
                  Pastikan saldo mencukupi untuk perpanjangan layanan secara otomatis.
                </p>
                <div className="mb-4 flex items-center justify-between rounded-lg bg-zinc-800 p-3">
                  <span className="text-sm text-zinc-300">Status</span>
                  <span className="flex items-center gap-1 text-sm font-bold text-green-400">
                    <div className="h-2 w-2 animate-pulse rounded-full bg-green-400" /> Aktif
                  </span>
                </div>
                <div className="mb-4 space-y-1 text-xs text-zinc-300">
                  <p>
                    Langganan jatuh tempo:{' '}
                    <strong className="text-white">{autoRenewInfo.dueCount}</strong>
                  </p>
                  <p>
                    Top up minimum:{' '}
                    <strong className="text-white">
                      Rp {autoRenewInfo.minimumTopupRequired.toLocaleString('id-ID')}
                    </strong>
                  </p>
                </div>
                <button
                  onClick={handleDeposit}
                  className="flex w-full items-center justify-center gap-2 rounded-lg bg-yellow-400 py-3 font-bold text-black transition-colors hover:bg-yellow-500"
                >
                  <Plus className="h-4 w-4" /> Top Up Saldo
                </button>
              </div>
              <div className="absolute -top-10 -right-10 h-32 w-32 rounded-full bg-yellow-400/10 blur-2xl" />
              <div className="absolute bottom-0 right-0 p-4 opacity-10">
                <CreditCard className="h-24 w-24" />
              </div>
            </div>
          </div>
        )}
      </div>

      {selectedApp && (
        <SubscriptionModal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          appName={selectedApp.name}
          appSlug={selectedApp.slug}
          appIcon={selectedApp.icon}
          onSuccess={() => {
            void loadWallet();
          }}
        />
      )}

      {gatewayStatus?.member_wallet_enabled && (
        <TopUpModal
          isOpen={isTopUpOpen}
          onClose={() => setIsTopUpOpen(false)}
          currentBalance={walletBalance}
          onSubmitTopUp={handleTopUp}
        />
      )}
    </div>
  );
}
