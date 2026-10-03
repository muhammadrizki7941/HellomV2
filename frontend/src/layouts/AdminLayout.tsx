import { useEffect, useMemo, useState } from 'react';
import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard, Users, Settings, LogOut,
  Menu, ShieldAlert, Activity, Package, Wallet, Film, Palette, ShoppingBag, FileText, Building2, ScrollText, Receipt, LineChart
} from 'lucide-react';
import NotificationBell from '@/components/admin/NotificationBell';
import { cn } from '@/lib/utils';
import {
  clearSession,
  getAuthMe,
  getCurrentOrganization,
  getOrganizations,
  getSessionEventName,
  getSessionUser,
  getToken,
  logout,
  setSession,
  switchOrganization,
} from '@/lib/hellomApi';
import { BRAND_NAME, getBrandLogo } from '@/lib/branding';
import { fetchBrand, BrandSettings } from '@/hooks/useBrand';

export default function AdminLayout() {
  const navigate = useNavigate();
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const location = useLocation();
  const [adminName, setAdminName] = useState('Super Admin');
  const [organizations, setOrganizations] = useState<Array<{ id: number; name: string; role?: string }>>([]);
  const [currentOrgId, setCurrentOrgId] = useState<number | null>(null);
  const [switchingOrg, setSwitchingOrg] = useState(false);
  const [brand, setBrand] = useState<BrandSettings | null>(null);

  useEffect(() => {
    // Fetch brand settings on mount (use cache if available)
    fetchBrand().then(setBrand).catch(() => {
      // Use default brand if fetch fails
      setBrand(null);
    });
  }, []);

  // The API enforces super admin on every /admin endpoint; this only keeps others off the UI.
  useEffect(() => {
    const syncSession = (): boolean => {
      const sessionUser = getSessionUser<{ name?: string; role?: string }>();
      if (!getToken() || !sessionUser) {
        navigate('/login', { replace: true });
        return false;
      }
      if (sessionUser.role !== 'super_admin') {
        navigate('/dashboard', { replace: true });
        return false;
      }
      if (sessionUser.name) {
        setAdminName(sessionUser.name);
      }
      return true;
    };

    window.addEventListener(getSessionEventName(), syncSession);
    if (!syncSession()) {
      return () => window.removeEventListener(getSessionEventName(), syncSession);
    }

    const loadOrgContext = async () => {
      try {
        const [orgs, currentOrg] = await Promise.all([getOrganizations(), getCurrentOrganization()]);
        setOrganizations((orgs || []).map((org) => ({ id: org.id, name: org.name, role: org.role })));
        setCurrentOrgId(currentOrg?.id ?? null);
      } catch {
        // The organization switcher is optional; the admin pages work without it.
      }
    };

    void loadOrgContext();

    return () => window.removeEventListener(getSessionEventName(), syncSession);
  }, [navigate]);

  const handleSwitchOrganization = async (organizationId: number) => {
    if (!organizationId || organizationId === currentOrgId) {
      return;
    }

    setSwitchingOrg(true);
    try {
      await switchOrganization({ organization_id: organizationId });
      const me = await getAuthMe();
      const token = getToken();
      if (token) {
        setSession(token, me);
      }
      setCurrentOrgId(organizationId);
      navigate('/admin', { replace: true });
      window.location.reload();
    } finally {
      setSwitchingOrg(false);
    }
  };

  const adminInitials = useMemo(() => {
    const parts = adminName.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return 'SA';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
  }, [adminName]);

  const handleLogout = async () => {
    try {
      await logout();
    } catch {
      // The token may already be invalid; the local session is cleared anyway.
    } finally {
      clearSession();
      navigate('/login', { replace: true });
    }
  };

  const menuItems = [
    { icon: LayoutDashboard, label: 'Ringkasan', path: '/admin' },
    { icon: Users, label: 'Pengguna', path: '/admin/users' },
    { icon: Building2, label: 'Organisasi', path: '/admin/organizations' },
    { icon: Package, label: 'Aplikasi & Paket', path: '/admin/apps' },
    { icon: Receipt, label: 'Invoice', path: '/admin/invoices' },
    { icon: LineChart, label: 'Ringkasan Keuangan', path: '/admin/keuangan' },
    { icon: Wallet, label: 'Keuangan Platform', path: '/admin/finance' },
    { icon: Wallet, label: 'Keuangan Penjual', path: '/admin/keuangan-penjual' },
    { icon: ShieldAlert, label: 'Moderasi Toko', path: '/admin/moderasi-toko' },
    { icon: Film, label: 'Showcase', path: '/admin/showcase' },
    { icon: FileText, label: 'Konten Situs', path: '/admin/landing-content' },
    { icon: Palette, label: 'Branding', path: '/admin/brand' },
    { icon: ScrollText, label: 'Log Audit', path: '/admin/audit-log' },
    { icon: Activity, label: 'Kesehatan Sistem', path: '/admin/system' },
    { icon: Settings, label: 'Pengaturan', path: '/admin/settings' },
  ];

  const productMenuItems = [
    { icon: ShoppingBag, label: 'Kelola Produk', path: '/admin/products', exact: false },
    { icon: Package, label: 'Pembelian', path: '/admin/products/purchases', exact: true },
  ];

  // "/admin" only matches itself; other items also stay active on their sub-pages.
  const isActivePath = (path: string, exact = path === '/admin') => {
    if (exact) return location.pathname === path;
    if (path === '/admin/products' && location.pathname.startsWith('/admin/products/purchases')) return false;
    return location.pathname === path || location.pathname.startsWith(path + '/');
  };

  const currentOrg = organizations.find((org) => org.id === currentOrgId) ?? null;

  return (
    <div className="min-h-screen bg-zinc-50 flex">
      {/* Mobile Sidebar Overlay */}
      {isSidebarOpen && (
        <div 
          className="fixed inset-0 bg-black/50 z-40 lg:hidden"
          onClick={() => setIsSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside className={cn(
        "fixed lg:static inset-y-0 left-0 z-50 w-64 bg-zinc-900 text-white transform transition-transform duration-200 ease-in-out lg:translate-x-0",
        isSidebarOpen ? "translate-x-0" : "-translate-x-full"
      )}>
        <div className="h-16 flex items-center px-6 border-b border-zinc-800">
          <img src={getBrandLogo(brand?.logo_url)} alt={brand?.app_name || BRAND_NAME} draggable={false} loading="lazy" className="w-7 h-7 rounded-md object-cover border border-zinc-700 mr-2" />
          <span className="font-bold text-lg tracking-tight">{brand?.app_name || BRAND_NAME} Admin</span>
        </div>

        <nav className="p-4 space-y-1 overflow-y-auto [scrollbar-color:#3f3f46_transparent] [scrollbar-width:thin]" style={{ maxHeight: 'calc(100vh - 8.5rem)' }}>
          {menuItems.map((item) => {
            const isActive = isActivePath(item.path);
            return (
              <Link
                key={item.path}
                to={item.path}
                onClick={() => setIsSidebarOpen(false)}
                className={cn(
                  "flex items-center gap-3 px-4 py-3 rounded-lg transition-colors",
                  isActive 
                    ? "bg-yellow-400 text-zinc-900 font-bold" 
                    : "text-zinc-400 hover:bg-zinc-800 hover:text-white"
                )}
              >
                <item.icon className="w-5 h-5" />
                {item.label}
              </Link>
            );
          })}

          <div className="pt-4 pb-2 px-2 text-xs font-bold text-zinc-500 uppercase tracking-wider">
            Produk Digital
          </div>
          {productMenuItems.map((item) => {
            const isActive = isActivePath(item.path, item.exact);
            return (
              <Link
                key={item.path}
                to={item.path}
                onClick={() => setIsSidebarOpen(false)}
                className={cn(
                  "flex items-center gap-3 px-4 py-3 rounded-lg transition-colors",
                  isActive
                    ? "bg-yellow-400 text-zinc-900 font-bold"
                    : "text-zinc-400 hover:bg-zinc-800 hover:text-white"
                )}
              >
                <item.icon className="w-5 h-5" />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="absolute bottom-0 left-0 right-0 p-4 border-t border-zinc-800">
          <button onClick={() => void handleLogout()} className="flex items-center gap-3 px-4 py-3 w-full text-zinc-400 hover:text-red-400 hover:bg-zinc-800 rounded-lg transition-colors">
            <LogOut className="w-5 h-5" />
            Keluar
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Header */}
        <header className="h-16 bg-white border-b border-zinc-200 flex items-center justify-between px-4 text-zinc-900 lg:px-8">
          <button 
            onClick={() => setIsSidebarOpen(true)}
            className="p-2 -ml-2 lg:hidden text-zinc-600 hover:bg-zinc-100 rounded-lg"
          >
            <Menu className="w-6 h-6" />
          </button>
          
            <div className="flex items-center gap-4 ml-auto">
              {organizations.length > 0 && (
                <select
                  value={currentOrgId ?? ''}
                  onChange={(event) => {
                    const nextOrgId = Number(event.target.value);
                    if (Number.isFinite(nextOrgId) && nextOrgId > 0) {
                      void handleSwitchOrganization(nextOrgId);
                    }
                  }}
                  disabled={switchingOrg}
                  className="hidden md:block rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-700 focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none disabled:opacity-60"
                  title="Ganti organisasi aktif"
                >
                  {organizations.map((org) => (
                    <option key={org.id} value={org.id}>
                      {org.name} ({org.role})
                    </option>
                  ))}
                </select>
              )}
              <NotificationBell />
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-full bg-zinc-900 flex items-center justify-center text-white font-bold text-xs">
                  {adminInitials}
                </div>
                <span className="hidden text-sm font-medium text-zinc-700 sm:block">{adminName}</span>
              </div>
            </div>
        </header>

        <div className="px-4 lg:px-8 py-3 border-b border-zinc-100 bg-yellow-50/70">
          <div className="text-xs sm:text-sm text-zinc-700 flex items-center gap-2">
            <span className="font-semibold text-zinc-900">Organisasi aktif:</span>
            <span className="font-medium">{currentOrg ? `${currentOrg.name} (${currentOrg.role})` : 'Belum ada organisasi aktif'}</span>
          </div>
        </div>

        {/* Page Content */}
        <main className="flex-1 overflow-y-auto p-4 text-zinc-900 lg:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
