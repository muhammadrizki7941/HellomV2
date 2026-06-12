import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { clearSession, getToken, setSession, ssoLogin } from '@/lib/hellomApi';

export default function PosAdmin() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const bootstrapPosAccess = async () => {
      const ssoToken = (searchParams.get('sso_token') || '').trim();
      const existingToken = getToken();

      if (ssoToken) {
        try {
          const result = await ssoLogin(ssoToken);
          setSession(result.token, result.user);
          navigate('/pos/admin-dashboard', { replace: true });
          return;
        } catch (ssoError) {
          if (existingToken) {
            navigate('/pos/admin-dashboard', { replace: true });
            return;
          }
          clearSession();
          const message = ssoError instanceof Error ? ssoError.message : 'Akses POS gagal diproses';
          setError(message);
          navigate('/login?app=pos', { replace: true });
          return;
        }
      }

      if (existingToken) {
        navigate('/pos/admin-dashboard', { replace: true });
        return;
      }

      navigate('/login?app=pos', { replace: true });
    };

    void bootstrapPosAccess();
  }, [navigate, searchParams]);

  return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="text-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-amber-400 mx-auto mb-4"></div>
        <p className="text-lg">{error ? 'Akses POS gagal, mengalihkan ke login...' : 'Logging into POS...'}</p>
      </div>
    </div>
  );
}
