import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowRight, Loader2 } from 'lucide-react';
import { LightAuthLayout, LightAuthField, LightPasswordField, LightAuthAlert, lightAuthButton } from '@/components/auth/LightAuthLayout';
import { login, setSession, setActiveOutletId } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';
import { continuePendingCheckoutAfterAuth } from '@/lib/checkoutIntent';
import HellomLoading from '@/components/HellomLoading';

export default function LoginPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { brand } = useBrand();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const targetApp = searchParams.get('app');
  const subscribeIntent = searchParams.get('subscribe') === '1';
  const intendedUrl = typeof window !== 'undefined' ? localStorage.getItem('hellom_intended_url') : null;
  const productCheckoutIntent = Boolean(intendedUrl?.match(/^\/dashboard\/products\/[^/]+\/checkout$/));

  const contextText = useMemo(() => {
    if (productCheckoutIntent) {
      return 'Silakan login dulu untuk melakukan checkout produk ini ya. Jika kamu belum memiliki akun, silakan daftarkan akunmu terlebih dahulu lalu lanjutkan proses pembayaran dan produk kamu siap kamu pakai.';
    }
    if (targetApp === 'pos' && subscribeIntent) {
      return 'Login untuk melanjutkan aktivasi langganan POS.';
    }
    if (targetApp === 'pos') {
      return 'Login untuk membuka akses POS Anda.';
    }
    return null;
  }, [productCheckoutIntent, subscribeIntent, targetApp]);

  const registerHref = useMemo(() => {
    const params = new URLSearchParams();
    if (targetApp) params.set('app', targetApp);
    if (subscribeIntent) params.set('subscribe', '1');
    const inviteToken = (searchParams.get('inviteToken') || '').trim();
    if (inviteToken) params.set('inviteToken', inviteToken);
    const qs = params.toString();
    return `/register${qs ? `?${qs}` : ''}`;
  }, [searchParams, subscribeIntent, targetApp]);

  useEffect(() => {
    document.title = `${brand.login_title} | ${brand.app_name}`;
  }, [brand]);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const result = await login(email, password);
      setSession(result.token, result.user);
      const inviteToken = (searchParams.get('inviteToken') || '').trim();
      if (inviteToken) {
        navigate(`/invitation/accept?token=${encodeURIComponent(inviteToken)}`);
        return;
      }
      // POS cashiers land straight in POS, locked to their assigned outlet.
      const posAccess = (result.user as { pos_access?: { is_cashier?: boolean; outlet_id?: number | null } } | null)?.pos_access;
      if (posAccess?.is_cashier) {
        if (posAccess.outlet_id) setActiveOutletId(posAccess.outlet_id);
        navigate('/pos/orders');
        return;
      }
      const role = (result.user as { role?: string } | null)?.role;
      const isSuperAdmin = role === 'super_admin';
      const isTenantAdmin = role === 'admin' || role === 'tenant_admin';
      if (!isSuperAdmin) {
        try {
          const continuedCheckout = await continuePendingCheckoutAfterAuth(navigate);
          if (continuedCheckout) return;
        } catch (checkoutError) {
          setError(checkoutError instanceof Error ? checkoutError.message : 'Checkout gagal dilanjutkan');
          return;
        }
      }

      const intendedUrl = localStorage.getItem('hellom_intended_url');
      if (intendedUrl && !isSuperAdmin) {
        localStorage.removeItem('hellom_intended_url');
        navigate(intendedUrl);
        return;
      }
      if (!isTenantAdmin && !isSuperAdmin && targetApp === 'pos') {
        navigate(subscribeIntent ? '/dashboard/apps/pos?subscribe=1' : '/dashboard/apps/pos');
        return;
      }
      navigate(isSuperAdmin ? '/admin' : '/dashboard');
    } catch (submitError) {
      const message = submitError instanceof Error ? submitError.message : 'Login gagal';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <LightAuthLayout variant="login" aside={<Link to={registerHref} className="inline-flex min-h-11 items-center gap-1 font-semibold text-zinc-900">Daftar gratis <ArrowRight size={15} /></Link>}>
      {loading && <HellomLoading mode="overlay" label="Memeriksa akun dan menyiapkan halaman..." />}
      <h1 className="text-3xl font-semibold tracking-tight">Senang bertemu lagi.</h1>
      <p className="mt-2 text-sm leading-6 text-zinc-500">Masuk dan lanjutkan langkah hebat usahamu.</p>
      {contextText && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{contextText}</p>}
      <form className="mt-7 space-y-5" onSubmit={handleSubmit}>
        <LightAuthField id="email" label="Email" type="email" autoComplete="email" value={email} onChange={e => setEmail(e.target.value)} required placeholder="nama@email.com" />
        <LightPasswordField id="password" label="Kata sandi" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)} required placeholder="Masukkan kata sandi" />
        <div className="flex justify-end"><Link to="/forgot-password" className="inline-flex min-h-11 items-center text-sm font-medium text-amber-800 hover:underline">Lupa kata sandi?</Link></div>
        {error && <LightAuthAlert>{error}</LightAuthAlert>}
        <button type="submit" disabled={loading} aria-busy={loading} className={lightAuthButton}>
          {loading ? <Loader2 size={18} className="animate-spin" /> : null}{loading ? 'Memproses…' : 'Masuk ke akun'}{!loading && <ArrowRight size={17} />}
        </button>
      </form>
      <p className="mt-6 text-center text-sm text-zinc-500">Baru di Hellom? <Link to={registerHref} className="font-semibold text-amber-800 hover:underline">Buat akun gratis</Link></p>
      <div className="my-6 flex items-center gap-3 text-xs text-zinc-400"><span className="h-px flex-1 bg-zinc-100" />Untuk tim toko<span className="h-px flex-1 bg-zinc-100" /></div>
      <Link to="/login/kasir" className="flex min-h-12 items-center justify-center rounded-xl border border-zinc-200 px-3 text-sm font-medium text-zinc-600 transition hover:border-amber-300 hover:bg-amber-50">Masuk sebagai kasir atau staf</Link>
    </LightAuthLayout>
  );
}
