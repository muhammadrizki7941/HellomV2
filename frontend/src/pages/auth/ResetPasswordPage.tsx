import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { resetPassword } from '@/lib/hellomApi';
import { AuthAlert, AuthShell, PasswordField, authPrimaryButton } from '@/components/auth/AuthShell';

/** /reset-password?token=…&email=… (link from the reset email): choose a new password. */
export default function ResetPasswordPage() {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [next, setNext] = useState<string | null>(null);

  useEffect(() => {
    document.title = 'Buat kata sandi baru | Hellom';
  }, []);

  const mismatch = confirm !== '' && confirm !== password;
  const strength = password.length === 0 ? 0 : password.length < 8 ? 1 : /[A-Za-z]/.test(password) && /\d/.test(password) && password.length >= 10 ? 3 : 2;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (password.length < 8 || mismatch) return;
    setBusy(true);
    setError(null);
    try {
      const result = await resetPassword({ email, token, password, password_confirmation: confirm });
      setNext(result.next === '/login/kasir' ? '/login/kasir' : '/login');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Kata sandi belum bisa diubah. Coba lagi.');
    } finally {
      setBusy(false);
    }
  };

  if (!token || !email) {
    return (
      <AuthShell>
        <h1 className="text-2xl font-bold">Link tidak lengkap</h1>
        <p className="mt-2 text-sm text-white/60">Buka lagi tombol di email, atau minta link baru.</p>
        <Link to="/forgot-password" className={`${authPrimaryButton} mt-6`}>Minta link baru</Link>
      </AuthShell>
    );
  }

  return (
    <AuthShell>
      {next ? (
        <div>
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-500/15 text-emerald-300"><CheckCircle2 className="h-6 w-6" /></span>
          <h1 className="mt-5 text-2xl font-bold">Kata sandi baru tersimpan</h1>
          <p className="mt-2 text-sm text-white/60">Demi keamanan, semua perangkat yang tadinya masuk sudah dikeluarkan. Masuk lagi dengan kata sandi baru.</p>
          <Link to={next} className={`${authPrimaryButton} mt-6`}>{next === '/login/kasir' ? 'Masuk sebagai kasir' : 'Masuk'}</Link>
        </div>
      ) : (
        <>
          <h1 className="text-2xl font-bold">Buat kata sandi baru</h1>
          <p className="mt-2 text-sm text-white/60">Untuk akun <strong className="text-white">{email}</strong>.</p>
          <form className="mt-6 space-y-4" onSubmit={submit}>
            <div>
              <PasswordField id="new-password" label="Kata sandi baru" autoComplete="new-password" required minLength={8} autoFocus
                value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Minimal 8 karakter" />
              <div className="mt-2 flex gap-1" aria-hidden="true">
                {[1, 2, 3].map((i) => (
                  <span key={i} className={`h-1 flex-1 rounded-full transition-colors ${strength >= i ? (strength === 1 ? 'bg-rose-400' : strength === 2 ? 'bg-[#F6B400]' : 'bg-emerald-400') : 'bg-white/10'}`} />
                ))}
              </div>
              <p className="mt-1.5 text-xs text-white/45">{strength <= 1 ? 'Minimal 8 karakter.' : strength === 2 ? 'Cukup. Tambah angka & huruf agar lebih kuat.' : 'Kuat.'}</p>
            </div>
            <PasswordField id="confirm-password" label="Ulangi kata sandi" autoComplete="new-password" required value={confirm}
              onChange={(e) => setConfirm(e.target.value)} error={mismatch ? 'Belum sama dengan kata sandi di atas.' : null} />
            {error && (
              <AuthAlert>
                {error} <Link to={`/forgot-password?email=${encodeURIComponent(email)}`} className="font-semibold underline">Minta link baru</Link>
              </AuthAlert>
            )}
            <button type="submit" disabled={busy || password.length < 8 || mismatch || confirm === ''} className={authPrimaryButton}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />} Simpan kata sandi
            </button>
          </form>
        </>
      )}
    </AuthShell>
  );
}
