import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { UserRound, Mail, Phone, Building2, Save, ShieldCheck, LogOut } from 'lucide-react';
import { clearSession, getAuthMe, getSessionUser, getToken, setSession, updateProfile, changePassword, logout } from '@/lib/hellomApi';
import { ColorSchemeToggle } from '@/lib/colorScheme';
import type { HellomUser } from '@/services/api/auth';

const inputClass = 'mt-2 min-h-12 w-full min-w-0 rounded-xl border border-zinc-200 bg-zinc-50 px-4 text-base text-zinc-900 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100';
export default function MemberProfile() {
  const navigate = useNavigate();
  const [user, setUser] = useState<HellomUser | null>(() => getSessionUser<HellomUser>());
  const [form, setForm] = useState({ name: '', email: '', phone: '' });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [password, setPassword] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [passwordBusy, setPasswordBusy] = useState(false);
  const [passwordMessage, setPasswordMessage] = useState('');
  const sync = (me: HellomUser) => {
    setUser(me); setForm({ name: me.name, email: me.email, phone: me.phone || '' });
    const token = getToken(); if (token) setSession(token, me);
  };
  const load = async () => {
    setLoading(true); setError('');
    try { sync(await getAuthMe()); } catch (e) { setError(e instanceof Error ? e.message : 'Gagal memuat Profile.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setError(''); setSuccess('');
    if (form.name.trim().length < 2) { setError('Nama lengkap minimal 2 karakter.'); return; }
    const phone = form.phone.replace(/[\s()-]/g, '');
    if (phone && !/^\+?[0-9]{8,15}$/.test(phone)) { setError('Isi nomor HP yang valid, 8–15 digit. Contoh: 081234567890.'); return; }
    setSaving(true);
    try { sync(await updateProfile({ name: form.name.trim(), email: form.email.trim(), phone })); setSuccess('Profile berhasil disimpan.'); }
    catch (e) { setError(e instanceof Error ? e.message : 'Gagal menyimpan Profile.'); }
    finally { setSaving(false); }
  };
  const submitPassword = async (event: FormEvent) => {
    event.preventDefault(); setPasswordMessage('');
    if (password.password !== password.password_confirmation) { setPasswordMessage('Konfirmasi password tidak cocok.'); return; }
    setPasswordBusy(true);
    try { await changePassword(password); setPassword({ current_password: '', password: '', password_confirmation: '' }); setPasswordMessage('Password berhasil diperbarui.'); }
    catch (e) { setPasswordMessage(e instanceof Error ? e.message : 'Gagal mengubah password.'); }
    finally { setPasswordBusy(false); }
  };
  const handleLogout = async () => {
    try { await logout(); }
    catch { /* Clear the local session even if the server is unavailable. */ }
    finally { clearSession(); navigate('/login', { replace: true }); }
  };
  return <div className="mx-auto max-w-3xl space-y-6">
    <header><h1 className="text-2xl font-bold text-zinc-900">Profile</h1><p className="mt-1 text-sm text-zinc-500">Kelola informasi pribadi dan tampilan aplikasi Anda.</p></header>
    <section className="rounded-3xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-7">
      <div className="mb-6 flex items-center gap-4"><div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-amber-100 text-2xl font-bold text-amber-800">{user?.name?.slice(0, 1).toUpperCase() || <UserRound />}</div><div className="min-w-0"><h2 className="break-words text-lg font-bold text-zinc-900">{user?.name || 'Profile Anda'}</h2><p className="break-all text-sm text-zinc-500">{user?.email}</p></div></div>
      {!loading && !user?.phone && <p className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Profile belum lengkap. Tambahkan nomor HP untuk melengkapi informasi akun.</p>}
      {loading && <p role="status" className="mb-4 text-sm text-zinc-500">Memuat Profile...</p>}
      {error && <div role="alert" className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error} {!form.email && <button onClick={() => void load()} className="underline">Coba lagi</button>}</div>}
      {success && <p role="status" className="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">{success}</p>}
      <form onSubmit={submit} className="space-y-5">
        <label className="block text-sm font-medium text-zinc-700"><span className="flex items-center gap-2"><UserRound className="h-4 w-4" />Nama Lengkap</span><input required minLength={2} maxLength={255} autoComplete="name" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} className={inputClass} disabled={loading || saving} /></label>
        <label className="block text-sm font-medium text-zinc-700"><span className="flex items-center gap-2"><Mail className="h-4 w-4" />Email</span><input required type="email" maxLength={255} autoComplete="email" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} className={inputClass} disabled={loading || saving} /></label>
        <label className="block text-sm font-medium text-zinc-700"><span className="flex items-center gap-2"><Phone className="h-4 w-4" />Nomor HP</span><input type="tel" autoComplete="tel" maxLength={30} placeholder="081234567890" value={form.phone} onChange={e => setForm({ ...form, phone: e.target.value })} className={inputClass} disabled={loading || saving} aria-describedby="phone-help" /></label>
        <p id="phone-help" className="text-xs leading-5 text-zinc-500">Nomor HP digunakan untuk informasi akun dan dapat diakses super admin. Nomor ini tidak ditampilkan di halaman publik.</p>
        <div><p className="flex items-center gap-2 text-sm font-medium text-zinc-700"><Building2 className="h-4 w-4" />Nama Bisnis</p><p className="mt-2 rounded-xl bg-zinc-50 px-4 py-3 text-sm text-zinc-700">{user?.current_organization?.name || 'Belum ada bisnis aktif'}</p><Link to="/dashboard/organizations" className="mt-2 inline-block text-xs text-amber-700 underline">Kelola bisnis</Link></div>
        <div><h3 className="mb-3 text-sm font-semibold text-zinc-900">Tampilan Aplikasi</h3><ColorSchemeToggle className="[&_span]:inline" /></div>
        <button type="submit" disabled={loading || saving || !form.email} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-amber-400 px-4 text-sm font-bold text-black transition hover:bg-amber-500 disabled:opacity-50"><Save className="h-5 w-5" />{saving ? 'Menyimpan...' : 'Simpan Perubahan'}</button>
      </form>
    </section>
    <details className="rounded-2xl border border-zinc-200 bg-white p-5"><summary className="cursor-pointer font-semibold text-zinc-900">Keamanan akun · Ubah password</summary><form onSubmit={submitPassword} className="mt-5 space-y-4">{([{ key: 'current_password', label: 'Password saat ini', auto: 'current-password' }, { key: 'password', label: 'Password baru', auto: 'new-password' }, { key: 'password_confirmation', label: 'Konfirmasi password baru', auto: 'new-password' }] as const).map(field => <label key={field.key} className="block text-sm text-zinc-700">{field.label}<input required type="password" minLength={field.key === 'current_password' ? 1 : 8} autoComplete={field.auto} value={password[field.key]} onChange={e => setPassword({ ...password, [field.key]: e.target.value })} className={inputClass} disabled={passwordBusy} /></label>)}{passwordMessage && <p role="status" className="text-sm text-zinc-700">{passwordMessage}</p>}<button disabled={passwordBusy} className="flex min-h-11 items-center gap-2 rounded-xl bg-zinc-900 px-4 text-sm font-semibold text-white disabled:opacity-50"><ShieldCheck className="h-4 w-4" />{passwordBusy ? 'Menyimpan...' : 'Perbarui password'}</button></form></details>
    <section className="flex flex-col gap-3 rounded-2xl border border-red-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 className="font-semibold text-zinc-900">Keluar dari akun</h2>
        <p className="mt-1 text-sm text-zinc-500">Akhiri sesi Anda di perangkat ini.</p>
      </div>
      <button onClick={() => void handleLogout()} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-red-200 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300">
        <LogOut className="h-4 w-4" />Keluar
      </button>
    </section>
  </div>;
}
