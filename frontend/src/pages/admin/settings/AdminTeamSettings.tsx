import { useEffect, useState } from 'react';
import { Loader2, Mail, RefreshCw, ShieldCheck, Trash2, UserPlus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ApiError, getAdminTeam, inviteAdmin, removeAdmin, resendAdminInvitation, revokeAdminInvitation } from '@/lib/hellomApi';
import type { AdminTeam } from '@/lib/hellomApi';

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-');

/**
 * Super admin › Pengaturan › Tim admin: who has full admin access, invite someone by email (the
 * link comes from the official email), resend/cancel invitations, remove an admin. Inviting and
 * removing need your own password. The owner email is told about every change.
 */
export default function AdminTeamSettings() {
  const [team, setTeam] = useState<AdminTeam | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [inviting, setInviting] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [removing, setRemoving] = useState<{ id: number; name: string } | null>(null);
  const [removePassword, setRemovePassword] = useState('');

  useEffect(() => {
    getAdminTeam().then(setTeam).catch((err: unknown) => setMessage({ ok: false, text: err instanceof Error ? err.message : 'Tim admin belum bisa dimuat.' }));
  }, []);

  const errorText = (err: unknown) => (err instanceof ApiError ? Object.values(err.fieldErrors)[0]?.[0] ?? err.message : err instanceof Error ? err.message : 'Gagal.');
  const run = async (key: string, job: () => Promise<AdminTeam>, ok: string) => {
    setBusy(key);
    setMessage(null);
    try {
      setTeam(await job());
      setMessage({ ok: true, text: ok });
      return true;
    } catch (err) {
      setMessage({ ok: false, text: errorText(err) });
      return false;
    } finally {
      setBusy(null);
    }
  };

  const submitInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    const target = email.trim().toLowerCase();
    if (await run('invite', () => inviteAdmin(target, password), `Undangan dikirim ke ${target}. Link berlaku 72 jam.`)) {
      setEmail('');
      setPassword('');
      setInviting(false);
    }
  };
  const submitRemove = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!removing) return;
    if (await run('remove', () => removeAdmin(removing.id, removePassword), `Akses admin ${removing.name} dicabut.`)) {
      setRemoving(null);
      setRemovePassword('');
    }
  };
  const input = 'min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-base outline-none focus:ring-2 focus:ring-zinc-900';

  return (
    <section className="space-y-5 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm" data-admin-team>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100"><ShieldCheck className="h-5 w-5 text-zinc-700" /></span>
          <div>
            <h2 className="text-lg font-bold text-zinc-900">Tim admin Hellom</h2>
            <p className="text-sm text-zinc-600">Admin di sini punya akses <strong>penuh</strong> ke dashboard super admin — termasuk pengaturan pembayaran dan persetujuan penarikan dana. Undang hanya orang yang kamu percaya.</p>
          </div>
        </div>
        {!inviting && (
          <button type="button" onClick={() => { setInviting(true); setMessage(null); }} className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-zinc-900 px-4 text-sm font-semibold text-white hover:bg-zinc-800">
            <UserPlus className="h-4 w-4" /> Undang admin
          </button>
        )}
      </div>

      {message && <p role="status" className={cn('rounded-xl p-3 text-sm', message.ok ? 'bg-green-50 text-green-700' : 'bg-rose-50 text-rose-700')}>{message.text}</p>}

      {inviting && (
        <form onSubmit={submitInvite} className="space-y-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4" data-invite-form>
          <div className="flex items-center justify-between gap-2">
            <p className="font-semibold text-zinc-900">Undang admin baru</p>
            <button type="button" onClick={() => setInviting(false)} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-xl hover:bg-zinc-200"><X className="h-4 w-4" /></button>
          </div>
          <label className="block text-sm font-medium text-zinc-800">Email yang diundang
            <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} placeholder="rekan@contoh.com" autoComplete="off" className={cn(input, 'mt-1')} />
          </label>
          <label className="block text-sm font-medium text-zinc-800">Password kamu (konfirmasi)
            <input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" className={cn(input, 'mt-1')} />
          </label>
          <p className="text-xs text-zinc-500">Undangan dikirim dari email resmi Hellom. Kalau email itu belum punya akun, penerima membuat password sendiri. Link berlaku 72 jam dan hanya bisa dipakai sekali.</p>
          <button type="submit" disabled={busy !== null} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-zinc-900 px-4 text-sm font-semibold text-white disabled:opacity-50">
            {busy === 'invite' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mail className="h-4 w-4" />} Kirim undangan
          </button>
        </form>
      )}

      <div>
        <h3 className="mb-2 text-sm font-bold text-zinc-900">Admin aktif</h3>
        {!team && !message && <div className="h-20 animate-pulse rounded-xl bg-zinc-100" aria-busy="true" />}
        <ul className="divide-y divide-zinc-100 rounded-2xl border border-zinc-200">
          {(team?.admins ?? []).map((admin) => (
            <li key={admin.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3" data-admin={admin.email}>
              <div className="min-w-0">
                <p className="font-semibold text-zinc-900">{admin.name || 'Tanpa nama'}{admin.is_self && <span className="ml-2 rounded-full bg-zinc-900 px-2 py-0.5 text-xs font-semibold text-white">Kamu</span>}</p>
                <p className="truncate text-sm text-zinc-500">{admin.email}</p>
              </div>
              {!admin.is_self && (
                <button type="button" disabled={busy !== null} onClick={() => { setRemoving({ id: admin.id, name: admin.name || admin.email }); setRemovePassword(''); setMessage(null); }}
                  className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-rose-200 px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                  <Trash2 className="h-4 w-4" /> Cabut akses
                </button>
              )}
              {removing?.id === admin.id && (
                <form onSubmit={submitRemove} className="flex w-full flex-wrap items-end gap-2 rounded-xl bg-rose-50 p-3">
                  <label className="min-w-[200px] flex-1 text-sm font-medium text-rose-900">Cabut akses admin {admin.name || admin.email}? Masukkan password kamu
                    <input type="password" required value={removePassword} onChange={(e) => setRemovePassword(e.target.value)} autoComplete="current-password" className={cn(input, 'mt-1 bg-white')} />
                  </label>
                  <button type="submit" disabled={busy !== null} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-rose-700 px-4 text-sm font-semibold text-white disabled:opacity-50">
                    {busy === 'remove' && <Loader2 className="h-4 w-4 animate-spin" />} Cabut
                  </button>
                  <button type="button" onClick={() => setRemoving(null)} className="min-h-11 rounded-xl border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-800">Batal</button>
                  <p className="w-full text-xs text-rose-800">Akunnya tetap ada sebagai akun biasa; hanya akses admin yang dicabut.</p>
                </form>
              )}
            </li>
          ))}
        </ul>
      </div>

      {(team?.invitations.length ?? 0) > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-bold text-zinc-900">Undangan belum diterima</h3>
          <ul className="divide-y divide-zinc-100 rounded-2xl border border-zinc-200">
            {team?.invitations.map((inv) => (
              <li key={inv.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3" data-invitation={inv.email}>
                <div className="min-w-0">
                  <p className="truncate font-semibold text-zinc-900">{inv.email}</p>
                  <p className="text-xs text-zinc-500">
                    {inv.status === 'expired' ? <span className="font-semibold text-amber-700">Kedaluwarsa</span> : `Berlaku sampai ${when(inv.expires_at)}`}
                    {inv.invited_by ? ` · diundang ${inv.invited_by}` : ''}
                  </p>
                </div>
                <div className="flex gap-2">
                  <button type="button" disabled={busy !== null} onClick={() => void run(`resend-${inv.id}`, () => resendAdminInvitation(inv.id), `Undangan dikirim ulang ke ${inv.email}.`)}
                    className="inline-flex min-h-11 items-center gap-1.5 rounded-xl border px-3 text-sm font-semibold">
                    {busy === `resend-${inv.id}` ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />} Kirim ulang
                  </button>
                  <button type="button" disabled={busy !== null} onClick={() => void run(`revoke-${inv.id}`, () => revokeAdminInvitation(inv.id), 'Undangan dibatalkan.')}
                    className="inline-flex min-h-11 items-center gap-1.5 rounded-xl border border-rose-200 px-3 text-sm font-semibold text-rose-700">Batalkan</button>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
