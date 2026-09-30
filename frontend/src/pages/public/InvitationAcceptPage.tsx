import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Loader2, Store } from 'lucide-react';
import {
  acceptOrganizationInvitation,
  getAuthMe,
  getPublicInvitation,
  getSessionUser,
  getToken,
  login,
  register,
  setActiveOutletId,
  setSession,
} from '@/lib/hellomApi';
import type { PublicInvitation } from '@/lib/hellomApi';
import { AuthAlert, AuthField, AuthShell, PasswordField, authPrimaryButton } from '@/components/auth/AuthShell';

/**
 * Invitation link from email (/invitation/accept?token=…). Shows who invited you, then either
 * "log in with your password" (the email already has an account) or "create your account"
 * (name + password; the email is fixed). Cashiers land straight in POS for their outlet.
 */
type Accepted = { pos_access?: { is_cashier?: boolean; outlet_id?: number | null } } | null;

export default function InvitationAcceptPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = (searchParams.get('token') || searchParams.get('inviteToken') || '').trim();

  const [invite, setInvite] = useState<PublicInvitation | null>(null);
  const [loadError, setLoadError] = useState<string | null>(token ? null : 'Link undangan tidak lengkap. Buka lagi tombol di email undangan.');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    document.title = 'Undangan | Hellom';
    if (!token) return;
    getPublicInvitation(token)
      .then((data) => { setInvite(data); setName(data.staff_name ?? ''); })
      .catch((err) => setLoadError(err instanceof Error ? err.message : 'Undangan tidak bisa dibuka.'));
  }, [token]);

  const signedInEmail = getToken() ? (getSessionUser<{ email?: string }>()?.email ?? null) : null;
  const signedInAsInvitee = !!invite && !!signedInEmail && signedInEmail.toLowerCase() === invite.email.toLowerCase();

  const finish = (user: Accepted) => {
    const access = user?.pos_access;
    if (access?.is_cashier) {
      if (access.outlet_id) setActiveOutletId(access.outlet_id);
      navigate('/pos/orders', { replace: true });
    } else {
      navigate('/dashboard', { replace: true });
    }
  };

  const acceptAsSignedIn = async () => {
    await acceptOrganizationInvitation({ token });
    const me = await getAuthMe();
    const current = getToken();
    if (current) setSession(current, me);
    finish(me as Accepted);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!invite) return;
    setBusy(true);
    setError(null);
    try {
      if (signedInAsInvitee) {
        await acceptAsSignedIn();
      } else if (invite.has_account) {
        const result = await login(invite.email, password);
        setSession(result.token, result.user);
        await acceptAsSignedIn();
      } else {
        const result = await register({ name: name.trim(), email: invite.email, password, invite_token: token });
        setSession(result.token, result.user);
        finish(result.user as Accepted);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Belum berhasil. Coba lagi.');
    } finally {
      setBusy(false);
    }
  };

  const expiry = invite?.expires_at
    ? new Date(invite.expires_at).toLocaleString('id-ID', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    : null;

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
          <p className="text-xs font-semibold uppercase tracking-widest text-[#F6B400]">Undangan</p>
          <h1 className="mt-2 text-2xl font-bold leading-tight">Gabung dengan {invite.organization_name}</h1>

          <div className="mt-5 flex items-center gap-3 rounded-2xl border border-white/[0.1] bg-white/[0.03] p-4">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#F6B400]/15 text-[#F6B400]"><Store className="h-5 w-5" /></span>
            <div className="min-w-0 text-sm">
              <p className="font-semibold">{invite.role_label}{invite.outlet_name ? ` · ${invite.outlet_name}` : ''}</p>
              <p className="truncate text-white/55">{invite.email}</p>
            </div>
          </div>

          {invite.status !== 'pending' ? (
            <div className="mt-5 space-y-4">
              <AuthAlert>
                {invite.status === 'expired' && 'Undangan ini sudah kedaluwarsa. Minta owner mengirim undangan baru.'}
                {invite.status === 'accepted' && 'Undangan ini sudah dipakai. Silakan masuk dengan akun kamu.'}
                {invite.status === 'revoked' && 'Undangan ini sudah dibatalkan. Minta owner mengirim undangan baru.'}
              </AuthAlert>
              <Link to={invite.is_pos_staff ? '/login/kasir' : '/login'} className={authPrimaryButton}>Masuk</Link>
            </div>
          ) : (
            <form className="mt-6 space-y-4" onSubmit={submit}>
              {signedInAsInvitee ? (
                <p className="text-sm text-white/65">Kamu sudah masuk sebagai <strong className="text-white">{signedInEmail}</strong>.</p>
              ) : invite.has_account ? (
                <>
                  <p className="text-sm text-white/65">Email ini sudah punya akun Hellom. Masukkan kata sandinya untuk bergabung.</p>
                  <PasswordField id="inv-password" label="Kata sandi" autoComplete="current-password" required autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />
                </>
              ) : (
                <>
                  <p className="text-sm text-white/65">Buat akun untuk email ini — cukup nama dan kata sandi.</p>
                  <AuthField id="inv-name" label="Nama kamu" autoComplete="name" required value={name} onChange={(e) => setName(e.target.value)} placeholder="Contoh: Sinta Dewi" />
                  <PasswordField id="inv-password" label="Buat kata sandi" autoComplete="new-password" required minLength={8} value={password}
                    onChange={(e) => setPassword(e.target.value)} placeholder="Minimal 8 karakter" hint="Minimal 8 karakter." />
                </>
              )}

              {error && <AuthAlert>{error}</AuthAlert>}

              <button type="submit" disabled={busy} className={authPrimaryButton}>
                {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                {signedInAsInvitee ? 'Terima undangan' : invite.has_account ? 'Masuk & bergabung' : 'Buat akun & bergabung'}
              </button>

              <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-white/45">
                {expiry && <span>Berlaku sampai {expiry}</span>}
                {invite.has_account && !signedInAsInvitee && <Link to="/forgot-password" className="inline-flex min-h-11 items-center font-semibold text-white/70 hover:text-white">Lupa kata sandi?</Link>}
              </div>
            </form>
          )}
        </>
      )}
    </AuthShell>
  );
}
