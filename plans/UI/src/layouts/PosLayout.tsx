import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { LayoutDashboard, ShoppingCart, Users, BarChart3, Utensils, Settings, LogOut, Square, X, Menu, User, Ticket } from 'lucide-react';
import { useState, useEffect } from 'react';
import BottomNav from '@/components/pos/BottomNav';
import { getAuthMe, getPosOrders, getSessionEventName, getSessionUser, getToken, setSession } from '@/lib/hellomApi';
import {
  getPosOrderListResetAt,
  getPosOrderListResetEventName,
  isOrderVisibleAfterReset,
} from '@/lib/posOrderListReset';

const sidebarNavigation = [
  { name: 'Dashboard', href: '/pos/admin-dashboard', icon: LayoutDashboard },
  { name: 'Orders', href: '/pos/orders', icon: ShoppingCart, hasBadge: true },
  { name: 'Product Management', href: '/pos/menu', icon: Utensils },
  { name: 'Tables', href: '/pos/tables', icon: Square },
  { name: 'Staff', href: '/pos/staff', icon: Users },
  { name: 'Members', href: '/pos/members', icon: User },
  { name: 'Loyalty', href: '/pos/loyalty', icon: Settings },
  { name: 'Promo & Reservasi', href: '/pos/customer-hub', icon: Ticket },
  { name: 'Reports', href: '/pos/reports', icon: BarChart3 },
  { name: 'Settings', href: '/pos/settings', icon: Settings },
];

const ACTIVE_ORDER_STATUSES = ['new', 'accepted', 'preparing', 'prepared'];

const countActiveOrders = (orders: Array<{ status: string; created_at?: string }> = []) => {
  const resetAt = getPosOrderListResetAt();

  return orders.filter((order) => {
    const isActive = ACTIVE_ORDER_STATUSES.includes(order.status);
    const isVisible = !order.created_at || isOrderVisibleAfterReset(order.created_at, resetAt);

    return isActive && isVisible;
  }).length;
};

export default function PosLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState('');
  const [activeOrdersCount, setActiveOrdersCount] = useState(0);

  // Map current location to active tab
  useEffect(() => {
    const path = location.pathname;
    if (path.includes('/pos/admin-dashboard')) setActiveTab('admin-dashboard');
    else if (path.includes('/pos/orders')) setActiveTab('orders');
    else if (path.includes('/pos/menu')) setActiveTab('menu');
    else if (path.includes('/pos/tables')) setActiveTab('tables');
    else if (path.includes('/pos/staff')) setActiveTab('staff');
    else if (path.includes('/pos/members')) setActiveTab('members');
    else if (path.includes('/pos/loyalty')) setActiveTab('loyalty');
    else if (path.includes('/pos/customer-hub')) setActiveTab('customer-hub');
    else if (path.includes('/pos/reports')) setActiveTab('reports');
    else if (path.includes('/pos/settings')) setActiveTab('settings');
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
        } catch {
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

  const handleTabChange = (tab: string) => {
    let path = '';
    switch (tab) {
      case 'admin-dashboard': path = '/pos/admin-dashboard'; break;
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
      <div className="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:block lg:w-64">
        <div className="flex h-full flex-col border-r border-[#eadfbe] bg-white shadow-[0_12px_40px_rgba(17,17,17,0.08)]">
          <div className="flex h-16 items-center border-b border-[#f1e7c9] px-4">
            <h2 className="text-lg font-semibold text-[#111111]">POS System</h2>
          </div>
          <nav className="mt-8 flex-1 px-4">
            {sidebarNavigation.map((item) => (
              <Link
                key={item.name}
                to={item.href}
                className={cn(
                  'mb-1 flex items-center rounded-md px-3 py-2 text-sm font-medium',
                  location.pathname === item.href
                    ? 'bg-amber-300 text-[#111111]'
                    : 'text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]'
                )}
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
            <button
              type="button"
              onClick={handleGoToDashboard}
              className="flex items-center rounded-md px-3 py-2 text-sm font-medium text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]"
            >
              <LogOut className="mr-3 h-5 w-5" />
              Ke Dashboard Hellom
            </button>
          </div>
        </div>
      </div>

      {/* Mobile/Tablet Layout */}
      <div className={cn('flex flex-col min-h-screen lg:pl-64')}>
        {/* Top Header - Mobile/Tablet */}
        <div className="sticky top-0 z-40 lg:hidden">
          <div className="flex h-16 items-center justify-between border-b border-[#f1e7c9] bg-white/95 px-4 shadow-sm backdrop-blur">
            <button onClick={() => setSidebarOpen(true)} className="text-[#8a7d63] hover:text-[#111111]">
              <Menu className="h-6 w-6" />
            </button>
            <h1 className="text-lg font-semibold text-[#111111]">POS System</h1>
            <div className="flex items-center gap-2">
              <Link
                to="/pos/settings"
                className="p-2 text-[#8a7d63] hover:text-[#111111] hover:bg-[#fff7db] rounded-lg transition-colors"
              >
                <Settings className="h-5 w-5" />
              </Link>
              <button
                type="button"
                onClick={handleGoToDashboard}
                className="px-3 py-1.5 text-sm font-medium text-[#4b5563] hover:text-[#111111] hover:bg-[#fff7db] rounded-lg transition-colors"
              >
                Ke Dashboard Hellom
              </button>
            </div>
          </div>
        </div>

        {/* Main Content */}
        <main className="flex-1 bg-[#fffdf5] p-6 pb-24 lg:pb-6">
          <Outlet />
        </main>

        {/* New Floating Bottom Navigation */}
        <div className="lg:hidden">
          <BottomNav
            activeTab={activeTab}
            onTabChange={handleTabChange}
            activeOrdersCount={activeOrdersCount}
          />
        </div>
      </div>

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
            'absolute inset-y-0 left-0 w-64 border-r border-[#eadfbe] bg-white/95 shadow-[0_20px_60px_rgba(17,17,17,0.14)] backdrop-blur-xl transition duration-200',
            sidebarOpen ? 'translate-x-0' : '-translate-x-full'
          )}
        >
          <div className="flex h-16 items-center justify-between border-b border-[#f1e7c9] px-4">
            <h2 className="text-lg font-semibold text-[#111111]">POS System</h2>
            <button onClick={() => setSidebarOpen(false)} className="text-[#8a7d63] hover:text-[#111111]">
              <X className="h-5 w-5" />
            </button>
          </div>
          <nav className="mt-8 px-4">
            {sidebarNavigation.map((item) => (
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
            <button
              type="button"
              onClick={handleGoToDashboard}
              className="flex items-center rounded-md px-3 py-2 text-sm font-medium text-[#4b5563] hover:bg-[#fff7db] hover:text-[#111111]"
            >
              <LogOut className="mr-3 h-5 w-5" />
              Ke Dashboard Hellom
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
