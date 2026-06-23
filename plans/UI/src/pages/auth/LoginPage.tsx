import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { login, setSession, setActiveOutletId } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';
import { AuthLayout } from '@/components/auth';
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
    <AuthLayout brand={brand} logoSrc={logoSrc} variant="login" footerText={brand.footer_text}>
      <section
        className="rounded-[28px] border border-white/[0.08] bg-white/[0.035] p-6 text-[#F5F5F2] shadow-[0_24px_80px_rgba(0,0,0,0.35)] backdrop-blur-xl sm:p-8"
      >
        <div className="mb-6 space-y-2">
          <p className="text-xs uppercase tracking-[0.3em] text-[#F6B400]">Masuk ke Hellom</p>
          <h2 className="text-2xl font-semibold" style={{ fontFamily: 'Inter, "Plus Jakarta Sans", "Geist", sans-serif' }}>
            Selamat datang kembali
          </h2>
          <p className="text-sm text-[#8B8B90]">Masuk untuk mengelola bisnis, POS payment, dan produk digital Anda.</p>
          {contextText && (
            <div className="rounded-2xl border border-[#F6B400]/30 bg-[#F6B400]/10 px-4 py-3 text-xs font-medium text-[#F5F5F2]">
              {contextText}
            </div>
          )}
        </div>

        <form className="space-y-4" onSubmit={handleSubmit}>
          <div>
            <label className="mb-2 block text-sm font-medium text-white/80">Email</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              className="h-[54px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15"
              placeholder="nama@email.com"
            />
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-white/80">Password</label>
            <div className="relative">
              <input
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                className="h-[54px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 pr-12 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15"
                placeholder="Masukkan password"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-4 top-1/2 -translate-y-1/2 text-white/45 hover:text-white"
              >
                {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
              </button>
            </div>
          </div>

          {error && <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</div>}

          <button
            type="submit"
            disabled={loading}
            className="flex h-[54px] w-full items-center justify-center rounded-2xl bg-[#F6B400] text-sm font-bold text-[#050505] shadow-[0_16px_34px_rgba(246,180,0,0.22)] transition-transform duration-200 hover:-translate-y-0.5 hover:bg-[#FFCC47] disabled:opacity-50"
          >
            {loading ? 'Memproses...' : 'Masuk ke Dashboard'}
          </button>
        </form>

        <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-sm text-[#8B8B90]">
          <Link to="/forgot-password" className="hover:text-white">
            Lupa password?
          </Link>
          <Link to={registerHref} className="font-semibold text-[#F6B400] hover:underline">
            Belum punya akun? Daftar gratis
          </Link>
        </div>
      </section>
    </AuthLayout>
  );
}

