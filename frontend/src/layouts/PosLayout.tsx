import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { LayoutDashboard, ShoppingCart, Users, BarChart3, Utensils, Settings, LogOut, Square, X, Menu, User, Ticket, Store, ShieldCheck, PanelLeftClose, PanelLeftOpen, Package, Smartphone, Wrench, BriefcaseBusiness, RefreshCw } from 'lucide-react';
import { useState, useEffect, useMemo, useRef } from 'react';
import BottomNav from '@/components/pos/BottomNav';
import OutletSwitcher from '@/components/pos/OutletSwitcher';
import { OwnerPinSetupDialog } from '@/components/pos/OwnerPin';
import { getActiveOutletEventName, getAuthMe, getPosMyAccess, getPosOwnerPinStatus, getPosOrders, getPosRealtimeToken, getSessionEventName, getSessionUser, getSessionPosAccess, getToken, isPosOfflineSessionExpired, POS_OFFLINE_MAX_SESSION_HOURS, setSession, setSessionPosAccess, clearSession, POS_PERMISSION_DENIED_EVENT } from '@/lib/hellomApi';
import { playOrderChime, setPosRealtimeConnected, subscribeRealtime } from '@/lib/realtime';
import { usePWAInstall } from '@/hooks/usePWAInstall';
import { clearSyncedPosCacheOnLogout, confirmPosContextChange, getPosOfflineScope, getPosSyncCounts } from '@/lib/posOfflineDb';
import { getPosSyncSnapshot, startPosSyncEngine, subscribePosSync, type PosSyncSnapshot } from '@/lib/posSyncEngine';
import {
  activatePwaUpdate,
  hasPwaUpdateAvailable,
  isPosTransactionInProgress,
  PWA_UPDATE_AVAILABLE_EVENT,
  POS_TRANSACTION_STATE_EVENT,
  setPosHasUnsyncedData,
} from '@/lib/pwa';
import { BRAND_LOGO_PATH } from '@/lib/branding';
import type { PosOwnerPinStatus } from '@/lib/hellomApi';
import {
  getPosOrderListResetAt,
  getPosOrderListResetEventName,
  isOrderVisibleAfterReset,
} from '@/lib/posOrderListReset';
import { BusinessProfileProvider, useBusinessProfile, useModule } from '@/hooks/useBusinessProfile';
import { usePermissions } from '@/hooks/usePermissions';
import type { PosBusinessType } from '@/services/api/pos';

const productIcons: Record<PosBusinessType['key'], typeof Package> = {
  fnb: Utensils,
  retail: Store,
  konter_hp: Smartphone,
  sparepart: Wrench,
  jasa: BriefcaseBusiness,
};

const sidebarNavigation = [
  { name: 'Dashboard', href: '/pos/admin-dashboard', icon: LayoutDashboard },
  { name: 'Outlet', href: '/pos/outlets', icon: Store },
  { name: 'Orders', href: '/pos/orders', icon: ShoppingCart, hasBadge: true },
  { name: 'Antrean Sinkronisasi', href: '/pos/sync-queue', icon: RefreshCw, hasSyncBadge: true },
  { name: 'Product Management', href: '/pos/menu', icon: Package },
  { name: 'Inventori', href: '/pos/inventory', icon: Package },
  { name: 'Tables', href: '/pos/tables', icon: Square },
  { name: 'Staff', href: '/pos/staff', icon: Users },
  { name: 'Members', href: '/pos/members', icon: User },
  { name: 'Loyalty', href: '/pos/loyalty', icon: Settings },
  { name: 'Promo & Reservasi', href: '/pos/customer-hub', icon: Ticket },
  { name: 'Reports', href: '/pos/reports', icon: BarChart3 },
  { name: 'Settings', href: '/pos/settings', icon: Settings },
];

// Navigation for POS cashiers: one page per permission their owner/admin switched on
// (POS › Staff, backend App\Support\Pos\PosPermissions). "Orders" is always there. Outlets,
// Staff and the aggregated dashboard are owner/admin only.
// NOTE: never include /pos/cashier or /pos/admin-dashboard here — the former is
// an SSO bootstrap stub that redirects to admin-dashboard, the latter is a
// manager overview; pointing cashiers there causes a redirect loop.
const cashierNavigation: Array<{ name: string; href: string; icon: typeof ShoppingCart; hasBadge?: boolean; hasSyncBadge?: boolean; perm: string }> = [
  { name: 'Orders', href: '/pos/orders', icon: ShoppingCart, hasBadge: true, perm: 'orders' },
  { name: 'Antrean Sinkronisasi', href: '/pos/sync-queue', icon: RefreshCw, hasSyncBadge: true, perm: 'orders' },
  { name: 'Staff', href: '/pos/staff', icon: Users, perm: 'staff_management' },
  { name: 'Tables', href: '/pos/tables', icon: Square, perm: 'tables' },
  { name: 'Product Management', href: '/pos/menu', icon: Package, perm: 'products' },
  { name: 'Inventori', href: '/pos/inventory', icon: Package, perm: 'inventory_management' },
  { name: 'Members', href: '/pos/members', icon: User, perm: 'members' },
  { name: 'Loyalty', href: '/pos/loyalty', icon: Settings, perm: 'loyalty' },
  { name: 'Promo & Reservasi', href: '/pos/customer-hub', icon: Ticket, perm: 'customer_hub' },
  { name: 'Reports', href: '/pos/reports', icon: BarChart3, perm: 'reports' },
  { name: 'Pesanan & Jam Buka', href: '/pos/settings', icon: Settings, perm: 'outlet_settings' },
];

const ACTIVE_ORDER_STATUSES = ['new', 'accepted', 'preparing', 'prepared'];
const INVENTORY_MODULES = [
  'variants', 'barcode', 'serial_imei', 'warranty', 'price_tiers',
  'suppliers_purchasing', 'stock_opname',
] as const;

const countActiveOrders = (orders: Array<{ status: string; created_at?: string }> = []) => {
  const resetAt = getPosOrderListResetAt();

  return orders.filter((order) => {
    const isActive = ACTIVE_ORDER_STATUSES.includes(order.status);
    const isVisible = !order.created_at || isOrderVisibleAfterReset(order.created_at, resetAt);

    return isActive && isVisible;
  }).length;
};

export default function PosLayout() {
  return (
    <BusinessProfileProvider>
      <PosLayoutContent />
    </BusinessProfileProvider>
  );
}

function PosLayoutContent() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem('hellom-pos-sidebar-collapsed') === 'true';
    } catch {
      return false;
    }
  });
  const [showIosGuide, setShowIosGuide] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const {
    profile,
    error: businessProfileError,
    loading: businessProfileLoading,
    refresh: refreshBusinessProfile,
    terminology,
    hasModule,
  } = useBusinessProfile();
  const productIcon = productIcons[profile?.business_type ?? 'retail'];
  const hasTablesSelfOrder = useModule('tables_selforder');
  const [activeTab, setActiveTab] = useState('');
  const [activeOrdersCount, setActiveOrdersCount] = useState(0);
  const [syncSnapshot, setSyncSnapshot] = useState<PosSyncSnapshot>(getPosSyncSnapshot);
  const [offlineSessionExpired, setOfflineSessionExpired] = useState(isPosOfflineSessionExpired);
  const lastOfflineScope = useRef(getPosOfflineScope());
  const { state: installState, install } = usePWAInstall();
  const [pwaUpdateAvailable, setPwaUpdateAvailable] = useState(hasPwaUpdateAvailable);
  const [posTransactionInProgress, setPosTransactionInProgress] = useState(isPosTransactionInProgress);

  useEffect(() => startPosSyncEngine(), []);

  useEffect(() => subscribePosSync(() => setSyncSnapshot(getPosSyncSnapshot())), []);

  useEffect(() => {
    const updateOfflineSessionState = () => setOfflineSessionExpired(isPosOfflineSessionExpired());
    const onSessionChanged = () => {
      if (getToken()) {
        lastOfflineScope.current = getPosOfflineScope();
      } else if (lastOfflineScope.current) {
        const scope = lastOfflineScope.current;
        lastOfflineScope.current = null;
        void clearSyncedPosCacheOnLogout(scope).catch((error: unknown) => {
          console.error('[POS offline] Failed to clear cached data after logout.', error);
          window.alert('Cache POS belum dapat dibersihkan. Antrean transaksi belum tersinkron tetap disimpan di perangkat.');
        });
      }
      updateOfflineSessionState();
    };
    const timer = window.setInterval(updateOfflineSessionState, 60000);
    window.addEventListener('online', updateOfflineSessionState);
    window.addEventListener('offline', updateOfflineSessionState);
    window.addEventListener(getSessionEventName(), onSessionChanged);
    updateOfflineSessionState();
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('online', updateOfflineSessionState);
      window.removeEventListener('offline', updateOfflineSessionState);
      window.removeEventListener(getSessionEventName(), onSessionChanged);
    };
  }, []);

  useEffect(() => {
    const onUpdateAvailable = () => setPwaUpdateAvailable(true);
    const onTransactionState = (event: Event) => {
      setPosTransactionInProgress(Boolean((event as CustomEvent<boolean>).detail));
    };

    window.addEventListener(PWA_UPDATE_AVAILABLE_EVENT, onUpdateAvailable);
    window.addEventListener(POS_TRANSACTION_STATE_EVENT, onTransactionState);
    setPwaUpdateAvailable(hasPwaUpdateAvailable());
    setPosTransactionInProgress(isPosTransactionInProgress());
    return () => {
      window.removeEventListener(PWA_UPDATE_AVAILABLE_EVENT, onUpdateAvailable);
      window.removeEventListener(POS_TRANSACTION_STATE_EVENT, onTransactionState);
    };
  }, []);

  useEffect(() => {
    try {
      window.localStorage.setItem('hellom-pos-sidebar-collapsed', String(sidebarCollapsed));
    } catch {
      // Sidebar preference remains usable for this session when storage is unavailable.
    }
  }, [sidebarCollapsed]);

  // Re-render when the session (and with it pos_access) changes.
  const [, setAccessVersion] = useState(0);
  const { access: posAccess, isPosStaff, can } = usePermissions();
  const permissionsKey = JSON.stringify(posAccess?.permissions ?? {});
  // Shop owner: "Log Audit" menu, and a PIN must exist before using POS (created on first visit).
  const [ownerPin, setOwnerPin] = useState<PosOwnerPinStatus | null>(null);
  useEffect(() => {
    if (isPosStaff || !getToken()) return;
    getPosOwnerPinStatus().then(setOwnerPin).catch(() => setOwnerPin(null));
  }, [isPosStaff]);
  const isOwner = ownerPin?.is_owner === true;
  const navItems = useMemo(
    () => {
      const items = isPosStaff
        ? cashierNavigation.filter((item) => can(item.perm))
        : isOwner
          ? [...sidebarNavigation, { name: 'Log Audit', href: '/pos/audit', icon: ShieldCheck }]
          : sidebarNavigation;
      return items
        .filter((item) => item.href !== '/pos/tables' || hasTablesSelfOrder)
        .filter((item) => item.href !== '/pos/inventory' || INVENTORY_MODULES.some(hasModule))
        .map((item) => ({
          ...item,
          icon: item.href === '/pos/menu' ? productIcon : item.icon,
          name: item.href === '/pos/orders'
            ? terminology.transaction
            : item.href === '/pos/menu'
              ? terminology.product
              : item.href === '/pos/tables'
                ? terminology.table
                : item.href === '/pos/inventory'
                  ? 'Inventori'
                : item.href === '/pos/members'
                  ? terminology.customer
                    : item.href === '/pos/admin-dashboard'
                      ? 'Ringkasan'
                    : item.href === '/pos/reports'
                      ? 'Laporan'
                      : item.href === '/pos/settings'
                        ? 'Pengaturan'
                        : item.href === '/pos/staff'
                          ? 'Staf'
                          : item.href === '/pos/customer-hub'
                            ? 'Promo pelanggan'
                          : item.name,
        }));
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [isPosStaff, permissionsKey, isOwner, hasTablesSelfOrder, terminology, hasModule, productIcon, can]
  );
  // Tablet bottom bar: tab ids = the second path segment (/pos/menu → "menu").
  const allowedTabs = isPosStaff ? navItems.map((item) => item.href.split('/')[2]) : undefined;
  const pendingSyncCount = syncSnapshot.pending + syncSnapshot.syncing;
  const unsyncedSyncCount = pendingSyncCount + syncSnapshot.failed;
  const syncStatusLabel = !syncSnapshot.online
    ? `🔴 Offline (${unsyncedSyncCount} tertunda)`
    : syncSnapshot.failed > 0
      ? `🟠 Ada yang gagal (${syncSnapshot.failed})`
      : syncSnapshot.running || pendingSyncCount > 0
        ? `🟡 Menyinkronkan (${pendingSyncCount})`
        : '🟢 Online & tersinkron';
  const canActivateUpdate = !posTransactionInProgress && unsyncedSyncCount === 0;

  useEffect(() => {
    setPosHasUnsyncedData(unsyncedSyncCount > 0);
  }, [unsyncedSyncCount]);

  const handlePwaUpdate = async () => {
    try {
      const counts = await getPosSyncCounts();
      const hasUnsynced = counts.pending + counts.syncing + counts.failed > 0;
      setPosHasUnsyncedData(hasUnsynced);
      if (hasUnsynced || isPosTransactionInProgress()) {
        setSyncSnapshot((current) => ({ ...current, ...counts }));
        return;
      }
      activatePwaUpdate();
    } catch (error) {
      console.error('[PWA] Could not verify the offline queue before updating.', error);
      window.alert('Antrean lokal belum dapat diperiksa. Pembaruan ditunda agar transaksi tidak hilang.');
    }
  };

  const handleInstallClick = async () => {
    if (installState !== 'available') {
      setShowIosGuide(true);
      return;
    }
    try {
      const outcome = await install();
      if (outcome === null) setShowIosGuide(true);
    } catch (error) {
      console.error('[PWA] Install prompt failed.', error);
      setShowIosGuide(true);
    }
  };

  useEffect(() => {
    if (!businessProfileLoading && !hasTablesSelfOrder && location.pathname.startsWith('/pos/tables')) {
      navigate('/pos/admin-dashboard', { replace: true });
    }
  }, [businessProfileLoading, hasTablesSelfOrder, location.pathname, navigate]);

  // Cashier permissions can change while they are logged in (owner/admin edits POS › Staff):
  // reload them on open, on focus, every minute, and right after the API refused something.
  useEffect(() => {
    const onSession = () => setAccessVersion((v) => v + 1);
    window.addEventListener(getSessionEventName(), onSession);
    return () => window.removeEventListener(getSessionEventName(), onSession);
  }, []);
  useEffect(() => {
    if (!isPosStaff) return undefined;
    let last = 0;
    const refresh = async () => {
      if (Date.now() - last < 3000 || !getToken()) return;
      last = Date.now();
      try {
        const access = await getPosMyAccess();
        if (JSON.stringify(access) !== JSON.stringify(getSessionPosAccess())) setSessionPosAccess(access);
      } catch {
        // Offline or token expired: keep the current menu; the API still enforces access.
      }
    };
    const onVisible = () => { if (document.visibilityState === 'visible') void refresh(); };
    void refresh();
    const timer = window.setInterval(() => void refresh(), 60000);
    window.addEventListener('focus', onVisible);
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener(POS_PERMISSION_DENIED_EVENT, refresh);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', onVisible);
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener(POS_PERMISSION_DENIED_EVENT, refresh);
    };
  }, [isPosStaff]);

  // Keep cashiers out of manager-only pages even via direct URL. Redirect to the
  // first page they ARE allowed to see (never a hardcoded route that might bounce).
  useEffect(() => {
    if (businessProfileLoading || businessProfileError || hasTablesSelfOrder) return;
    if (location.pathname === '/pos/tables' || location.pathname.startsWith('/pos/tables/')) {
      navigate('/pos/orders', { replace: true });
    }
  }, [businessProfileLoading, businessProfileError, hasTablesSelfOrder, location.pathname, navigate]);

  useEffect(() => {
    if (!isPosStaff) return;
    const allowed = navItems.map((item) => item.href);
    const path = location.pathname;
    const ok = allowed.some((href) => path === href || path.startsWith(`${href}/`));
    const home = navItems[0]?.href;
    if (!ok && home && path !== home) navigate(home, { replace: true });
  }, [isPosStaff, location.pathname, navItems, navigate]);

  const handleLogout = async () => {
    if (!(await confirmPosContextChange())) return;
    try {
      await clearSyncedPosCacheOnLogout();
    } catch (error) {
      console.error('[POS offline] Failed to clear cached data before logout.', error);
      window.alert('Cache POS belum dapat dibersihkan. Logout dibatalkan agar data perangkat tetap aman.');
      return;
    }
    setSidebarOpen(false);
    clearSession();
    // Cashiers go back to their own login page; owners/admins to the normal one.
    navigate(isPosStaff ? '/login/kasir' : '/login?app=pos', { replace: true });
  };

  // Map current location to active tab
  useEffect(() => {
    const path = location.pathname;
    if (path.includes('/pos/admin-dashboard')) setActiveTab('admin-dashboard');
    else if (path.includes('/pos/outlets')) setActiveTab('outlets');
    else if (path.includes('/pos/orders')) setActiveTab('orders');
    else if (path.includes('/pos/menu')) setActiveTab('menu');
    else if (path.includes('/pos/tables')) setActiveTab('tables');
    else if (path.includes('/pos/staff')) setActiveTab('staff');
    else if (path.includes('/pos/members')) setActiveTab('members');
    else if (path.includes('/pos/loyalty')) setActiveTab('loyalty');
    else if (path.includes('/pos/customer-hub')) setActiveTab('customer-hub');
    else if (path.includes('/pos/reports')) setActiveTab('reports');
    else if (path.includes('/pos/settings')) setActiveTab('settings');
    else if (path.includes('/pos/audit')) setActiveTab('audit');
    else setActiveTab('admin-dashboard');
  }, [location]);

  useEffect(() => {
    let isMounted = true;
    let refreshing = false;

    const syncSession = async () => {
      const hasSsoToken = new URLSearchParams(location.search).has('sso_token');
      const hasToken = Boolean(getToken());
      const hasUser = Boolean(getSessionUser());

      if (hasToken && !hasUser && !refreshing) {
        refreshing = true;
        try {
          const me = await getAuthMe();
          const token = getToken();
          if (token && isMounted) {
            setSession(token, me);
          }
        } catch (error) {
          console.error('[POS] Failed to refresh the authenticated session:', error);
        } finally {
          refreshing = false;
        }
      }

      const hasUserAfterRefresh = Boolean(getSessionUser());
      if (!hasSsoToken && (!hasToken || !hasUserAfterRefresh)) {
        navigate('/login?app=pos', { replace: true });
      }
    };

    syncSession();
    window.addEventListener(getSessionEventName(), syncSession as EventListener);

    return () => {
      isMounted = false;
      window.removeEventListener(getSessionEventName(), syncSession);
    };
  }, [location.search, navigate]);

  useEffect(() => {
    let isMounted = true;

    const refreshActiveOrdersCount = async () => {
      try {
        const result = await getPosOrders();
        if (!isMounted) return;
        setActiveOrdersCount(countActiveOrders(result.orders ?? []));
      } catch {
        if (!isMounted) return;
        setActiveOrdersCount(0);
      }
    };

    const handleOrdersUpdated = () => {
      void refreshActiveOrdersCount();
    };

    void refreshActiveOrdersCount();

    const interval = window.setInterval(() => {
      void refreshActiveOrdersCount();
    }, 30000);

    window.addEventListener('pos-orders-updated', handleOrdersUpdated);
    window.addEventListener(getPosOrderListResetEventName(), handleOrdersUpdated);

    return () => {
      isMounted = false;
      window.clearInterval(interval);
      window.removeEventListener('pos-orders-updated', handleOrdersUpdated);
      window.removeEventListener(getPosOrderListResetEventName(), handleOrdersUpdated);
    };
  }, []);

  useEffect(() => {
    const refreshActiveOrdersCount = async () => {
      try {
        const result = await getPosOrders();
        setActiveOrdersCount(countActiveOrders(result.orders ?? []));
      } catch {
        setActiveOrdersCount(0);
      }
    };

    void refreshActiveOrdersCount();
  }, [location.pathname]);

  // Live order feed for the active outlet: chime on new orders, then every POS screen
  // refreshes through 'pos-orders-updated'. Reconnects when the outlet changes.
  useEffect(() => {
    if (!getToken()) return undefined;
    let unsubscribe: () => void = () => undefined;

    const connect = () => {
      unsubscribe();
      setPosRealtimeConnected(false);
      unsubscribe = subscribeRealtime({
        getToken: getPosRealtimeToken,
        onStatus: setPosRealtimeConnected,
        handlers: {
          'pos.order': (payload) => {
            const event = (payload as { event?: string } | null)?.event;
            if (event === 'order.created') playOrderChime();
            window.dispatchEvent(new CustomEvent('pos-orders-updated'));
          },
        },
      });
    };

    connect();
    window.addEventListener(getActiveOutletEventName(), connect);

    return () => {
      window.removeEventListener(getActiveOutletEventName(), connect);
      unsubscribe();
      setPosRealtimeConnected(false);
    };
  }, []);

  const handleTabChange = (tab: string) => {
    let path = '';
    switch (tab) {
      case 'admin-dashboard': path = '/pos/admin-dashboard'; break;
      case 'outlets': path = '/pos/outlets'; break;
      case 'orders': path = '/pos/orders'; break;
      case 'menu': path = '/pos/menu'; break;
      case 'tables': path = '/pos/tables'; break;
      case 'staff': path = '/pos/staff'; break;
      case 'members': path = '/pos/members'; break;
      case 'loyalty': path = '/pos/loyalty'; break;
      case 'customer-hub': path = '/pos/customer-hub'; break;
      case 'reports': path = '/pos/reports'; break;
      case 'settings': path = '/pos/settings'; break;
      default: path = '/pos/admin-dashboard';
    }
    navigate(path);
  };

  const handleGoToDashboard = () => {
    setSidebarOpen(false);
    navigate('/dashboard');
  };

  return (
    <div className="min-h-screen bg-[#fffdf5]">
      {/* Desktop Sidebar */}
      <div className={cn(
        'fixed inset-y-0 left-0 z-30 hidden transition-[width] duration-200 ease-in-out lg:block',
        sidebarCollapsed ? 'w-[72px]' : 'w-64'
      )}>
        <div className="flex h-full flex-col border-r border-[#eadfbe] bg-white shadow-[0_12px_40px_rgba(17,17,17,0.08)]">
          <div className={cn('flex h-16 items-center border-b border-[#f1e7c9]', sidebarCollapsed ? 'justify-center px-2' : 'justify-between px-4')}>
            {!sidebarCollapsed && (
              <div className="flex min-w-0 items-center gap-2">
                <img src={BRAND_LOGO_PATH} alt="" className="h-9 w-9 shrink-0 rounded-lg object-contain" />
                <div className="min-w-0">
                  <h2 className="text-lg font-semibold text-[#111111]">Hellom</h2>
                  <p className="text-[10px] leading-none text-[#8a7d63]">POS System</p>
                </div>
              </div>
            )}
            <button
              type="button"
              onClick={() => setSidebarCollapsed((collapsed) => !collapsed)}
              aria-label={sidebarCollapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'}
              aria-expanded={!sidebarCollapsed}
              title={sidebarCollapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'}
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-[#6b7280] transition hover:bg-[#fff7db] hover:text-[#111111]"
            >
              {sidebarCollapsed ? <PanelLeftOpen className="h-5 w-5" /> : <PanelLeftClose className="h-5 w-5" />}
            </button>
          </div>
          <div className={cn('pt-4', sidebarCollapsed ? 'flex justify-center px-2' : 'px-4')}>
            {sidebarCollapsed ? (
              <div
                title={`Outlet aktif: ${isPosStaff ? (posAccess?.outlet_name || 'Outlet kamu') : 'Pilih outlet'}`}
                aria-label={`Outlet aktif: ${isPosStaff ? (posAccess?.outlet_name || 'Outlet kamu') : 'Pilih outlet'}`}
                className="flex h-10 w-10 items-center justify-center rounded-lg border border-[#eadfbe] bg-[#fffdf5] text-[#8a7d63]"
              >
                <Store className="h-5 w-5" />
              </div>
            ) : isPosStaff && (posAccess?.outlets?.length ?? 0) <= 1 ? (
              <div className="rounded-md border border-[#eadfbe] bg-[#fffdf5] px-3 py-2">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-[#8a7d63]">Outlet aktif</p>
                <p className="truncate text-sm font-semibold text-[#111111]">{posAccess?.outlet_name || 'Outlet kamu'}</p>
              </div>
            ) : (
              <OutletSwitcher canManageOutlets={!isPosStaff} />
            )}
          </div>
          <nav className={cn('mt-4 flex-1', sidebarCollapsed ? 'px-2' : 'px-4')}>
            {navItems.map((item) => (
              <Link
                key={item.name}
                to={item.href}
                title={sidebarCollapsed ? item.name : undefined}
                aria-label={item.name}
                className={cn(
                  'relative mb-1 flex min-h-11 items-center rounded-md text-sm font-medium transition-colors',
                  sidebarCollapsed ? 'justify-center px-2' : 'px-3',
                  location.pathname === item.href || location.pathname.startsWith(`${item.href}/`)
                    ? 'bg-amber-300 text-[#111111]'
                    : 'text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]'
                )}
              >
                <item.icon className={cn('h-5 w-5 shrink-0', sidebarCollapsed ? '' : 'mr-3')} />
                {!sidebarCollapsed && <span className="truncate">{item.name}</span>}
                {item.hasBadge && activeOrdersCount > 0 && (
                  <span className={cn(
                    'inline-flex min-w-[22px] items-center justify-center rounded-full bg-red-500 px-2 py-0.5 text-xs font-semibold text-white',
                    sidebarCollapsed ? 'absolute -right-1 -top-1 min-w-[18px] px-1 text-[10px]' : 'ml-auto'
                  )}>
                    {activeOrdersCount > 99 ? '99+' : activeOrdersCount}
                  </span>
                )}
                {item.hasSyncBadge && unsyncedSyncCount > 0 && (
                  <span className={cn(
                    'inline-flex min-w-[22px] items-center justify-center rounded-full bg-orange-500 px-2 py-0.5 text-xs font-semibold text-white',
                    sidebarCollapsed ? 'absolute -right-1 -bottom-1 min-w-[18px] px-1 text-[10px]' : 'ml-auto',
                  )}>
                    {unsyncedSyncCount > 99 ? '99+' : unsyncedSyncCount}
                  </span>
                )}
              </Link>
            ))}
          </nav>
          <div className={cn('border-t border-[#f1e7c9]', sidebarCollapsed ? 'p-2' : 'p-4')}>
            {installState !== 'installed' && (
              <button
                type="button"
                onClick={() => void handleInstallClick()}
                className={cn(
                  'mb-1 flex min-h-11 w-full items-center rounded-md py-2 text-sm font-medium text-amber-800 hover:bg-amber-50',
                  sidebarCollapsed ? 'justify-center px-2' : 'px-3',
                )}
                aria-label="Install Aplikasi"
                title="Install Aplikasi"
              >
                <Smartphone className={cn('h-5 w-5 shrink-0', sidebarCollapsed ? '' : 'mr-3')} />
                {!sidebarCollapsed && 'Install Aplikasi'}
              </button>
            )}
            <Link
              to="/pos/profile"
              title={sidebarCollapsed ? 'Profil saya' : undefined}
              aria-label="Profil saya"
              className={cn(
                'mb-1 flex min-h-11 items-center rounded-md py-2 text-sm font-medium',
                sidebarCollapsed ? 'justify-center px-2' : 'px-3',
                location.pathname === '/pos/profile' ? 'bg-amber-300 text-[#111111]' : 'text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]'
              )}
            >
              <User className={cn('h-5 w-5 shrink-0', sidebarCollapsed ? '' : 'mr-3')} />
              {!sidebarCollapsed && 'Profil saya'}
            </Link>
            <button
              type="button"
              onClick={isPosStaff ? handleLogout : handleGoToDashboard}
              title={sidebarCollapsed ? (isPosStaff ? 'Keluar' : 'Ke Dashboard Hellom') : undefined}
              aria-label={isPosStaff ? 'Keluar' : 'Ke Dashboard Hellom'}
              className={cn('flex min-h-11 w-full items-center rounded-md py-2 text-sm font-medium text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]', sidebarCollapsed ? 'justify-center px-2' : 'px-3')}
            >
              <LogOut className={cn('h-5 w-5 shrink-0', sidebarCollapsed ? '' : 'mr-3')} />
              {!sidebarCollapsed && (isPosStaff ? 'Keluar' : 'Ke Dashboard Hellom')}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile/Tablet Layout */}
      <div className={cn('flex min-h-screen min-w-0 flex-col transition-[padding] duration-200 ease-in-out', sidebarCollapsed ? 'lg:pl-[72px]' : 'lg:pl-64')}>
        {/* Top Header - Mobile/Tablet */}
        <div className="sticky top-0 z-40 lg:hidden">
          <div className="flex h-16 items-center justify-between border-b border-[#f1e7c9] bg-white/95 px-4 shadow-sm backdrop-blur">
            <button
              type="button"
              onClick={() => setSidebarOpen((open) => !open)}
              aria-label={sidebarOpen ? 'Tutup menu' : 'Buka menu'}
              aria-expanded={sidebarOpen}
              className="flex h-11 w-11 items-center justify-center rounded-lg text-[#8a7d63] hover:bg-[#fff7db] hover:text-[#111111]"
            >
              <Menu className="h-6 w-6" />
            </button>
            <h1 className="min-w-0 truncate text-base font-semibold text-[#111111] sm:text-lg">Kasir POS</h1>
            <div className="flex items-center gap-1.5">
              <Link
                to="/pos/sync-queue"
                aria-label={`Status sinkronisasi: ${syncStatusLabel}`}
                title={syncStatusLabel}
                className="max-w-[108px] truncate rounded-lg bg-gray-100 px-2 py-2 text-[10px] font-semibold text-gray-800 sm:max-w-none sm:text-xs"
              >
                {syncStatusLabel}
              </Link>
              {installState !== 'installed' && (
                <button
                  type="button"
                  onClick={() => void handleInstallClick()}
                  className="flex h-10 w-10 shrink-0 items-center justify-center gap-1 rounded-lg bg-amber-100 px-2.5 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-200 sm:w-auto"
                  title="Install Aplikasi"
                  aria-label="Install Aplikasi"
                >
                  <Smartphone className="h-4 w-4" />
                  <span className="hidden sm:inline">Install</span>
                </button>
              )}
              <Link
                to="/pos/settings"
                className="p-2 text-[#8a7d63] hover:text-[#111111] hover:bg-[#fff7db] rounded-lg transition-colors"
              >
                <Settings className="h-5 w-5" />
              </Link>
              <button
                type="button"
                onClick={isPosStaff ? handleLogout : handleGoToDashboard}
                aria-label={isPosStaff ? 'Keluar' : 'Ke Dashboard Hellom'}
                className="flex h-11 shrink-0 items-center gap-2 px-3 py-1.5 text-sm font-medium text-[#4b5563] hover:text-[#111111] hover:bg-[#fff7db] rounded-lg transition-colors"
              >
                <LogOut className="h-5 w-5 sm:hidden" />
                <span className="hidden sm:inline">{isPosStaff ? 'Keluar' : 'Ke Dashboard Hellom'}</span>
              </button>
            </div>
          </div>
        </div>

        {/* Main Content */}
        <main className="min-w-0 flex-1 bg-[#fffdf5] p-3 pb-24 sm:p-5 sm:pb-24 lg:p-6 lg:pb-6">
          <div className="mb-3 hidden justify-end lg:flex">
            <Link
              to="/pos/sync-queue"
              aria-label={`Status sinkronisasi: ${syncStatusLabel}`}
              className="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 shadow-sm"
            >
              {syncStatusLabel}
            </Link>
          </div>
          {pwaUpdateAvailable && (
            <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <span>
                {!canActivateUpdate
                  ? `Versi baru tersedia. ${posTransactionInProgress ? 'Selesaikan transaksi' : `Sinkronkan ${unsyncedSyncCount} transaksi tertunda`} sebelum memuat ulang.`
                  : 'Versi baru tersedia, muat ulang.'}
              </span>
              <button
                type="button"
                onClick={() => void handlePwaUpdate()}
                disabled={!canActivateUpdate}
                className="rounded-lg bg-amber-300 px-3 py-2 font-semibold text-gray-900 hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Muat ulang
              </button>
            </div>
          )}
          {businessProfileError && (
            <div role="alert" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
              <span>Profil usaha gagal dimuat: {businessProfileError}</span>
              <button type="button" onClick={() => void refreshBusinessProfile()} className="font-semibold underline">
                Coba lagi
              </button>
            </div>
          )}
          {offlineSessionExpired ? (
            <div className="mx-auto mt-8 max-w-xl rounded-2xl border border-red-200 bg-white p-6 text-center shadow-sm">
              <h2 className="text-lg font-bold text-gray-950">Sesi offline berakhir</h2>
              <p className="mt-2 text-sm leading-6 text-gray-700">
                Demi keamanan perangkat bersama, POS memerlukan validasi online setidaknya setiap {POS_OFFLINE_MAX_SESSION_HOURS} jam.
                Order yang belum tersinkron tetap disimpan dan tidak dihapus. Hubungkan perangkat ke internet untuk melanjutkan dan login ulang bila diminta.
              </p>
              <p className="mt-3 text-xs text-gray-500">
                Token sesi saat ini disimpan di penyimpanan lokal browser (localStorage); jangan gunakan profil browser bersama tanpa logout.
              </p>
              <button
                type="button"
                onClick={() => {
                  clearSession();
                  navigate('/login?app=pos', { replace: true });
                }}
                className="mt-5 min-h-11 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
              >
                Ke halaman login
              </button>
            </div>
          ) : <Outlet />}
          {ownerPin?.is_owner && !ownerPin.pin_set && (
            <OwnerPinSetupDialog resetEmail={ownerPin.reset_email} onDone={() => setOwnerPin({ ...ownerPin, pin_set: true })} />
          )}
        </main>

        {/* New Floating Bottom Navigation */}
        <div className="lg:hidden">
          <BottomNav
            activeTab={activeTab}
            onTabChange={handleTabChange}
            activeOrdersCount={activeOrdersCount}
            allowedTabs={allowedTabs}
            hasTablesSelfOrder={hasTablesSelfOrder}
            productLabel={terminology.product}
            transactionLabel={terminology.transaction}
            customerLabel={terminology.customer}
            productIcon={productIcon}
          />
        </div>
      </div>

      {/* iOS Add-to-Home-Screen Guide */}
      {showIosGuide && (
        <div className="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-4">
          <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
            <h3 className="mb-3 text-base font-semibold text-gray-900">
              {installState === 'ios' ? 'Install POS Hellom di iPhone / iPad' : 'Pasang Hellom POS di perangkat ini'}
            </h3>
            {installState === 'ios' ? (
              <ol className="space-y-2.5 text-sm text-gray-600">
                <li>1. Ketuk ikon <strong>Bagikan</strong> di toolbar Safari.</li>
                <li>2. Pilih <strong>Tambahkan ke Layar Utama</strong>.</li>
                <li>3. Ketuk <strong>Tambah</strong> untuk memasang.</li>
              </ol>
            ) : (
              <div className="space-y-2.5 text-sm text-gray-600">
                <p><strong>Chrome Android:</strong> buka menu ⋮ lalu pilih <strong>Install aplikasi</strong> atau <strong>Tambahkan ke layar utama</strong>.</p>
                <p><strong>Chrome desktop:</strong> pilih ikon install di bilah alamat, atau menu ⋮ → <strong>Install Hellom POS</strong>.</p>
                <p><strong>Microsoft Edge:</strong> buka menu … → <strong>Aplikasi</strong> → <strong>Instal situs ini sebagai aplikasi</strong>.</p>
                <p className="text-xs text-gray-500">Jika pilihan belum muncul, buka halaman POS melalui HTTPS di browser terbaru, lalu muat ulang sekali.</p>
              </div>
            )}
            <button
              type="button"
              onClick={() => setShowIosGuide(false)}
              className="mt-5 w-full rounded-xl bg-amber-400 py-2.5 text-sm font-semibold text-gray-900 transition hover:bg-amber-500"
            >
              Mengerti
            </button>
          </div>
        </div>
      )}

      {/* Mobile Sidebar Overlay */}
      <div className={cn('fixed inset-0 z-50 lg:hidden', sidebarOpen ? 'pointer-events-auto' : 'pointer-events-none')}>
        <div
          className={cn(
            'absolute inset-0 transition duration-200',
            sidebarOpen ? 'bg-white/18 opacity-100 backdrop-blur-md' : 'opacity-0'
          )}
          onClick={() => setSidebarOpen(false)}
        />
        <div
          className={cn(
            'absolute inset-y-0 left-0 w-64 max-w-full overflow-y-auto overscroll-contain border-r border-[#eadfbe] bg-white/95 shadow-[0_20px_60px_rgba(17,17,17,0.14)] backdrop-blur-xl transition duration-200',
            sidebarOpen ? 'translate-x-0' : '-translate-x-full'
          )}
        >
          <div className="flex h-16 items-center justify-between border-b border-[#f1e7c9] px-4">
            <h2 className="text-lg font-semibold text-[#111111]">Kasir POS</h2>
            <button onClick={() => setSidebarOpen(false)} className="text-[#8a7d63] hover:text-[#111111]">
              <X className="h-5 w-5" />
            </button>
          </div>
          <div className="px-4 pt-4">
            {isPosStaff && (posAccess?.outlets?.length ?? 0) <= 1 ? (
              <div className="rounded-md border border-[#eadfbe] bg-[#fffdf5] px-3 py-2">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-[#8a7d63]">Outlet</p>
                <p className="truncate text-sm font-semibold text-[#111111]">{posAccess?.outlet_name || 'Outlet kamu'}</p>
              </div>
            ) : (
              <OutletSwitcher canManageOutlets={!isPosStaff} />
            )}
          </div>
          <nav className="mt-4 px-4">
            {navItems.map((item) => (
              <Link
                key={item.name}
                to={item.href}
                className={cn(
                  'mb-1 flex items-center rounded-md px-3 py-2 text-sm font-medium',
                  location.pathname === item.href
                    ? 'bg-amber-300 text-[#111111]'
                    : 'text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]'
                )}
                onClick={() => setSidebarOpen(false)}
              >
                <item.icon className="mr-3 h-5 w-5" />
                <span>{item.name}</span>
                {item.hasBadge && activeOrdersCount > 0 && (
                  <span className="ml-auto inline-flex min-w-[22px] items-center justify-center rounded-full bg-red-500 px-2 py-0.5 text-xs font-semibold text-white">
                    {activeOrdersCount > 99 ? '99+' : activeOrdersCount}
                  </span>
                )}
              </Link>
            ))}
          </nav>
          <div className="border-t border-[#f1e7c9] p-4">
            <Link
              to="/pos/profile"
              onClick={() => setSidebarOpen(false)}
              className={cn(
                'mb-1 flex items-center rounded-md px-3 py-2 text-sm font-medium',
                location.pathname === '/pos/profile' ? 'bg-amber-300 text-[#111111]' : 'text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]'
              )}
            >
              <User className="mr-3 h-5 w-5" />
              Profil saya
            </Link>
            <button
              type="button"
              onClick={isPosStaff ? handleLogout : handleGoToDashboard}
              className="flex items-center rounded-md px-3 py-2 text-sm font-medium text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]"
            >
              <LogOut className="mr-3 h-5 w-5" />
              {isPosStaff ? 'Keluar' : 'Ke Dashboard Hellom'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
