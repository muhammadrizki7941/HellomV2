import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { magicLogin, setSession } from '@/lib/hellomApi';
import CheckoutShell, { panelClass, primaryButtonClass } from '@/components/checkout/CheckoutShell';

// Only same-app paths (e.g. /dashboard/products/x); never protocol-relative or absolute URLs.
const safeRedirect = (path: string | null | undefined) =>
  path && path.startsWith('/') && !path.startsWith('//') ? path : '/dashboard';

// Opens the single-use sign-in link from the access email (/auth/magic?token=...).
export default function MagicLoginPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get('token') || '';
  const [error, setError] = useState<string | null>(token ? null : 'Link masuk tidak lengkap.');
  // The link is single-use: guard against React StrictMode running the effect twice.
  const started = useRef(false);

  useEffect(() => {
    if (!token || started.current) return;
    started.current = true;

    magicLogin(token)
      .then((result) => {
        setSession(result.token, result.user);
        navigate(safeRedirect(result.redirect_path), { replace: true });
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Link masuk tidak valid.'));
  }, [navigate, token]);

  return (
    <CheckoutShell>
      <div className={`${panelClass} mx-auto max-w-md text-center`}>
        {error ? (
          <>
            <p className="text-lg font-semibold">Link tidak bisa dipakai</p>
            <p className="mt-2 text-sm text-[#8B8B90]">{error}</p>
            <Link to="/login" className={`${primaryButtonClass} mt-6`}>Masuk dengan email &amp; password</Link>
            <Link to="/forgot-password" className="mt-4 inline-block text-sm text-[#F6B400]">Lupa password?</Link>
          </>
        ) : (
          <div className="flex flex-col items-center gap-3 py-6">
            <Loader2 className="h-6 w-6 animate-spin text-[#F6B400]" />
            <p className="text-sm text-[#B5B5BA]">Membuka produk Anda...</p>
          </div>
        )}
      </div>
    </CheckoutShell>
  );
}
