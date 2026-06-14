import { useState, useEffect, type FormEvent } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import {
  ArrowLeft,
  Calendar,
  LogOut,
  Mail,
  Phone,
  ShoppingBag,
  Star,
  User,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { registerPublicPosMember, lookupPublicPosMember } from '@/lib/pos/posApi';

type ViewState = 'checking' | 'login' | 'register' | 'dashboard';

interface PosMemberSession {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  total_points: number;
  total_orders: number;
}

const ACCENT = '#F5C518';

function memberSessionKey(orgSlug: string) {
  return `pos_member_session_${orgSlug}`;
}

function FieldInput({
  icon,
  type = 'text',
  value,
  onChange,
  placeholder,
  required,
  disabled,
}: {
  icon: React.ReactNode;
  type?: string;
  value: string;
  onChange?: (v: string) => void;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
}) {
  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[#888888]">{icon}</span>
      <input
        type={type}
        value={value}
        onChange={onChange ? (e) => onChange(e.target.value) : undefined}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        className={cn(
          'w-full rounded-2xl border py-3 pl-10 pr-4 text-sm text-[#1A1A1A] outline-none placeholder:text-[#BBBBBB] transition-colors',
          disabled
            ? 'cursor-not-allowed border-[#E6E6E6] bg-[#F9F9F9] text-[#888888]'
            : 'border-[#E6A800] bg-white focus:border-[#1A1A1A]'
        )}
      />
    </div>
  );
}

export default function MemberPortalPage() {
  const { organizationSlug, tableToken } = useParams<{
    organizationSlug?: string;
    tableToken?: string;
  }>();
  const navigate = useNavigate();

  const slug = organizationSlug || '';
  const [view, setView] = useState<ViewState>('checking');
  const [member, setMember] = useState<PosMemberSession | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // Login form — phone only
  const [loginPhone, setLoginPhone] = useState('');

  // Register form
  const [regName, setRegName] = useState('');
  const [regPhone, setRegPhone] = useState('');
  const [regEmail, setRegEmail] = useState('');

  const backUrl = slug
    ? tableToken
      ? `/customer/${slug}/order/${tableToken}`
      : `/customer/${slug}`
    : '/';

  useEffect(() => {
    if (!slug) {
      setView('login');
      return;
    }
    const stored = localStorage.getItem(memberSessionKey(slug));
    if (stored) {
      try {
        const cached = JSON.parse(stored) as PosMemberSession;
        setMember(cached);
        setView('dashboard');
        // Refresh poin & transaksi terbaru dari server di background
        void refreshFromServer(cached.phone);
        return;
      } catch {
        localStorage.removeItem(memberSessionKey(slug));
      }
    }
    setView('login');
  }, [slug]);

  const refreshFromServer = async (phone: string) => {
    try {
      const res = await lookupPublicPosMember(slug, phone);
      if (res.member) persistMember(res.member);
    } catch {
      // Gagal refresh — tetap tampilkan data cache
    }
  };

  const persistMember = (m: PosMemberSession) => {
    localStorage.setItem(memberSessionKey(slug), JSON.stringify(m));
    setMember(m);
  };

  const handleLogin = async (e: FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await lookupPublicPosMember(slug, loginPhone);
      if (!res.member) throw new Error('Nomor HP tidak ditemukan.');
      persistMember(res.member);
      setView('dashboard');
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Nomor HP tidak terdaftar sebagai member.';
      setError(msg);
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e: FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await registerPublicPosMember(slug, {
        name: regName,
        phone: regPhone,
        email: regEmail || undefined,
      });
      persistMember(res.member);
      setView('dashboard');
      setSuccessMsg('Pendaftaran berhasil! Selamat bergabung 🎉');
      setTimeout(() => setSuccessMsg(null), 4000);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Gagal mendaftar. Coba lagi.');
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    localStorage.removeItem(memberSessionKey(slug));
    setMember(null);
    setLoginPhone('');
    setView('login');
  };

  const switchView = (next: 'login' | 'register') => {
    setError(null);
    setSuccessMsg(null);
    setView(next);
  };

  if (view === 'checking') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-white">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-[#E6A800] border-t-transparent" />
      </div>
    );
  }

  return (
    <div
      className="min-h-screen bg-[#F9F9F9]"
      style={{ paddingBottom: 'calc(5rem + env(safe-area-inset-bottom))' }}
    >
      <div className="mx-auto max-w-[430px]">
        {/* ─── Top Bar ─── */}
        <div className="sticky top-0 z-10 flex items-center gap-3 bg-white px-4 py-3 shadow-sm">
          <button
            type="button"
            onClick={() => navigate(backUrl)}
            className="flex h-9 w-9 items-center justify-center rounded-full border border-[#E6A800] text-[#1A1A1A]"
          >
            <ArrowLeft className="h-4 w-4" />
          </button>
          <div className="flex-1">
            <p className="text-sm font-bold text-[#1A1A1A]">
              {view === 'dashboard'
                ? 'Profil Member'
                : view === 'register'
                ? 'Daftar Member'
                : 'Masuk sebagai Member'}
            </p>
            <p className="text-[11px] text-[#888888]">Poin, pesanan & reward eksklusifmu</p>
          </div>
          {view === 'dashboard' && (
            <button
              type="button"
              onClick={handleLogout}
              title="Keluar"
              className="flex h-9 w-9 items-center justify-center rounded-full border border-red-200 text-red-400 hover:bg-red-50 transition-colors"
            >
              <LogOut className="h-4 w-4" />
            </button>
          )}
        </div>

        {/* ─── Banners ─── */}
        {error && (
          <div className="mx-4 mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}
        {successMsg && (
          <div className="mx-4 mt-4 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {successMsg}
          </div>
        )}

        {/* ══════════════════════════════════════
            LOGIN VIEW
        ══════════════════════════════════════ */}
        {view === 'login' && (
          <div className="px-4 pt-6">
            {/* Hero card */}
            <div className="mb-6 overflow-hidden rounded-[28px] bg-[#1A1A1A] p-6 text-center text-white">
              <div
                className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl"
                style={{ background: `linear-gradient(135deg, ${ACCENT}, #E6A800)` }}
              >
                <Star className="h-8 w-8 text-[#1A1A1A]" />
              </div>
              <h2 className="text-xl font-extrabold">Member Area</h2>
              <p className="mt-1 text-sm text-white/60">
                Masukkan nomor HP untuk melihat poin, riwayat pesanan, dan reward eksklusif
              </p>
            </div>

            <form onSubmit={handleLogin} className="space-y-4">
              <div>
                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-widest text-[#888888]">
                  Nomor HP Terdaftar
                </p>
                <FieldInput
                  icon={<Phone className="h-4 w-4" />}
                  type="tel"
                  value={loginPhone}
                  onChange={setLoginPhone}
                  placeholder="08xxxxxxxxxx"
                  required
                />
              </div>
              <button
                type="submit"
                disabled={loading}
                className="w-full rounded-2xl py-3 text-sm font-bold text-[#1A1A1A] transition disabled:opacity-60"
                style={{ backgroundColor: ACCENT }}
              >
                {loading ? 'Mencari...' : 'Cari Akun Member'}
              </button>
            </form>

            <p className="mt-6 text-center text-sm text-[#888888]">
              Belum punya kartu member?{' '}
              <button
                type="button"
                onClick={() => switchView('register')}
                className="font-bold text-[#1A1A1A] underline underline-offset-2"
              >
                Daftar sekarang
              </button>
            </p>
          </div>
        )}

        {/* ══════════════════════════════════════
            REGISTER VIEW
        ══════════════════════════════════════ */}
        {view === 'register' && (
          <div className="px-4 pt-6">
            <div className="mb-6 overflow-hidden rounded-[28px] bg-[#1A1A1A] p-6 text-center text-white">
              <div
                className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl"
                style={{ background: `linear-gradient(135deg, ${ACCENT}, #E6A800)` }}
              >
                <User className="h-8 w-8 text-[#1A1A1A]" />
              </div>
              <h2 className="text-xl font-extrabold">Daftar Member</h2>
              <p className="mt-1 text-sm text-white/60">
                Kumpulkan poin di setiap pembelian dan nikmati reward eksklusif
              </p>
            </div>

            <form onSubmit={handleRegister} className="space-y-4">
              <div>
                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-widest text-[#888888]">
                  Nama Lengkap <span className="text-red-400">*</span>
                </p>
                <FieldInput
                  icon={<User className="h-4 w-4" />}
                  value={regName}
                  onChange={setRegName}
                  placeholder="Nama lengkap kamu"
                  required
                />
              </div>
              <div>
                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-widest text-[#888888]">
                  No. HP <span className="text-red-400">*</span>
                </p>
                <FieldInput
                  icon={<Phone className="h-4 w-4" />}
                  type="tel"
                  value={regPhone}
                  onChange={setRegPhone}
                  placeholder="08xxxxxxxxxx"
                  required
                />
              </div>
              <div>
                <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-widest text-[#888888]">
                  Email <span className="text-[#BBBBBB] font-normal text-[10px]">(opsional)</span>
                </p>
                <FieldInput
                  icon={<Mail className="h-4 w-4" />}
                  type="email"
                  value={regEmail}
                  onChange={setRegEmail}
                  placeholder="email@kamu.com"
                />
              </div>
              <button
                type="submit"
                disabled={loading}
                className="w-full rounded-2xl py-3 text-sm font-bold text-[#1A1A1A] transition disabled:opacity-60"
                style={{ backgroundColor: ACCENT }}
              >
                {loading ? 'Mendaftarkan...' : 'Daftar sebagai Member'}
              </button>
            </form>

            <p className="mt-6 text-center text-sm text-[#888888]">
              Sudah punya akun?{' '}
              <button
                type="button"
                onClick={() => switchView('login')}
                className="font-bold text-[#1A1A1A] underline underline-offset-2"
              >
                Masuk sekarang
              </button>
            </p>
          </div>
        )}

        {/* ══════════════════════════════════════
            DASHBOARD VIEW
        ══════════════════════════════════════ */}
        {view === 'dashboard' && member && (
          <div className="space-y-4 px-4 pt-4">
            {/* Member Card */}
            <div className="overflow-hidden rounded-[28px] bg-[#1A1A1A] p-5 text-white">
              <div className="flex items-center gap-4">
                <div
                  className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl text-[#1A1A1A]"
                  style={{ background: `linear-gradient(135deg, ${ACCENT}, #E6A800)` }}
                >
                  <span className="text-2xl font-extrabold">
                    {(member.name || 'M').charAt(0).toUpperCase()}
                  </span>
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-[10px] font-semibold uppercase tracking-widest text-white/40">Member</p>
                  <h2 className="truncate text-base font-bold">{member.name}</h2>
                  <p className="truncate text-xs text-white/55">{member.phone}</p>
                  {member.email && (
                    <p className="truncate text-xs text-white/40">{member.email}</p>
                  )}
                </div>
              </div>

              {/* Stats */}
              <div className="mt-4 grid grid-cols-2 gap-3">
                <div className="rounded-2xl bg-white/8 px-4 py-3">
                  <p className="text-[10px] text-white/45">Total Poin</p>
                  <p
                    className="mt-0.5 text-[1.6rem] font-extrabold leading-none"
                    style={{ color: ACCENT }}
                  >
                    {member.total_points ?? 0}
                  </p>
                  <p className="mt-1 text-[10px] text-white/35">poin terkumpul</p>
                </div>
                <div className="rounded-2xl bg-white/8 px-4 py-3">
                  <p className="text-[10px] text-white/45">Total Pesanan</p>
                  <p className="mt-0.5 text-[1.6rem] font-extrabold leading-none text-white">
                    {member.total_orders ?? 0}
                  </p>
                  <p className="mt-1 text-[10px] text-white/35">transaksi</p>
                </div>
              </div>

              {(member.total_orders ?? 0) === 0 && (
                <p className="mt-3 rounded-xl bg-white/6 px-3 py-2 text-[11px] text-white/45">
                  💡 Data poin muncul setelah pesanan pertama tercatat sebagai member
                </p>
              )}
            </div>

            {/* Quick Actions */}
            <div className="grid grid-cols-3 gap-3">
              {(
                [
                  { icon: <ShoppingBag className="h-5 w-5" />, label: 'Pesanan', hash: '#pesanan' },
                  { icon: <Calendar className="h-5 w-5" />, label: 'Reservasi', hash: '#reservasi' },
                  { icon: <Star className="h-5 w-5" />, label: 'Promo', hash: '#promo' },
                ] as const
              ).map((item) => (
                <button
                  key={item.label}
                  type="button"
                  onClick={() => navigate(backUrl + item.hash)}
                  className="flex flex-col items-center gap-2 rounded-[22px] border border-[#E6A800] bg-white px-3 py-4 transition hover:shadow-md"
                >
                  <div
                    className="flex h-10 w-10 items-center justify-center rounded-xl text-[#1A1A1A]"
                    style={{ background: `linear-gradient(135deg, ${ACCENT}, #E6A800)` }}
                  >
                    {item.icon}
                  </div>
                  <span className="text-xs font-semibold text-[#1A1A1A]">{item.label}</span>
                </button>
              ))}
            </div>

            {/* Info Card */}
            <div className="rounded-[24px] border border-[#F0F0F0] bg-white p-5 shadow-sm">
              <h3 className="mb-3 text-sm font-bold text-[#1A1A1A]">Informasi Akun</h3>
              <div className="space-y-2 text-sm text-[#444444]">
                <div className="flex items-center gap-2">
                  <User className="h-4 w-4 text-[#888888]" />
                  <span>{member.name}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Phone className="h-4 w-4 text-[#888888]" />
                  <span>{member.phone}</span>
                </div>
                {member.email && (
                  <div className="flex items-center gap-2">
                    <Mail className="h-4 w-4 text-[#888888]" />
                    <span>{member.email}</span>
                  </div>
                )}
              </div>
              <p className="mt-3 text-[11px] text-[#BBBBBB]">
                Untuk mengubah data, hubungi staf di kasir.
              </p>
            </div>

            {/* Logout */}
            <button
              type="button"
              onClick={handleLogout}
              className="w-full rounded-2xl border border-red-200 bg-white py-3 text-sm font-semibold text-red-500 transition hover:bg-red-50"
            >
              Keluar dari Akun
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
