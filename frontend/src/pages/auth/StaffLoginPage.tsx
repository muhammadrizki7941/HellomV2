import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Eye, EyeOff, Loader2, Store } from 'lucide-react';
import { ApiError, setActiveOutletId, setSession, staffLogin } from '@/lib/hellomApi';
import type { StaffStoreChoice } from '@/lib/hellomApi';

/**
 * Staff / cashier login (/login/kasir). After the password check the backend switches the
 * account into the store where it is registered as POS staff, so a cashier who also owns or
 * belongs to another business still lands in the right store's POS.
 */
export default function StaffLoginPage() {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState<number | 'form' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [choices, setChoices] = useState<StaffStoreChoice[] | null>(null);

  useEffect(() => {
    document.title = 'Masuk Kasir & Staf | Hellom POS';
  }, []);

  const submit = async (staffId?: number) => {
    setError(null);
    setLoading(staffId ?? 'form');
    try {
      const result = await staffLogin(email.trim(), password, staffId);
      setSession(result.token, result.user);
      const access = (result.user as { pos_access?: { is_cashier?: boolean; outlet_id?: number | null } } | null)?.pos_access;
      if (access?.outlet_id) setActiveOutletId(access.outlet_id);
      // Owners/admins who are also staff get the normal POS home.
      navigate(access?.is_cashier ? '/pos/orders' : '/pos/admin-dashboard', { replace: true });
    } catch (err) {
      if (err instanceof ApiError && err.code === 'STAFF_CHOOSE_STORE' && Array.isArray(err.details.choices)) {
        setChoices(err.details.choices as StaffStoreChoice[]);
        return;
      }
      setChoices(null);
      setError(err instanceof Error ? err.message : 'Belum bisa masuk. Coba lagi.');
    } finally {
      setLoading(null);
    }
  };

  const inputClass = 'h-12 w-full rounded-xl border border-white/[0.12] bg-black/40 px-4 text-base text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/25';

  return (
    <div className="flex min-h-[100svh] flex-col bg-[#050505] text-[#F5F5F2]">
      <header className="px-6 py-6">
        <Link to="/" className="inline-flex min-h-11 items-center text-xl font-black">
          Hell<span className="text-[#F6B400]">om</span> <span className="ml-2 text-sm font-semibold text-white/60">POS</span>
        </Link>
      </header>

      <main className="flex flex-1 items-start justify-center px-5 pb-10 sm:items-center">
        <section className="w-full max-w-sm">
          <p className="text-xs font-semibold uppercase tracking-widest text-[#F6B400]">Kasir & staf</p>
          <h1 className="mt-2 text-2xl font-bold">Masuk ke toko kamu</h1>
          <p className="mt-2 text-sm text-white/60">Pakai email yang didaftarkan owner di POS › Staff. Kamu langsung masuk ke toko dan outlet tempat kamu bertugas.</p>

          {choices ? (
            <div className="mt-6 space-y-2" role="list" aria-label="Pilih toko">
              <p className="text-sm font-semibold">Kamu terdaftar di beberapa toko. Pilih yang mau dibuka:</p>
              {choices.map((c) => (
                <button key={c.staff_id} type="button" role="listitem" onClick={() => void submit(c.staff_id)} disabled={loading !== null}
                  className="flex min-h-14 w-full items-center gap-3 rounded-xl border border-white/[0.12] bg-white/[0.04] px-4 text-left hover:border-[#F6B400] disabled:opacity-60">
                  <Store className="h-5 w-5 shrink-0 text-[#F6B400]" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-semibold">{c.organization_name}</span>
                    {c.outlet_name && <span className="block truncate text-xs text-white/60">{c.outlet_name}</span>}
                  </span>
                  {loading === c.staff_id && <Loader2 className="h-4 w-4 animate-spin" />}
                </button>
              ))}
              <button type="button" onClick={() => setChoices(null)} className="min-h-11 text-sm text-white/60 underline">Ganti akun</button>
            </div>
          ) : (
            <form className="mt-6 space-y-4" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
              <div>
                <label htmlFor="staff-email" className="mb-2 block text-sm font-medium text-white/80">Email</label>
                <input id="staff-email" type="email" autoComplete="email" inputMode="email" required value={email}
                  onChange={(e) => setEmail(e.target.value)} placeholder="nama@email.com" aria-invalid={Boolean(error)} className={inputClass} />
              </div>
              <div>
                <label htmlFor="staff-password" className="mb-2 block text-sm font-medium text-white/80">Kata sandi</label>
                <div className="relative">
                  <input id="staff-password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" required value={password}
                    onChange={(e) => setPassword(e.target.value)} placeholder="Masukkan kata sandi" aria-invalid={Boolean(error)}
                    aria-describedby={error ? 'staff-login-error' : undefined} className={`${inputClass} pr-12`} />
                  <button type="button" onClick={() => setShowPassword(!showPassword)} aria-label={showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
                    className="absolute right-1 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-white/55 hover:text-white">
                    {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                  </button>
                </div>
              </div>
              {error && (
                <p id="staff-login-error" role="alert" className="rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{error}</p>
              )}
              <button type="submit" disabled={loading !== null}
                className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#F6B400] text-base font-bold text-[#050505] hover:bg-[#FFCC47] disabled:opacity-60">
                {loading === 'form' && <Loader2 className="h-4 w-4 animate-spin" />} {loading === 'form' ? 'Memproses…' : 'Masuk'}
              </button>
            </form>
          )}

          <div className="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
            <Link to="/forgot-password" className="inline-flex min-h-11 items-center text-white/60 hover:text-white">Lupa kata sandi?</Link>
            <Link to="/login?app=pos" className="inline-flex min-h-11 items-center font-semibold text-[#F6B400] hover:underline">Pemilik toko? Masuk di sini</Link>
          </div>
        </section>
      </main>
    </div>
  );
}
