import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Loader2, ShieldCheck } from 'lucide-react';
import { acceptAdminInvitation, getAdminInvitation, login, setSession } from '@/lib/hellomApi';
import type { AdminInvitation } from '@/lib/hellomApi';
import { AuthAlert, AuthField, AuthShell, PasswordField, authPrimaryButton } from '@/components/auth/AuthShell';

/**
 * Link from the "Undangan menjadi admin Hellom" email (/undangan-admin?token=…). An existing
 * account confirms with its own password; a new one picks a name and password. Then the person
 * is a super admin, signed in, and lands in the admin dashboard.
 */
export default function AdminInvitationPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = (searchParams.get('token') || '').trim();
  const [invite, setInvite] = useState<AdminInvitation | null>(null);
  const [loadError, setLoadError] = useState<string | null>(token ? null : 'Link undangan tidak lengkap. Buka lagi tombol di email undangan.');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    document.title = 'Undangan admin | Hellom';
    if (!token) return;
    getAdminInvitation(token).then(setInvite).catch((err) => setLoadError(err instanceof Error ? err.message : 'Undangan tidak bisa dibuka.'));
  }, [token]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!invite) return;
    if (!invite.has_account && password !== confirm) {
      setError('Konfirmasi kata sandi belum sama.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await acceptAdminInvitation(token, invite.has_account ? { password } : { name: name.trim(), password, password_confirmation: confirm });
      const result = await login(invite.email, password);
      setSession(result.token, result.user);
      navigate('/admin', { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Belum berhasil. Coba lagi.');
    } finally {
      setBusy(false);
    }
  };

  const expiry = invite ? new Date(invite.expires_at).toLocaleString('id-ID', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : null;

  return (
    <AuthShell>
      {!invite && !loadError && (
        <div className="space-y-3" aria-busy="true">
          <div className="h-5 w-24 animate-pulse rounded bg-white/10" />
          <div className="h-8 w-3/4 animate-pulse rounded bg-white/10" />
          <div className="h-24 animate-pulse rounded-2xl bg-white/[0.06]" />
        </div>
      )}

      {loadError && (
        <>
          <h1 className="text-2xl font-bold">Undangan tidak bisa dibuka</h1>
          <div className="mt-4"><AuthAlert>{loadError}</AuthAlert></div>
          <Link to="/login" className="mt-6 inline-flex min-h-11 items-center text-sm font-semibold text-[#F6B400] hover:underline">Ke halaman masuk</Link>
        </>
      )}

      {invite && (
        <>
          <p className="text-xs font-semibold uppercase tracking-widest text-[#F6B400]">Undangan admin</p>
          <h1 className="mt-2 text-2xl font-bold leading-tight">Jadi admin Hellom</h1>
          <div className="mt-5 flex items-center gap-3 rounded-2xl border border-white/[0.1] bg-white/[0.03] p-4">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#F6B400]/15 text-[#F6B400]"><ShieldCheck className="h-5 w-5" /></span>
            <div className="min-w-0 text-sm">
              <p className="font-semibold">Super admin{invite.invited_by ? ` · diundang ${invite.invited_by}` : ''}</p>
              <p className="truncate text-white/55">{invite.email}</p>
            </div>
          </div>

          {invite.status !== 'pending' ? (
            <div className="mt-5 space-y-4">
              <AuthAlert>
                {invite.status === 'expired' && 'Undangan ini sudah kedaluwarsa. Minta super admin mengirim ulang.'}
                {invite.status === 'accepted' && 'Undangan ini sudah dipakai. Silakan masuk dengan akun kamu.'}
                {invite.status === 'revoked' && 'Undangan ini sudah dibatalkan.'}
              </AuthAlert>
              <Link to="/login" className={authPrimaryButton}>Masuk</Link>
            </div>
          ) : (
            <form className="mt-6 space-y-4" onSubmit={submit}>
              <p className="text-sm text-white/65">Admin punya akses penuh ke dashboard super admin, termasuk pengaturan pembayaran dan persetujuan penarikan dana.</p>
              {invite.has_account ? (
                <>
                  <p className="text-sm text-white/65">Email ini sudah punya akun Hellom. Masukkan kata sandinya untuk menerima.</p>
                  <PasswordField id="adm-password" label="Kata sandi" autoComplete="current-password" required autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />
                </>
              ) : (
                <>
                  <AuthField id="adm-name" label="Nama kamu" autoComplete="name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="Contoh: Sinta Dewi" />
                  <PasswordField id="adm-password" label="Buat kata sandi" autoComplete="new-password" required minLength={8} value={password}
                    onChange={(e) => setPassword(e.target.value)} placeholder="Minimal 8 karakter" hint="Minimal 8 karakter." />
                  <PasswordField id="adm-confirm" label="Ulangi kata sandi" autoComplete="new-password" required minLength={8} value={confirm} onChange={(e) => setConfirm(e.target.value)} />
                </>
              )}
              {error && <AuthAlert>{error}</AuthAlert>}
              <button type="submit" disabled={busy} className={authPrimaryButton}>
                {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                {invite.has_account ? 'Masuk & terima' : 'Buat akun & terima'}
              </button>
              {expiry && <p className="text-xs text-white/45">Berlaku sampai {expiry}</p>}
            </form>
          )}
        </>
      )}
    </AuthShell>
  );
}
