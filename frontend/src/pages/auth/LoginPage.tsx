import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { LazyMotion, domAnimation, m, useReducedMotion } from 'framer-motion';
import { Eye, EyeOff, Loader2 } from 'lucide-react';
import { login, setSession, setActiveOutletId } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';
import { continuePendingCheckoutAfterAuth } from '@/lib/checkoutIntent';

export default function LoginPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { brand, logoSrc } = useBrand();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const reduced = useReducedMotion();

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
    <LazyMotion features={domAnimation} strict>
      <div className="relative flex min-h-screen flex-col bg-[#050505] text-[#F5F5F2]">
        {/* One very soft gold glow + static grain; nothing else competes with the form. */}
        <div aria-hidden className="pointer-events-none fixed inset-0 bg-[radial-gradient(circle_at_50%_30%,rgba(246,180,0,.10),transparent_45%)]" />
        <div aria-hidden className="bg-grain pointer-events-none fixed inset-0 opacity-[0.04]" />

        <header className="relative z-10 px-6 py-6">
          <Link to="/" className="inline-flex min-h-11 min-w-11 items-center">
            {logoSrc ? (
              <img src={logoSrc} alt={brand.app_name || 'Hellom'} className="h-7 w-auto object-contain" />
            ) : (
              <span className="text-xl font-black">Hell<span className="text-[#F6B400]">om</span></span>
            )}
            <span className="sr-only"> — kembali ke Beranda</span>
          </Link>
        </header>

        <main className="relative z-10 flex flex-1 items-center justify-center px-5 pb-16">
          <m.section
            aria-labelledby="judul-masuk"
            className="w-full max-w-[400px] rounded-2xl border border-white/[0.10] bg-[#0B0B0E]/85 p-7 shadow-[0_24px_80px_rgba(0,0,0,0.45)] backdrop-blur-xl sm:p-8"
            initial={reduced ? { opacity: 0 } : { opacity: 0, y: 20 }}
            animate={reduced ? { opacity: 1 } : { opacity: 1, y: 0 }}
            transition={{ duration: reduced ? 0.15 : 0.6, ease: [0.22, 1, 0.36, 1] }}
          >
            <h1 id="judul-masuk" className="overflow-hidden font-display text-3xl font-medium leading-tight sm:text-4xl">
              <m.span
                className="block"
                initial={reduced ? { opacity: 0 } : { y: '100%' }}
                animate={reduced ? { opacity: 1 } : { y: '0%' }}
                transition={{ duration: reduced ? 0.15 : 0.6, ease: [0.22, 1, 0.36, 1], delay: reduced ? 0 : 0.08 }}
              >
                Masuk ke Hellom
              </m.span>
            </h1>
            {contextText ? (
              <p className="mt-4 rounded-xl border border-[#F6B400]/25 bg-[#F6B400]/[0.08] px-4 py-3 text-xs leading-relaxed text-[#E8E8EA]">
                {contextText}
              </p>
            ) : null}

            <form className="mt-7 space-y-4" onSubmit={handleSubmit}>
              <div>
                <label htmlFor="email" className="mb-2 block text-sm font-medium text-white/80">Email</label>
                <input
                  id="email"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  aria-invalid={Boolean(error)}
                  className="h-12 w-full rounded-xl border border-white/[0.12] bg-black/40 px-4 text-base text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/25"
                  placeholder="nama@email.com"
                />
              </div>

              <div>
                <label htmlFor="password" className="mb-2 block text-sm font-medium text-white/80">Kata sandi</label>
                <div className="relative">
                  <input
                    id="password"
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? 'galat-masuk' : undefined}
                    className="h-12 w-full rounded-xl border border-white/[0.12] bg-black/40 px-4 pr-12 text-base text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/25"
                    placeholder="Masukkan kata sandi"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    aria-label={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                    aria-pressed={showPassword}
                    className="absolute right-1 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-white/55 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#F6B400]"
                  >
                    {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
              </div>

              {error ? (
                <p id="galat-masuk" role="alert" className="rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                  {error}
                </p>
              ) : null}

              <button
                type="submit"
                disabled={loading}
                aria-busy={loading}
                className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#F6B400] text-sm font-bold text-[#050505] transition-colors hover:bg-[#FFCC47] focus:outline-none focus-visible:ring-4 focus-visible:ring-[#F6B400]/40 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
                {loading ? 'Memproses...' : 'Masuk'}
              </button>
            </form>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
              <Link to="/forgot-password" className="inline-flex min-h-11 items-center text-[#A1A1A6] hover:text-white">
                Lupa kata sandi?
              </Link>
              <Link to={registerHref} className="inline-flex min-h-11 items-center px-1 font-semibold text-[#F6B400] hover:underline">
                Daftar
              </Link>
            </div>
            <Link to="/login/kasir" className="mt-3 flex min-h-11 items-center justify-center rounded-xl border border-white/[0.12] text-sm font-semibold text-white/80 hover:border-[#F6B400] hover:text-white">
              Kasir atau staf toko? Masuk di sini
            </Link>
          </m.section>
        </main>
      </div>
    </LazyMotion>
  );
}
