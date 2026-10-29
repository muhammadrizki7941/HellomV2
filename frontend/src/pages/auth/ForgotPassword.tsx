import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import HellomMascot from '@/components/HellomMascot';
import { forgotPassword } from '@/lib/hellomApi';
import { LightAuthAlert as AuthAlert, LightAuthField as AuthField, LightAuthLayout as AuthShell, lightAuthButton as authPrimaryButton } from '@/components/auth/LightAuthLayout';

/**
 * "Lupa kata sandi": we email a link to /reset-password. Same answer whether or not the email
 * has an account. POS staff the owner registered by email (no account yet) get an activation
 * link for their store instead.
 */
export default function ForgotPassword() {
  const [searchParams] = useSearchParams();
  const [email, setEmail] = useState(searchParams.get('email') ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sentTo, setSentTo] = useState<string | null>(null);

  useEffect(() => {
    document.title = 'Lupa kata sandi | Hellom';
  }, []);

  const submit = async (event?: React.FormEvent) => {
    event?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await forgotPassword(email.trim());
      setSentTo(email.trim());
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Belum bisa mengirim link. Coba lagi sebentar lagi.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthShell variant="recovery">
      {sentTo ? (
        <div>
          <HellomMascot mood="success" className="!w-24" />
          <h1 className="mt-5 text-2xl font-bold">Cek email kamu</h1>
          <p className="mt-2 text-sm leading-relaxed text-zinc-600">
            Kalau <strong className="text-zinc-900">{sentTo}</strong> terdaftar di Hellom, kami sudah mengirim link untuk membuat kata sandi baru.
            Link berlaku 60 menit. Tidak ada di kotak masuk? Cek folder Spam atau Promosi.
          </p>
          {error && <div className="mt-4"><AuthAlert>{error}</AuthAlert></div>}
          <div className="mt-6 space-y-3">
            <button type="button" onClick={() => void submit()} disabled={busy}
              className="flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 text-sm font-semibold text-zinc-900 hover:border-[#F6B400] disabled:opacity-60">
              {busy && <Loader2 className="h-4 w-4 animate-spin" />} Kirim ulang link
            </button>
            <button type="button" onClick={() => setSentTo(null)} className="flex min-h-11 w-full items-center justify-center text-sm text-zinc-600 hover:text-zinc-950">Pakai email lain</button>
          </div>
        </div>
      ) : (
        <>
          <h1 className="text-2xl font-bold">Lupa kata sandi?</h1>
          <p className="mt-2 text-sm leading-relaxed text-zinc-600">Masukkan email akun kamu. Kami kirim link untuk membuat kata sandi baru — berlaku juga untuk kasir & staf toko.</p>
          <form className="mt-6 space-y-4" onSubmit={submit}>
            <AuthField id="forgot-email" label="Email" type="email" inputMode="email" autoComplete="email" required autoFocus
              value={email} onChange={(e) => setEmail(e.target.value)} placeholder="nama@email.com" />
            {error && <AuthAlert>{error}</AuthAlert>}
            <button type="submit" disabled={busy} className={authPrimaryButton}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />} {busy ? 'Mengirim…' : 'Kirim link'}
            </button>
          </form>
        </>
      )}
      <div className="mt-8 flex flex-wrap items-center justify-between gap-2 text-sm">
        <Link to="/login" className="inline-flex min-h-11 items-center text-zinc-600 hover:text-zinc-950">Masuk pemilik</Link>
        <Link to="/login/kasir" className="inline-flex min-h-11 items-center font-semibold text-amber-700 hover:underline">Masuk kasir & staf</Link>
      </div>
    </AuthShell>
  );
}
