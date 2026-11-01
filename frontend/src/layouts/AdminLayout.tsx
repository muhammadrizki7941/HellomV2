import { useEffect, useMemo, useState } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import AdminShell from '@/components/admin/AdminShell';
import LiveToasts from '@/components/notifications/LiveToasts';
import { useNotifications } from '@/hooks/useNotifications';
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
import { fetchBrand, BrandSettings } from '@/hooks/useBrand';

export default function AdminLayout() {
  const navigate = useNavigate();
  const [adminName, setAdminName] = useState('Super Admin');
  const [organizations, setOrganizations] = useState<Array<{ id: number; name: string; role?: string }>>([]);
  const [currentOrgId, setCurrentOrgId] = useState<number | null>(null);
  const [switchingOrg, setSwitchingOrg] = useState(false);
  const [brand, setBrand] = useState<BrandSettings | null>(null);
  // One live notification state for the bell and the sidebar badges.
  const notificationState = useNotifications();

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

  return (
    <>
      <AdminShell brand={brand} adminName={adminName} adminInitials={adminInitials}
        notifications={notificationState} organizations={organizations} currentOrgId={currentOrgId}
        switchingOrg={switchingOrg} onSwitchOrganization={handleSwitchOrganization} onLogout={handleLogout}>
        <Outlet />
      </AdminShell>
      <LiveToasts />
    </>
  );
}
