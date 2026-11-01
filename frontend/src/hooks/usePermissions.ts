import { useCallback, useEffect, useState } from 'react';
import { getSessionEventName, getSessionPosAccess, type PosAccess } from '@/lib/hellomApi';

export function usePermissions() {
  const [access, setAccess] = useState<PosAccess | null>(() => getSessionPosAccess());

  useEffect(() => {
    const refresh = () => setAccess(getSessionPosAccess());
    const eventName = getSessionEventName();
    window.addEventListener(eventName, refresh);
    refresh();

    return () => window.removeEventListener(eventName, refresh);
  }, []);

  const isPosStaff = access?.is_cashier === true;
  const role = access?.business_role ?? (access?.pos_role === 'admin' ? 'outlet_admin' : access?.pos_role);
  const isCashier = isPosStaff && role === 'cashier';
  const isOutletAdmin = isPosStaff && role === 'outlet_admin';
  const can = useCallback(
    (permission: string) => permission === 'organization_admin'
      ? access?.organization_role === 'org_admin'
      : !isPosStaff || permission === 'orders' || access?.permissions?.[permission] === true,
    [access, isPosStaff]
  );

  return {
    access,
    isPosStaff,
    isCashier,
    isOutletAdmin,
    isOrganizationAdmin: access?.organization_role === 'org_admin',
    can,
    canViewCostPrice: !isCashier,
  };
}
