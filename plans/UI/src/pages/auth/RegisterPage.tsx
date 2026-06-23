import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { register, setSession, setActiveOutletId } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';
import { AuthLayout } from '@/components/auth';
import { continuePendingCheckoutAfterAuth } from '@/lib/checkoutIntent';

export default function RegisterPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { brand, logoSrc } = useBrand();
  const [organizationName, setOrganizationName] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const targetApp = searchParams.get('app');
  const subscribeIntent = searchParams.get('subscribe') === '1';
  const inviteToken = (searchParams.get('inviteToken') || '').trim();
  const invitedRegistration = inviteToken !== '';
  const loginHref = `/login${targetApp || subscribeIntent ? `?${new URLSearchParams({
    ...(targetApp ? { app: targetApp } : {}),
    ...(subscribeIntent ? { subscribe: '1' } : {}),
  }).toString()}` : ''}`;

  useEffect(() => {
    document.title = `${brand.register_title} | ${brand.app_name}`;
  }, [brand]);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const result = await register({
        name,
        email,
        password,
        organization_name: invitedRegistration ? undefined : organizationName,
        invite_token: invitedRegistration ? inviteToken : undefined,
      });

      setSession(result.token, result.user);

      // POS cashiers (registered via invite link) land straight in POS.
      const posAccess = (result.user as { pos_access?: { is_cashier?: boolean; outlet_id?: number | null } } | null)?.pos_access;
      if (posAccess?.is_cashier) {
        if (posAccess.outlet_id) setActiveOutletId(posAccess.outlet_id);
        navigate('/pos/orders');
        return;
      }

      try {
        const continuedCheckout = await continuePendingCheckoutAfterAuth(navigate);
        if (continuedCheckout) return;
      } catch (checkoutError) {
        setError(checkoutError instanceof Error ? checkoutError.message : 'Checkout gagal dilanjutkan');
        return;
      }
      
      // If subscribing to an app, redirect to that subscription flow
      if (subscribeIntent && targetApp === 'pos') {
        navigate(`/dashboard/apps/pos?subscribe=1`);
        return;
      }

      const intendedUrl = localStorage.getItem('hellom_intended_url');
      if (intendedUrl) {
        localStorage.removeItem('hellom_intended_url');
        navigate(intendedUrl);
        return;
      }

      navigate('/dashboard');
    } catch (submitError) {
      const message = submitError instanceof Error ? submitError.message : 'Registrasi gagal';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout brand={brand} logoSrc={logoSrc} variant="register" footerText={brand.footer_text}>
      <section
        className="rounded-[28px] border border-white/[0.08] bg-white/[0.035] p-6 text-[#F5F5F2] shadow-[0_24px_80px_rgba(0,0,0,0.35)] backdrop-blur-xl sm:p-8"
      >
        <div className="mb-6 space-y-2">
          <p className="text-xs uppercase tracking-[0.3em] text-[#F6B400]">Registrasi Hellom</p>
          <h2 className="text-2xl font-semibold" style={{ fontFamily: 'Inter, "Plus Jakarta Sans", "Geist", sans-serif' }}>
            Bikin akun gratis
          </h2>
          <p className="text-sm text-[#8B8B90]">Daftar gratis untuk membuka dashboard, POS payment, dan ekosistem produk digital Hellom.</p>
        </div>

        <form className="space-y-4" onSubmit={handleSubmit}>
          {!invitedRegistration && (
            <div>
              <label className="mb-2 block text-sm font-medium text-white/80">Nama Bisnis / Organisasi</label>
              <input
                type="text"
                required
                value={organizationName}
                onChange={(e) => setOrganizationName(e.target.value)}
                className="h-[54px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15"
                placeholder="Contoh: Toko Kopi Senja"
              />
            </div>
          )}

          {invitedRegistration && (
            <div className="rounded-2xl border border-[#F6B400]/30 bg-[#F6B400]/10 px-4 py-3 text-sm text-[#F5F5F2]">
              Registrasi ini berasal dari undangan tim. Pastikan email yang kamu masukkan sama dengan email tujuan undangan.
            </div>
          )}

          <div>
            <label className="mb-2 block text-sm font-medium text-white/80">Nama Lengkap Anda</label>
            <input
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="h-[54px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15"
              placeholder="Contoh: Budi Santoso"
            />
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-white/80">Email</label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="h-[54px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15"
              placeholder="nama@email.com"
            />
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-white/80">Password</label>
            <div className="relative">
              <input
                type={showPassword ? 'text' : 'password'}
                required
                minLength={8}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="h-[54px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 pr-12 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15"
                placeholder="Minimal 8 karakter"
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
            {loading ? 'Memproses...' : subscribeIntent ? 'Daftar & Lanjut Aktivasi' : 'Daftar Gratis Sekarang'}
          </button>
        </form>

        <p className="mt-5 text-center text-sm text-[#8B8B90]">
          Sudah punya akun? <Link to={loginHref} className="font-semibold text-[#F6B400] hover:underline">Masuk disini</Link>
        </p>
      </section>
    </AuthLayout>
  );
}
