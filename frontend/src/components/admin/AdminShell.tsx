import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Activity, Bell, Building2, ChevronRight, FileText, Film, Headset, LayoutDashboard, LogOut, Menu, Package, Palette, Receipt, ScrollText, Search, Settings, ShieldAlert, ShoppingBag, Users, Wallet, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { BRAND_NAME, getBrandLogo } from '@/lib/branding';
import type { BrandSettings } from '@/hooks/useBrand';
import type { AdminNotificationsState } from '@/hooks/useNotifications';
import NotificationBell from './NotificationBell';
import '@/styles/admin-control.css';

type Props = {
  children: ReactNode;
  brand: BrandSettings | null;
  adminName: string;
  adminInitials: string;
  notifications: AdminNotificationsState;
  organizations: Array<{ id: number; name: string; role?: string }>;
  currentOrgId: number | null;
  switchingOrg: boolean;
  onSwitchOrganization: (id: number) => Promise<void>;
  onLogout: () => Promise<void>;
};

/** Presentation-only shell. Authentication, organization switching, and live data stay in the layout. */
export default function AdminShell({ children, brand, adminName, adminInitials, notifications, organizations, currentOrgId, switchingOrg, onSwitchOrganization, onLogout }: Props) {
  const location = useLocation();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [mobile, setMobile] = useState(() => window.matchMedia('(max-width: 1023px)').matches);
  const sidebarRef = useRef<HTMLElement>(null);
  const [switchError, setSwitchError] = useState('');

  useEffect(() => {
    const media = window.matchMedia('(max-width: 1023px)');
    const update = () => { setMobile(media.matches); if (!media.matches) setOpen(false); };
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);

  useEffect(() => {
    if (!open || !mobile) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const sidebar = sidebarRef.current;
    sidebar?.querySelector<HTMLButtonElement>('button')?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); setOpen(false); }
      if (event.key !== 'Tab' || !sidebar) return;
      const items = Array.from(sidebar.querySelectorAll<HTMLElement>('a[href], button:not(:disabled), input, select'))
        .filter(element => element.getClientRects().length > 0);
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', onKey);
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, [open, mobile]);

  const groups = [
    { label: 'Workspace', items: [
      { icon: LayoutDashboard, label: 'Ringkasan', path: '/admin', badge: 0 },
      { icon: Users, label: 'Pengguna', path: '/admin/users', badge: 0 },
      { icon: Building2, label: 'Organisasi', path: '/admin/organizations', badge: 0 },
      { icon: Package, label: 'Aplikasi & Paket', path: '/admin/apps', badge: 0 },
    ] },
    { label: 'Keuangan', items: [
      { icon: Wallet, label: 'Keuangan Platform', path: '/admin/finance', badge: 0 },
      { icon: Wallet, label: 'Keuangan Penjual', path: '/admin/keuangan-penjual', badge: notifications.badges.openWithdrawals },
      { icon: Receipt, label: 'Invoice', path: '/admin/invoices', badge: 0 },
      { icon: ShoppingBag, label: 'Pembelian', path: '/admin/products/purchases', badge: 0 },
    ] },
    { label: 'Produk & konten', items: [
      { icon: Package, label: 'Kelola Produk', path: '/admin/products', badge: 0 },
      { icon: ShieldAlert, label: 'Moderasi Toko', path: '/admin/moderasi-toko', badge: 0 },
      { icon: Film, label: 'Showcase', path: '/admin/showcase', badge: 0 },
      { icon: FileText, label: 'Konten Situs', path: '/admin/landing-content', badge: 0 },
      { icon: Palette, label: 'Branding', path: '/admin/brand', badge: 0 },
    ] },
    { label: 'Pemantauan', items: [
      { icon: Activity, label: 'Analitik & Iklan', path: '/admin/marketing', badge: 0 },
      { icon: Bell, label: 'Notifikasi', path: '/admin/notifications', badge: notifications.unreadCount },
      { icon: Headset, label: 'Pusat Bantuan', path: '/admin/support', badge: 0 },
      { icon: ScrollText, label: 'Log Audit', path: '/admin/audit-log', badge: 0 },
      { icon: Activity, label: 'Kesehatan Sistem', path: '/admin/system', badge: 0 },
    ] },
    { label: 'Konfigurasi', items: [
      { icon: Settings, label: 'Pengaturan', path: '/admin/settings', badge: 0 },
    ] },
  ];
  const active = (path: string) => {
    if (path === '/admin') return location.pathname === path;
    if (path === '/admin/products' && location.pathname.startsWith('/admin/products/purchases')) return false;
    return location.pathname === path || location.pathname.startsWith(path + '/');
  };
  const currentItem = groups.flatMap(group => group.items).find(item => active(item.path));
  const currentGroup = groups.find(group => group.items.some(item => active(item.path)));
  const visibleGroups = groups.map(group => ({ ...group, items: group.items.filter(item =>
    `${group.label} ${item.label}`.toLocaleLowerCase('id-ID').includes(search.trim().toLocaleLowerCase('id-ID')),
  ) })).filter(group => group.items.length);
  const close = () => { setOpen(false); setSearch(''); };

  return (
    <div className="admin-control">
      <a href="#admin-main" className="admin-skip-link">Langsung ke konten</a>
      {open && mobile && <div className="admin-nav-backdrop" aria-hidden="true" onClick={close} />}
      <aside id="admin-navigation" ref={sidebarRef} className={cn('admin-sidebar', open && 'is-open')}
        inert={mobile && !open} role={mobile && open ? 'dialog' : undefined}
        aria-modal={mobile && open ? true : undefined} aria-label="Navigasi Super Admin">
        <div className="admin-sidebar-brand">
          <Link to="/admin" onClick={close} className="admin-brand-link">
            <img src={getBrandLogo(brand?.logo_url)} alt="" className="h-9 w-9 rounded-xl object-cover" />
            <span>{brand?.app_name || BRAND_NAME}<small>CONTROL CENTER</small></span>
          </Link>
          <button className="admin-icon-button admin-sidebar-close" aria-label="Tutup menu" onClick={close}><X size={20} /></button>
        </div>
        <label className="admin-menu-search"><Search size={16} aria-hidden="true" />
          <input aria-label="Cari menu admin" placeholder="Cari menu…" value={search} onChange={event => setSearch(event.target.value)} />
        </label>
        <nav className="admin-menu" aria-label="Menu utama">
          {visibleGroups.map(group => <section key={group.label}>
            <p className="admin-menu-label">{group.label}</p>
            {group.items.map(item => <Link key={item.path} to={item.path} onClick={close}
              aria-current={active(item.path) ? 'page' : undefined} className={cn('admin-menu-link', active(item.path) && 'is-active')}>
              <item.icon size={18} aria-hidden="true" /><span>{item.label}</span>
              {!!item.badge && <span className="admin-menu-badge">{item.badge > 99 ? '99+' : item.badge}</span>}
            </Link>)}
          </section>)}
          {visibleGroups.length === 0 && <p className="px-3 py-6 text-sm text-slate-300">Menu tidak ditemukan.</p>}
        </nav>
        <div className="admin-sidebar-account"><div className="admin-avatar">{adminInitials}</div>
          <div className="min-w-0 flex-1"><p className="truncate">{adminName}</p><span>Super Admin</span></div>
          <button onClick={() => void onLogout()} className="admin-icon-button" aria-label="Keluar" title="Keluar"><LogOut size={18} /></button>
        </div>
      </aside>
      <div className="admin-workspace" inert={mobile && open}>
        <header className="admin-topbar">
          <div className="admin-topbar-row">
            <button className="admin-icon-button admin-mobile-menu" onClick={() => setOpen(true)} aria-label="Buka menu" aria-expanded={open} aria-controls="admin-navigation"><Menu size={21} /></button>
            <div className="admin-breadcrumb"><span>{currentGroup?.label || 'Super Admin'}</span><ChevronRight size={14} /><strong>{currentItem?.label || 'Super Admin'}</strong></div>
            <div className="admin-topbar-actions"><NotificationBell state={notifications} /><span className="admin-avatar" title={adminName}>{adminInitials}</span></div>
          </div>
          <div className="admin-context"><span className="admin-scope-label"><span />Panel Super Admin</span>
            {organizations.length > 0 ? <label className="admin-org-select"><span>Organisasi aktif</span><select aria-label="Ganti organisasi aktif"
              value={currentOrgId ?? ''} disabled={switchingOrg}
              onChange={event => { const id = Number(event.target.value); if (id > 0) { setSwitchError(''); void onSwitchOrganization(id).catch(() => setSwitchError('Gagal mengganti organisasi. Silakan coba lagi.')); } }}>
              {!organizations.some(org => org.id === currentOrgId) && <option value="">Pilih organisasi</option>}
              {organizations.map(org => <option key={org.id} value={org.id}>{org.name} ({org.role})</option>)}
            </select></label> : <span className="text-xs text-slate-500">Belum ada organisasi aktif</span>}
          </div>
          {switchError && <p className="px-4 pb-3 text-sm text-red-700" role="alert">{switchError}</p>}
        </header>
        <main id="admin-main" className="admin-content" tabIndex={-1}>{children}</main>
        <nav className="admin-mobile-nav" aria-label="Akses cepat admin">
          {[{ path: '/admin', label: 'Ringkasan', icon: LayoutDashboard }, { path: '/admin/users', label: 'Pengguna', icon: Users }, { path: '/admin/keuangan-penjual', label: 'Keuangan', icon: Wallet }].map(item => <Link key={item.path} to={item.path} aria-current={active(item.path) ? 'page' : undefined} className={cn(active(item.path) && 'is-active')}><item.icon size={20} /><span>{item.label}</span></Link>)}
          <button onClick={() => setOpen(true)} aria-expanded={open} aria-controls="admin-navigation"><Menu size={20} /><span>Menu</span></button>
        </nav>
      </div>
    </div>
  );
}
