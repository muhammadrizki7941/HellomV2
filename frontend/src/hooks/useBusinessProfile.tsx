import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { getPosBusinessProfile, type PosBusinessProfile, type PosModuleKey } from '@/services/api/pos';
import { getSessionEventName, getToken } from '@/lib/hellomApi';
import { getPosTerminology, type PosTerminology } from '@/config/posTerminology';

type BusinessProfileContextValue = {
  profile: PosBusinessProfile | null;
  loading: boolean;
  error: string | null;
  terminology: PosTerminology;
  refresh: () => Promise<void>;
  hasModule: (module: PosModuleKey) => boolean;
};

const BusinessProfileContext = createContext<BusinessProfileContextValue | null>(null);

export function BusinessProfileProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile] = useState<PosBusinessProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setProfile(null);
      setError(null);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);
    try {
      setProfile(await getPosBusinessProfile());
    } catch (reason) {
      setProfile(null);
      setError(reason instanceof Error ? reason.message : 'Profil usaha gagal dimuat.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const eventName = getSessionEventName();
    window.addEventListener(eventName, refresh);
    void refresh();
    return () => window.removeEventListener(eventName, refresh);
  }, [refresh]);

  const hasModule = useCallback(
    (module: PosModuleKey) => profile?.enabled_modules.includes(module) ?? false,
    [profile]
  );
  const value = useMemo(
    () => ({
      profile,
      loading,
      error,
      terminology: getPosTerminology(profile?.business_type ?? 'fnb'),
      refresh,
      hasModule,
    }),
    [profile, loading, error, refresh, hasModule]
  );

  return <BusinessProfileContext.Provider value={value}>{children}</BusinessProfileContext.Provider>;
}

export function useBusinessProfile(): BusinessProfileContextValue {
  const context = useContext(BusinessProfileContext);
  if (!context) {
    throw new Error('useBusinessProfile harus digunakan di dalam BusinessProfileProvider.');
  }
  return context;
}

export function useModule(module: PosModuleKey): boolean {
  return useBusinessProfile().hasModule(module);
}
