import { useCallback, useEffect, useState } from 'react';
import { Outlet, NavLink, Link, useLocation, useNavigate } from 'react-router-dom';
import { LayoutDashboard, Layout, ShoppingCart, LogOut, User, CreditCard, Menu, X, Lock, Settings, Sparkles, ArrowRight, Grid, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clearSession, getAuthMe, getMemberDashboardCards, getPaymentGatewayStatus, getSessionEventName, getSessionUser, getToken, logout, setSession } from '@/lib/hellomApi';
import { BRAND_LOGO_PATH, BRAND_NAME, getBrandLogo } from '@/lib/branding';
import { fetchBrand, BrandSettings } from '@/hooks/useBrand';
import SubscriptionModal from '@/components/SubscriptionModal';
import NotificationBell from '@/components/consumer/NotificationBell';

type DashboardApp = {
  id: string;
  name: string;
  path: string;
  isLocked: boolean;
  icon: typeof Layout;
};

type DashboardCard = {
  app: {
    slug: string;
    name: string;
  };
  entitlement: {
    allowed: boolean;
  };
  access?: {
    dashboard_path?: string | null;
  } | null;
};

const FALLBACK_APPS: DashboardApp[] = [
  {
    id: 'landing_builder',
    name: 'Landing Builder',
    path: '/dashboard/apps/landing-builder',
    isLocked: false,
    icon: Layout,
  },
];

export default function DashboardLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [apps, setApps] = useState<DashboardApp[]>(FALLBACK_APPS);
  const [userName, setUserName] = useState('Member User');
  const [userEmail, setUserEmail] = useState('member@hellom.id');
  const [lockedAppPrompt, setLockedAppPrompt] = useState<DashboardApp | null>(null);
  const [subscriptionApp, setSubscriptionApp] = useState<DashboardApp | null>(null);
  const [memberWalletEnabled, setMemberWalletEnabled] = useState(false);
  const [activeGatewayLabel, setActiveGatewayLabel] = useState('Xendit');
  const [brand, setBrand] = useState<BrandSettings | null>(null);

  const loadCards = useCallback(async () => {
    try {
      const response = await getMemberDashboardCards();
      const mapped: DashboardApp[] = ((response.cards || []) as DashboardCard[]).map((card) => ({
        id: card.app.slug,
        name: card.app.name,
        path: card.access?.dashboard_path
          || (card.app.slug === 'landing_builder'
            ? '/dashboard/apps/landing-builder'
            : card.app.slug === 'pos'
              ? '/dashboard/apps/pos'
              : '#'),
        isLocked: !card.entitlement.allowed || !(card.access?.dashboard_path || card.app.slug === 'landing_builder' || card.app.slug === 'pos'),
        icon: card.app.slug === 'pos' ? ShoppingCart : Layout,
      }));
      if (mapped.length > 0) {
        setApps(mapped);
      }
    } catch {
    }
  }, []);

  useEffect(() => {
    // Fetch brand settings on mount (use cache if available)
    fetchBrand().then(setBrand).catch(() => {
      // Use default brand if fetch fails
      setBrand(null);
    });
  }, []);

  useEffect(() => {
    let isMounted = true;
    let refreshing = false;

    const syncSession = async () => {
      const hasToken = Boolean(getToken());
      const sessionUser = getSessionUser<{ name?: string; email?: string; role?: string }>();

      if (hasToken && !sessionUser && !refreshing) {
        refreshing = true;
        try {
          const me = await getAuthMe();
          const token = getToken();
          if (token && isMounted) {
            setSession(token, me);
          }
        } catch {
        } finally {
          refreshing = false;
        }
      }

      const sessionUserAfterRefresh = getSessionUser<{ name?: string; email?: string; role?: string }>();
      if (!hasToken || !sessionUserAfterRefresh) {
        navigate('/login', { replace: true });
        return;
      }

      if (sessionUserAfterRefresh.role === 'super_admin') {
        navigate('/admin', { replace: true });
        return;
      }

      if (sessionUserAfterRefresh.name) setUserName(sessionUserAfterRefresh.name);
      if (sessionUserAfterRefresh.email) setUserEmail(sessionUserAfterRefresh.email);
    };

    syncSession();
    window.addEventListener(getSessionEventName(), syncSession as EventListener);

    return () => {
      isMounted = false;
      window.removeEventListener(getSessionEventName(), syncSession);
    };
  }, [navigate]);

  useEffect(() => {
    const sessionUser = getSessionUser<{ name?: string; email?: string; role?: string }>();
    const isSuperAdmin = sessionUser?.role === 'super_admin';
    if (isSuperAdmin) {
      navigate('/admin', { replace: true });
      return;
    }

    if (sessionUser?.name) setUserName(sessionUser.name);
    if (sessionUser?.email) setUserEmail(sessionUser.email);

    void loadCards();
    void getPaymentGatewayStatus()
      .then((status) => {
        setMemberWalletEnabled(Boolean(status.member_wallet_enabled));
        setActiveGatewayLabel(
          status.active_provider === 'ipaymu'
            ? 'iPaymu'
            : status.active_provider === 'doku'
              ? 'DOKU'
              : 'Xendit'
        );
      })
      .catch(() => {
        setMemberWalletEnabled(false);
      });
  }, [loadCards, navigate]);

  useEffect(() => {
    const params = new URLSearchParams(location.search);
    if (location.pathname !== '/dashboard/apps/pos' || params.get('subscribe') !== '1') {
      return;
    }

    const posApp = apps.find((app) => app.id === 'pos') || {
      id: 'pos',
      name: 'POS',
      path: '/dashboard/apps/pos',
      isLocked: true,
      icon: ShoppingCart,
    };

    setSubscriptionApp(posApp);
  }, [apps, location.pathname, location.search]);

  const handleLogout = async () => {
    try {
      await logout();
    } catch {
    } finally {
      clearSession();
      navigate('/login', { replace: true });
    }
  };

  return (
    <div className="min-h-screen bg-zinc-50 flex font-sans text-zinc-900 selection:bg-yellow-400 selection:text-black">
      {/* Mobile Header */}
      <div className="lg:hidden fixed top-0 left-0 right-0 h-16 bg-white border-b border-zinc-200 flex items-center justify-between px-4 z-30">
        <Link to="/" className="flex items-center gap-2">
          <img src={getBrandLogo(brand?.logo_url)} alt={brand?.app_name || BRAND_NAME} draggable={false} loading="lazy" className="w-8 h-8 rounded-lg object-cover border border-zinc-200" />
          <span className="text-xl font-bold tracking-tight">{brand?.app_name || BRAND_NAME}</span>
        </Link>
        <div className="flex items-center gap-2">
          <NotificationBell />
          <button onClick={() => setIsSidebarOpen(!isSidebarOpen)} className="p-2 text-zinc-600">
            {isSidebarOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
          </button>
        </div>
      </div>

      {/* Sidebar Overlay (Mobile) */}
      {isSidebarOpen && (
        <div 
          className="fixed inset-0 bg-black/50 z-20 lg:hidden"
          onClick={() => setIsSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside className={cn(
        "fixed top-0 bottom-0 left-0 w-64 bg-white border-r border-zinc-200 flex flex-col z-30 transition-transform duration-300 ease-in-out lg:translate-x-0 pt-16 lg:pt-0",
        isSidebarOpen ? "translate-x-0" : "-translate-x-full"
      )}>
          <div className="hidden lg:flex p-6 border-b border-zinc-100">
          <Link to="/" className="flex items-center gap-2">
            <img src={getBrandLogo(brand?.logo_url)} alt={brand?.app_name || BRAND_NAME} draggable={false} loading="lazy" className="w-8 h-8 rounded-lg object-cover border border-zinc-200" />
            <span className="text-xl font-bold tracking-tight">{brand?.app_name || BRAND_NAME}</span>
          </Link>
        </div>
        
        <nav className="flex-1 p-4 space-y-1 overflow-y-auto">
          <NavLink 
            to="/dashboard" 
            end
            onClick={() => setIsSidebarOpen(false)}
            className={({ isActive }) => cn(
              "flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors",
              isActive 
                ? "bg-black text-white shadow-md shadow-black/10" 
                : "text-zinc-600 hover:bg-zinc-50 hover:text-black"
            )}
          >
            <LayoutDashboard className="w-5 h-5" />
            Dashboard
          </NavLink>

          <NavLink 
            to="/dashboard/profile" 
            onClick={() => setIsSidebarOpen(false)}
            className={({ isActive }) => cn(
              "flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors",
              isActive 
                ? "bg-black text-white shadow-md shadow-black/10" 
                : "text-zinc-600 hover:bg-zinc-50 hover:text-black"
            )}
          >
            <Settings className="w-5 h-5" />
            Pengaturan Akun
          </NavLink>

          <NavLink 
            to="/dashboard/products" 
            onClick={() => setIsSidebarOpen(false)}
            className={({ isActive }) => cn(
              "flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors",
              isActive 
                ? "bg-black text-white shadow-md shadow-black/10" 
                : "text-zinc-600 hover:bg-zinc-50 hover:text-black"
            )}
          >
            <Grid className="w-5 h-5" />
            Katalog Produk
          </NavLink>

          <NavLink 
            to="/dashboard/my-purchases" 
            onClick={() => setIsSidebarOpen(false)}
            className={({ isActive }) => cn(
              "flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors",
              isActive 
                ? "bg-black text-white shadow-md shadow-black/10" 
                : "text-zinc-600 hover:bg-zinc-50 hover:text-black"
            )}
          >
            <Package className="w-5 h-5" />
            Produk Saya
          </NavLink>

          {memberWalletEnabled && (
            <NavLink 
              to="/dashboard/payments" 
              onClick={() => setIsSidebarOpen(false)}
              className={({ isActive }) => cn(
                "flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors",
                isActive 
                  ? "bg-black text-white shadow-md shadow-black/10" 
                  : "text-zinc-600 hover:bg-zinc-50 hover:text-black"
              )}
            >
              <CreditCard className="w-5 h-5" />
              Payments
            </NavLink>
          )}
          
          <div className="pt-4 pb-2 px-4 text-xs font-bold text-zinc-400 uppercase tracking-wider">
            Aplikasi
          </div>
          
          {apps.map((app) => (
            <NavLink 
              key={app.id}
              to={app.isLocked ? '#' : app.path}
              onClick={(e) => {
                if (app.id === 'pos' && app.isLocked) {
                  e.preventDefault();
                  setLockedAppPrompt(app);
                } else if (app.isLocked) {
                  e.preventDefault();
                  setLockedAppPrompt(app);
                }
                setIsSidebarOpen(false);
              }}
              className={({ isActive }) => cn(
                "flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors group relative",
                isActive && !app.isLocked
                  ? "bg-black text-white shadow-md shadow-black/10" 
                  : "text-zinc-600 hover:bg-zinc-50 hover:text-black",
                app.isLocked && "opacity-75 cursor-not-allowed hover:bg-transparent hover:text-zinc-600"
              )}
            >
              <app.icon className="w-5 h-5" />
              <span className="flex-1">{app.name}</span>
              {app.isLocked && <Lock className="w-3.5 h-3.5 text-zinc-400" />}
            </NavLink>
          ))}
        </nav>

        <div className="p-4 border-t border-zinc-100">
          <div className="flex items-center gap-2 px-4 py-3">
            <Link to="/dashboard/profile" className="flex min-w-0 flex-1 items-center gap-3 rounded-lg transition-colors group hover:bg-zinc-50">
              <div className="w-8 h-8 rounded-full bg-zinc-100 flex items-center justify-center text-zinc-500 border border-zinc-200 group-hover:border-yellow-400 group-hover:text-yellow-600 transition-colors">
                <User className="w-4 h-4" />
              </div>
              <div className="flex-1 min-w-0 text-left">
                <p className="text-sm font-bold text-zinc-900 truncate">{userName}</p>
                <p className="text-xs text-zinc-500 truncate">{userEmail}</p>
              </div>
            </Link>
            <button onClick={() => void handleLogout()} className="text-zinc-400 hover:text-red-600 transition-colors p-1" title="Logout">
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main Content */}
      <main className="flex-1 lg:ml-64 p-4 lg:p-8 pt-20 lg:pt-8 transition-all duration-300">
        <div className="max-w-7xl mx-auto">
          <div className="hidden lg:flex mb-6 items-center justify-between rounded-3xl border border-zinc-200 bg-white px-5 py-4 shadow-sm">
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Member Workspace</p>
              <p className="mt-1 text-sm text-zinc-700">Pantau pembelian, aplikasi aktif, dan notifikasi terbaru dari header atas ini.</p>
            </div>
            <div className="flex items-center gap-4">
              <NotificationBell />
              <div className="text-right">
                <p className="text-sm font-semibold text-zinc-900">{userName}</p>
                <p className="text-xs text-zinc-500">{userEmail}</p>
              </div>
            </div>
          </div>
          <Outlet />
        </div>
      </main>

      {lockedAppPrompt && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/55 p-4 backdrop-blur-sm">
          <div className="w-full max-w-lg rounded-3xl border border-zinc-200 bg-white p-6 shadow-2xl">
            <div className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">
              <Sparkles className="h-3.5 w-3.5" />
              Promo aktivasi
            </div>
            <h3 className="mt-4 text-2xl font-bold text-zinc-950">Buka akses {lockedAppPrompt.name}</h3>
            <p className="mt-3 text-sm leading-6 text-zinc-600">
              Aplikasi ini masih terkunci. Aktifkan sekarang untuk membuka akses penuh, dapatkan info promo yang sedang berjalan, dan lanjutkan pembayaran sesuai mode owner dashboard.
            </p>

            <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
              <p className="text-sm font-semibold text-zinc-900">Yang akan Anda dapatkan</p>
              <ul className="mt-3 space-y-2 text-sm text-zinc-600">
                <li>Checkout langsung dari dashboard tanpa keluar halaman</li>
                <li>Promo langganan tampil sebelum pembayaran</li>
                <li>Mode pembayaran mengikuti setting owner: manual atau otomatis/{activeGatewayLabel}</li>
              </ul>
            </div>

            <div className="mt-6 flex gap-3">
              <button
                onClick={() => setLockedAppPrompt(null)}
                className="flex-1 rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
              >
                Nanti dulu
              </button>
              <button
                onClick={() => {
                  setSubscriptionApp(lockedAppPrompt);
                  setLockedAppPrompt(null);
                }}
                className="flex-1 inline-flex items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
              >
                Aktifkan sekarang <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
      )}

      {subscriptionApp && (
        <SubscriptionModal
          isOpen={Boolean(subscriptionApp)}
          onClose={() => setSubscriptionApp(null)}
          appName={subscriptionApp.name}
          appSlug={subscriptionApp.id}
          appIcon={subscriptionApp.icon}
          onSuccess={() => {
            void loadCards();
          }}
        />
      )}
    </div>
  );
}
