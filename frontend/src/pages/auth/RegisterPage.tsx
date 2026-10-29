import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import { register, setSession, setActiveOutletId } from '@/lib/hellomApi';
import { continuePendingCheckoutAfterAuth } from '@/lib/checkoutIntent';
import { LightAuthAlert as AuthAlert, LightAuthField as AuthField, LightAuthLayout as AuthShell, LightPasswordField as PasswordField, lightAuthButton as authPrimaryButton } from '@/components/auth/LightAuthLayout';
import HellomLoading from '@/components/HellomLoading';

// Same idea as the backend's Str::slug: the shop address is the organization slug.
const slugify = (value: string) =>
  value.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);

export default function RegisterPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [organizationName, setOrganizationName] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const passwordChecks = [
    { label: 'Minimal 8 karakter', valid: password.length >= 8 },
    { label: 'Huruf & angka (disarankan)', valid: /[A-Za-z]/.test(password) && /\d/.test(password) },
  ];

  const targetApp = searchParams.get('app');
  const subscribeIntent = searchParams.get('subscribe') === '1';
  const inviteToken = (searchParams.get('inviteToken') || '').trim();
  const loginHref = `/login${targetApp || subscribeIntent ? `?${new URLSearchParams({
    ...(targetApp ? { app: targetApp } : {}),
    ...(subscribeIntent ? { subscribe: '1' } : {}),
  }).toString()}` : ''}`;
  const host = typeof window !== 'undefined' ? window.location.host.replace(/^www\./, '') : 'hellomspace.com';
  const handle = useMemo(() => slugify(organizationName), [organizationName]);

  useEffect(() => {
    document.title = 'Buat akun | Hellom';
  }, []);

  // Old invitation links (/register?inviteToken=…) → the invitation page.
  if (inviteToken) {
    return <Navigate to={`/invitation/accept?token=${encodeURIComponent(inviteToken)}`} replace />;
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const result = await register({ name: name.trim(), email: email.trim(), password, organization_name: organizationName.trim() });
      setSession(result.token, result.user);

      const posAccess = (result.user as { pos_access?: { is_cashier?: boolean; outlet_id?: number | null } } | null)?.pos_access;
      if (posAccess?.is_cashier) {
        if (posAccess.outlet_id) setActiveOutletId(posAccess.outlet_id);
        navigate('/pos/orders');
        return;
      }
      try {
        if (await continuePendingCheckoutAfterAuth(navigate)) return;
      } catch (checkoutError) {
        setError(checkoutError instanceof Error ? checkoutError.message : 'Checkout belum bisa dilanjutkan');
        return;
      }
      if (subscribeIntent && targetApp === 'pos') {
        navigate('/dashboard/apps/pos?subscribe=1');
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
      setError(submitError instanceof Error ? submitError.message : 'Pendaftaran belum berhasil. Coba lagi.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthShell wide aside={<Link to={loginHref} className="inline-flex min-h-11 items-center text-sm font-semibold text-zinc-600 hover:text-zinc-950">Masuk</Link>}>
      {loading && <HellomLoading mode="overlay" label="Membuat akun dan menyiapkan usaha..." />}
      <h1 className="text-[28px] font-bold leading-tight tracking-tight">Mulai usaha kamu di Hellom</h1>
      <p className="mt-2 text-sm text-zinc-600">Halaman toko, jualan produk digital, dan POS kasir — dalam satu akun.</p>

      {/* The one special thing: your shop's address, written as you type. */}
      <div className="mt-6 rounded-2xl border border-zinc-200 bg-amber-50/70 p-4" aria-live="polite">
        <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-500">
          <span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#F6B400] opacity-60" /><span className="relative inline-flex h-2 w-2 rounded-full bg-[#F6B400]" /></span>
          Alamat toko kamu
        </p>
        <p className="mt-2 truncate font-mono text-[15px] sm:text-base">
          <span className="text-zinc-500">{host}/</span>
          <span className={handle ? 'text-amber-700' : 'text-zinc-500'}>{handle || 'nama-usaha'}</span>
          <span className="ml-0.5 inline-block h-[1.1em] w-[2px] translate-y-[3px] animate-pulse bg-[#F6B400]" aria-hidden="true" />
        </p>
      </div>

      <form className="mt-6 space-y-4" onSubmit={handleSubmit}>
        <AuthField id="reg-org" label="Nama usaha" autoComplete="organization" required maxLength={255} value={organizationName}
          onChange={(e) => setOrganizationName(e.target.value)} placeholder="Contoh: Kopi Senja" hint="Bisa diganti nanti, termasuk alamat tokonya." />
        <AuthField id="reg-name" label="Nama kamu" autoComplete="name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="Contoh: Budi Santoso" />
        <AuthField id="reg-email" label="Email" type="email" inputMode="email" autoComplete="email" required value={email}
          onChange={(e) => setEmail(e.target.value)} placeholder="nama@email.com" />
        <PasswordField id="reg-password" label="Kata sandi" autoComplete="new-password" required minLength={8} value={password}
          onChange={(e) => setPassword(e.target.value)} placeholder="Minimal 8 karakter" />
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-live="polite">
          {passwordChecks.map(check => <span key={check.label} className={check.valid ? 'text-emerald-700' : 'text-zinc-500'}><span aria-hidden="true">{check.valid ? '✓' : '○'}</span> {check.label}{check.valid && <span className="sr-only">, terpenuhi</span>}</span>)}
        </div>

        {error && <AuthAlert>{error}</AuthAlert>}

        <button type="submit" disabled={loading} aria-busy={loading} className={authPrimaryButton}>
          {loading && <Loader2 className="h-4 w-4 animate-spin" />}
          {loading ? 'Membuat akun…' : subscribeIntent ? 'Buat akun & lanjut aktivasi' : 'Buat akun gratis'}
        </button>
        <p className="text-center text-xs leading-relaxed text-zinc-500">
          Dengan mendaftar, kamu setuju dengan <Link to="/terms" className="text-zinc-600 underline hover:text-zinc-950">Syarat & Ketentuan</Link> Hellom.
        </p>
      </form>

      <p className="mt-8 text-center text-sm text-zinc-600">
        Sudah punya akun? <Link to={loginHref} className="font-semibold text-amber-700 hover:underline">Masuk</Link>
        <span className="mx-2 text-zinc-500">·</span>
        <Link to="/login/kasir" className="font-semibold text-zinc-600 hover:text-zinc-950">Kasir & staf</Link>
      </p>
    </AuthShell>
  );
}
