import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { changePassword, getSessionUser, getToken, setSession } from '@/lib/hellomApi';

type SessionUser = {
  must_change_password?: boolean;
  pos_access?: { is_cashier?: boolean };
};

export default function ForcePasswordChangePage() {
  const navigate = useNavigate();
  const token = getToken();
  const user = getSessionUser<SessionUser>();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (!token) return <Navigate to="/login" replace />;
  if (!user?.must_change_password) return <Navigate to="/pos/admin-dashboard" replace />;

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setSaving(true);
    try {
      await changePassword({
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      });
      setSession(token, { ...user, must_change_password: false });
      navigate(user.pos_access?.is_cashier ? '/pos/orders' : '/pos/admin-dashboard', { replace: true });
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : 'Kata sandi belum dapat diubah.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#080808] px-4 py-8 text-white">
      <form onSubmit={submit} className="w-full max-w-md space-y-5 rounded-3xl border border-white/10 bg-white/[0.04] p-6 shadow-2xl">
        <div>
          <p className="text-sm font-semibold text-lime-400">Keamanan akun POS</p>
          <h1 className="mt-2 text-2xl font-bold">Buat kata sandi baru</h1>
          <p className="mt-2 text-sm leading-6 text-white/65">Kata sandi sementara hanya bisa dipakai untuk masuk pertama kali. Ganti sekarang sebelum melanjutkan.</p>
        </div>
        <label className="block text-sm font-medium">
          Kata sandi sementara
          <input required type="password" autoComplete="current-password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)}
            className="mt-2 min-h-12 w-full rounded-xl border border-white/15 bg-black/40 px-3 text-white outline-none focus:border-lime-400" />
        </label>
        <label className="block text-sm font-medium">
          Kata sandi baru
          <input required minLength={8} type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)}
            className="mt-2 min-h-12 w-full rounded-xl border border-white/15 bg-black/40 px-3 text-white outline-none focus:border-lime-400" />
        </label>
        <label className="block text-sm font-medium">
          Ulangi kata sandi baru
          <input required minLength={8} type="password" autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)}
            className="mt-2 min-h-12 w-full rounded-xl border border-white/15 bg-black/40 px-3 text-white outline-none focus:border-lime-400" />
        </label>
        {error && <p role="alert" className="rounded-xl border border-red-300/20 bg-red-500/10 p-3 text-sm text-red-200">{error}</p>}
        <button type="submit" disabled={saving} className="min-h-12 w-full rounded-xl bg-lime-400 px-4 font-bold text-black disabled:opacity-60">
          {saving ? 'Menyimpan…' : 'Simpan kata sandi dan lanjutkan'}
        </button>
      </form>
    </main>
  );
}
