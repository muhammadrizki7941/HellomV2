import { useEffect, useState } from 'react';
import Overview from './landing-builder/Overview';
import Editor from './landing-builder/Editor';
import SellerBalance from './landing-builder/SellerBalance';
import ProductsPanel from './landing-builder/ProductsPanel';
import OrdersPanel from './landing-builder/OrdersPanel';
import CouponsPanel from './landing-builder/CouponsPanel';
import TrafficPanel from './landing-builder/TrafficPanel';
import ShopSettingsPanel from './landing-builder/ShopSettingsPanel';
import { EditorPreferenceProvider } from './landing-builder/editorPreference';
import { useSearchParams } from 'react-router-dom';
import { Layout, BarChart3, Users, RefreshCw, Wallet, Package, ReceiptText, TicketPercent, LineChart, Settings2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getLandingPageCustomers } from '@/lib/hellomApi';
import { useEditorChrome } from '@/contexts/editorChrome';

function CustomersPanel() {
  const [items, setItems] = useState<Array<Record<string, any>>>([]);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const loadCustomers = async () => {
    setLoading(true);
    setErrorMessage(null);
    try {
      const result = await getLandingPageCustomers();
      setItems((result.items as Array<Record<string, any>>) || []);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Gagal memuat data pelanggan landingpage');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadCustomers();
  }, []);

  return (
    <div className="max-w-6xl mx-auto space-y-5">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Data Pelanggan Landingpage</h1>
          <p className="text-zinc-600">Semua user yang mengisi form pendaftaran di landing page organisasi.</p>
        </div>
        <button
          onClick={() => void loadCustomers()}
          disabled={loading}
          className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 disabled:opacity-60"
        >
          <RefreshCw className={cn("h-4 w-4", loading && "animate-spin")} /> Refresh
        </button>
      </div>

      {errorMessage && (
        <div className="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">{errorMessage}</div>
      )}

      <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full divide-y divide-zinc-100 text-sm">
            <thead className="bg-zinc-50 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">
              <tr>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap">Waktu</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap">Nama</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden sm:table-cell">Nomor HP</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden md:table-cell">Email</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden lg:table-cell">Form</th>
                <th className="px-3 md:px-4 py-3 whitespace-nowrap hidden xl:table-cell">Data Tambahan</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {items.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-3 md:px-4 py-10 text-center text-zinc-500">
                    {loading ? 'Memuat data...' : 'Belum ada data pelanggan landingpage.'}
                  </td>
                </tr>
              ) : items.map((item) => {
                const fields = item.fields && typeof item.fields === 'object' ? item.fields as Record<string, unknown> : {};
                return (
                  <tr key={item.id} className="align-top hover:bg-zinc-50">
                    <td className="whitespace-nowrap px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-500">{item.created_at ? new Date(String(item.created_at)).toLocaleString('id-ID') : '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm font-semibold text-zinc-900">{item.name || fields.name || fields.nama || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-700 hidden sm:table-cell">{item.phone || fields.phone || fields.nomor_hp || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-700 hidden md:table-cell">{item.email || fields.email || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-700 hidden lg:table-cell">{item.form_title || item.landing_page?.title || '-'}</td>
                    <td className="px-3 md:px-4 py-3 text-xs md:text-sm text-zinc-600 hidden xl:table-cell">
                      <div className="max-w-md space-y-1">
                        {Object.entries(fields).slice(0, 2).map(([key, value]) => (
                          <div key={key}><span className="font-semibold">{key}:</span> {String(value).slice(0, 50)}</div>
                        ))}
                        {Object.keys(fields).length > 2 && <div className="text-xs text-zinc-400">+{Object.keys(fields).length - 2} more</div>}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

type Tab = 'overview' | 'produk' | 'pesanan' | 'kupon' | 'editor' | 'customers' | 'saldo' | 'statistik' | 'pengaturan';

const TABS: Array<{ key: Tab; label: string; icon: typeof Layout }> = [
  { key: 'overview', label: 'Overview', icon: BarChart3 },
  { key: 'produk', label: 'Produk', icon: Package },
  { key: 'pesanan', label: 'Pesanan', icon: ReceiptText },
  { key: 'editor', label: 'Editor', icon: Layout },
  { key: 'kupon', label: 'Kupon', icon: TicketPercent },
  { key: 'customers', label: 'Pelanggan', icon: Users },
  { key: 'saldo', label: 'Saldo', icon: Wallet },
  { key: 'statistik', label: 'Statistik', icon: LineChart },
  { key: 'pengaturan', label: 'Pengaturan', icon: Settings2 },
];

export default function LandingBuilder() {
  // ?tab=saldo|pesanan|produk|kupon opens that tab directly (links from emails).
  const [searchParams, setSearchParams] = useSearchParams();
  const requested = searchParams.get('tab') as Tab | null;
  const [activeTab, setActiveTabState] = useState<Tab>(requested && TABS.some((t) => t.key === requested) ? requested : 'overview');
  // In-app links (?tab=saldo&rekening=1 from the checklist) switch tabs without a remount.
  useEffect(() => {
    if (requested && TABS.some((t) => t.key === requested)) setActiveTabState(requested);
  }, [requested]);
  const setActiveTab = (tab: Tab) => {
    setActiveTabState(tab);
    setSearchParams(tab === 'overview' ? {} : { tab }, { replace: true });
  };
  const { chromeHidden, setChromeHidden } = useEditorChrome();

  // Auto-hide the dashboard chrome (mobile header + these tabs) while editing,
  // so the editor gets the full screen. Restore it when leaving the editor.
  // chromeHidden only has a visible effect on mobile widths.
  useEffect(() => {
    setChromeHidden(activeTab === 'editor');
    return () => setChromeHidden(false);
  }, [activeTab, setChromeHidden]);

  return (
    // Asks "Sebelumnya pakai apa?" once and gives every tab the editor preset.
    <EditorPreferenceProvider>
    <div className={cn(chromeHidden ? "space-y-0 lg:space-y-6" : "space-y-6")}>
      {/* App Header & Tabs */}
      <div className={cn(
        "items-center justify-between border-b border-zinc-200 pb-1",
        chromeHidden ? "hidden lg:flex" : "flex"
      )}>
        <div className="-mx-1 flex gap-6 overflow-x-auto px-1">
          {TABS.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setActiveTab(key)}
              className={cn(
                "flex min-h-11 shrink-0 items-center gap-2 pb-3 text-sm font-medium border-b-2 transition-colors",
                activeTab === key
                  ? "border-yellow-400 text-black"
                  : "border-transparent text-zinc-500 hover:text-zinc-900"
              )}
            >
              <Icon className="w-4 h-4" />
              {label}
            </button>
          ))}
        </div>
      </div>

      {/* Content */}
      {activeTab === 'editor' ? (
        /* Full-bleed on mobile/tablet: cancel DashboardLayout p-4 horizontally.
           The dashboard uses the mobile chrome (fixed header + off-canvas sidebar, p-4)
           up to the lg breakpoint, so the editor stays full-bleed up to lg too.
           Height = viewport minus: mobile-header(80px) + tab-bar(~52px) + space-y-6(24px) + bottom-safe(~8px) */
        <div
          className={cn(
            "-mx-4 lg:mx-0 overflow-hidden",
            // When chrome is hidden the mobile header + tabs are gone, so the
            // editor can fill almost the whole screen. Desktop height is unchanged.
            chromeHidden
              ? "h-[calc(100svh-16px)] lg:h-[calc(100svh-164px)]"
              : "h-[calc(100svh-164px)]"
          )}
        >
          <Editor />
        </div>
      ) : (
        <div className="min-h-[600px]">
          {activeTab === 'overview' && <Overview onEdit={() => setActiveTab('editor')} onOpenOrders={() => setActiveTab('pesanan')} onOpenProducts={() => setActiveTab('produk')} onOpenSettings={() => setActiveTab('pengaturan')} />}
          {activeTab === 'produk' && <ProductsPanel />}
          {activeTab === 'pesanan' && <OrdersPanel />}
          {activeTab === 'kupon' && <CouponsPanel />}
          {activeTab === 'saldo' && <SellerBalance />}
          {activeTab === 'customers' && <CustomersPanel />}
          {activeTab === 'statistik' && <TrafficPanel />}
          {activeTab === 'pengaturan' && <ShopSettingsPanel onOpenEditor={() => setActiveTab('editor')} />}
        </div>
      )}
    </div>
    </EditorPreferenceProvider>
  );
}
