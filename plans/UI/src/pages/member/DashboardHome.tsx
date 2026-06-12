import { useEffect, useState } from 'react';
import { 
  Layout, ShoppingCart, ArrowRight, Lock, Users, 
  TrendingUp, DollarSign, Smartphone, Store, Wallet, 
  Plus, Bell, Clock, CreditCard, Tag, Sparkles
} from 'lucide-react';
import { Link } from 'react-router-dom';
import SubscriptionModal from '@/components/SubscriptionModal';
import TopUpModal from '@/components/TopUpModal';
import OnboardingTips from '@/components/consumer/OnboardingTips';
import { PROMOS } from '@/lib/mockData';
import { cn } from '@/lib/utils';
import { createWalletTopupSession, getAutoRenewPreview, getCatalogApps, getLandingBuilderStats, getPaymentGatewayStatus, getWalletOverview, getWalletTransactions } from '@/lib/hellomApi';

export default function DashboardHome() {
  const [stats, setStats] = useState({
    visitors: 0,
    sales: 0,
    revenue: 0
  });

  const [walletBalance, setWalletBalance] = useState(500000);
  const [walletPending, setWalletPending] = useState(0);
  const [walletError, setWalletError] = useState<string | null>(null);
  const [gatewayStatus, setGatewayStatus] = useState<{
    mode: 'sandbox' | 'production';
    is_ready: boolean;
    member_wallet_enabled: boolean;
    active_provider: 'xendit' | 'ipaymu';
  } | null>(null);
  const [historyRows, setHistoryRows] = useState<Array<{ id: string; item: string; date: string; amount: number; status: string; nextBilling: string }>>([]);
  const [autoRenewInfo, setAutoRenewInfo] = useState({
    dueCount: 0,
    minimumTopupRequired: 0,
  });
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isTopUpOpen, setIsTopUpOpen] = useState(false);
  const [selectedApp, setSelectedApp] = useState<{ name: string; icon: any; slug?: string } | null>(null);
  const [lockedApps, setLockedApps] = useState<Array<{ slug: string; name: string; price: number }>>([]);

  const loadWallet = async () => {
    try {
      const [overview, preview, landingStats, transactions, catalog, gateway] = await Promise.all([
        getWalletOverview(),
        getAutoRenewPreview({ days: 30, include_overdue: true, limit: 50 }),
        getLandingBuilderStats(),
        getWalletTransactions({ limit: 10 }),
        getCatalogApps().catch(() => ({ items: [] as any[] })),
        getPaymentGatewayStatus().catch(() => null),
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
          item: String(item.type || 'wallet_transaction'),
          date: String(item.created_at || '-').split('T')[0],
          amount: Number(item.amount || 0),
          status: String(item.direction) === 'credit' ? 'Success' : 'Processed',
          nextBilling: '-',
        }))
      );
      setLockedApps(
        (catalog.items || [])
          .filter((item: any) => !item.entitlement?.allowed)
          .map((item: any) => ({
            slug: String(item.app?.slug || ''),
            name: String(item.app?.name || 'App'),
            price: Number(item.cta?.recommended_plan?.price || 0),
          }))
      );
      setWalletError(null);
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message : 'Gagal memuat wallet';
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
      setWalletError('Fitur wallet/e-wallet sedang dimatikan oleh owner.');
      return;
    }
    setIsTopUpOpen(true);
  };

  const handleTopUp = async (payload: { amount: number; channel: string }) => {
    if (!gatewayStatus?.member_wallet_enabled) {
      throw new Error('Fitur wallet/e-wallet sedang dimatikan oleh owner.');
    }

    const providerLabel = gatewayStatus?.active_provider === 'ipaymu' ? 'iPaymu' : 'Xendit';
    if (!gatewayStatus?.is_ready) {
      throw new Error(`Gateway ${providerLabel} belum siap. Minta owner atau super admin melengkapi kredensialnya terlebih dahulu.`);
    }

    const result = await createWalletTopupSession({
      amount: payload.amount,
      channel: payload.channel,
    });

    if (!result.payment_url) {
      throw new Error(`Link pembayaran ${providerLabel} tidak berhasil dibuat.`);
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
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Dashboard Organisasi</h1>
          <p className="text-zinc-600">Overview performa bisnis dan status langganan Anda.</p>
        </div>
        {gatewayStatus?.member_wallet_enabled && (
        <div className="flex items-center gap-3">
          <div className="bg-white px-4 py-2 rounded-lg border border-zinc-200 shadow-sm flex items-center gap-3">
            <div className="p-1.5 bg-yellow-100 text-yellow-700 rounded-md">
              <Wallet className="w-4 h-4" />
            </div>
            <div>
              <p className="text-xs text-zinc-500 font-medium">Wallet Balance</p>
              <p className="text-sm font-bold text-zinc-900">Rp {walletBalance.toLocaleString('id-ID')}</p>
              <p className="text-[11px] text-zinc-400">Pending: Rp {walletPending.toLocaleString('id-ID')}</p>
            </div>
            <button 
              onClick={handleDeposit}
              className="ml-2 p-1.5 bg-zinc-900 text-white rounded-md hover:bg-zinc-800 transition-colors"
              title="Deposit Saldo"
            >
              <Plus className="w-4 h-4" />
            </button>
          </div>
        </div>
        )}
      </div>

      {/* Promos & Alerts */}
      {walletError && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {walletError}
        </div>
      )}
      {!gatewayStatus?.member_wallet_enabled && (
        <div className="p-3 rounded-lg bg-amber-50 border border-amber-100 text-sm text-amber-800">
          Owner sedang mematikan fitur wallet/e-wallet member. Checkout aplikasi berbayar tetap bisa lanjut lewat pembayaran langsung.
        </div>
      )}

      {PROMOS.length > 0 && (
        <div className="grid gap-4">
          {PROMOS.map((promo) => (
            <div 
              key={promo.id} 
              className={cn(
                "p-4 rounded-xl border flex items-start gap-4",
                promo.type === 'offer' 
                  ? "bg-gradient-to-r from-purple-50 to-white border-purple-100" 
                  : "bg-blue-50 border-blue-100"
              )}
            >
              <div className={cn(
                "p-2 rounded-lg shrink-0",
                promo.type === 'offer' ? "bg-purple-100 text-purple-600" : "bg-blue-100 text-blue-600"
              )}>
                {promo.type === 'offer' ? <Tag className="w-5 h-5" /> : <Bell className="w-5 h-5" />}
              </div>
              <div>
                <h3 className={cn("font-bold text-sm", promo.type === 'offer' ? "text-purple-900" : "text-blue-900")}>
                  {promo.title}
                </h3>
                <p className={cn("text-sm mt-1", promo.type === 'offer' ? "text-purple-700" : "text-blue-700")}>
                  {promo.description}
                </p>
              </div>
            </div>
          ))}
        </div>
      )}

      {lockedApps.length > 0 && (
        <div className="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 via-white to-orange-50 p-5 shadow-sm">
          <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
              <div className="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">
                <Sparkles className="w-3.5 h-3.5" />
                Akses premium terkunci
              </div>
              <h3 className="mt-3 text-lg font-bold text-zinc-900">Buka aplikasi berbayar langsung dari dashboard</h3>
              <p className="mt-1 text-sm text-zinc-600">
                {lockedApps[0].name} masih terkunci. Klik tombol aktivasi untuk melihat promo dan form pembayaran langsung.
              </p>
            </div>
            <button
              onClick={() => handleOpenModal(lockedApps[0].name, ShoppingCart, lockedApps[0].slug)}
              className="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-900 px-5 py-3 text-sm font-bold text-white transition-colors hover:bg-zinc-800"
            >
              Aktifkan {lockedApps[0].name} <ArrowRight className="w-4 h-4" />
            </button>
          </div>
          {lockedApps[0].price > 0 && (
            <p className="mt-3 text-xs text-zinc-500">
              Harga mulai dari Rp {lockedApps[0].price.toLocaleString('id-ID')} dan bisa mengikuti promo/konfirmasi owner.
            </p>
          )}
        </div>
      )}

      {/* Business Performance Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center gap-3 mb-2">
            <div className="p-2 bg-blue-100 text-blue-600 rounded-lg">
              <Users className="w-5 h-5" />
            </div>
            <span className="text-sm font-medium text-zinc-500">Total Visitors</span>
          </div>
          <p className="text-2xl font-bold text-zinc-900">{stats.visitors.toLocaleString()}</p>
        </div>
        
        <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center gap-3 mb-2">
            <div className="p-2 bg-green-100 text-green-600 rounded-lg">
              <ShoppingCart className="w-5 h-5" />
            </div>
            <span className="text-sm font-medium text-zinc-500">Total Sales</span>
          </div>
          <p className="text-2xl font-bold text-zinc-900">{stats.sales.toLocaleString()}</p>
        </div>

        <div className="bg-white p-6 rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center gap-3 mb-2">
            <div className="p-2 bg-yellow-100 text-yellow-600 rounded-lg">
              <DollarSign className="w-5 h-5" />
            </div>
            <span className="text-sm font-medium text-zinc-500">Total Revenue</span>
          </div>
          <p className="text-2xl font-bold text-zinc-900">Rp {stats.revenue.toLocaleString('id-ID')}</p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {/* Purchase History */}
        <div className="lg:col-span-2 bg-white rounded-xl border border-zinc-200 shadow-sm overflow-hidden">
          <div className="px-6 py-4 border-b border-zinc-200 flex justify-between items-center">
            <h3 className="font-bold text-zinc-900 flex items-center gap-2">
              <Clock className="w-4 h-4" /> Riwayat Pembelian & Langganan
            </h3>
            <button className="text-sm text-zinc-500 hover:text-zinc-900">View All</button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-zinc-50 border-b border-zinc-200">
                <tr>
                  <th className="px-6 py-3 font-medium text-zinc-500">Item</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Date</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Amount</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Status</th>
                  <th className="px-6 py-3 font-medium text-zinc-500">Next Billing</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100">
                {historyRows.map((item) => (
                  <tr key={item.id} className="hover:bg-zinc-50">
                    <td className="px-6 py-4 font-medium text-zinc-900">{item.item}</td>
                    <td className="px-6 py-4 text-zinc-500">{item.date}</td>
                    <td className="px-6 py-4 font-mono text-zinc-600">
                      {item.amount > 0 ? `Rp ${item.amount.toLocaleString('id-ID')}` : 'Free'}
                    </td>
                    <td className="px-6 py-4">
                      <span className={cn(
                        "px-2 py-1 rounded text-xs font-medium",
                        item.status === 'Active' || item.status === 'Success' 
                          ? "bg-green-100 text-green-700" 
                          : "bg-zinc-100 text-zinc-600"
                      )}>
                        {item.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-zinc-500">{item.nextBilling}</td>
                  </tr>
                ))}
                {historyRows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-6 py-10 text-center text-zinc-500">Belum ada riwayat transaksi.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Quick Actions / Wallet Info */}
        {gatewayStatus?.member_wallet_enabled && (
        <div className="space-y-6">
          <div className="bg-zinc-900 text-white p-6 rounded-xl shadow-lg relative overflow-hidden">
            <div className="relative z-10">
              <h3 className="font-bold text-lg mb-1">Auto-Renewal</h3>
              <p className="text-zinc-400 text-sm mb-6">
                Pastikan saldo wallet mencukupi untuk perpanjangan otomatis layanan Anda.
              </p>
              <div className="flex items-center justify-between bg-zinc-800 p-3 rounded-lg mb-4">
                <span className="text-sm text-zinc-300">Status</span>
                <span className="text-sm font-bold text-green-400 flex items-center gap-1">
                  <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse" /> Active
                </span>
              </div>
              <div className="text-xs text-zinc-300 mb-4 space-y-1">
                <p>Due subscriptions: <strong>{autoRenewInfo.dueCount}</strong></p>
                <p>Minimum topup required: <strong>Rp {autoRenewInfo.minimumTopupRequired.toLocaleString('id-ID')}</strong></p>
              </div>
              <button 
                onClick={handleDeposit}
                className="w-full py-3 bg-yellow-400 text-black font-bold rounded-lg hover:bg-yellow-500 transition-colors flex items-center justify-center gap-2"
              >
                <Plus className="w-4 h-4" /> Top Up Wallet
              </button>
            </div>
            {/* Decoration */}
            <div className="absolute -top-10 -right-10 w-32 h-32 bg-yellow-400/10 rounded-full blur-2xl" />
            <div className="absolute bottom-0 right-0 p-4 opacity-10">
              <CreditCard className="w-24 h-24" />
            </div>
          </div>
        </div>
        )}
      </div>

      {/* Subscription Modal */}
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

      {/* Top Up Modal */}
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
