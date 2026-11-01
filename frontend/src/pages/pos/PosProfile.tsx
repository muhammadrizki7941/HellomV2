import { useEffect, useRef, useState, type FormEvent } from 'react';
import { BadgeCheck, Building2, Download, Mail, Phone, Printer, Save, ShieldCheck, UserRound } from 'lucide-react';
import html2canvas from 'html2canvas';
import { changePassword, getPosMyProfile, getToken, setSession, getAuthMe, updatePosMyProfile, type PosMyProfile } from '@/lib/hellomApi';

const inputClass = 'mt-2 min-h-11 w-full rounded-xl border border-[#eadfbe] bg-[#fffdf7] px-3 text-sm text-[#1d1914] outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-100';
const roleLabels: Record<string, string> = {
  org_admin: 'Admin Utama',
  outlet_admin: 'Admin Outlet',
  cashier: 'Kasir',
};

export default function PosProfile() {
  const [profile, setProfile] = useState<PosMyProfile | null>(null);
  const [form, setForm] = useState({ name: '', email: '', phone: '' });
  const [password, setPassword] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const cardRef = useRef<HTMLDivElement | null>(null);

  async function loadProfile() {
    setLoading(true);
    setError('');
    try {
      const result = await getPosMyProfile();
      setProfile(result);
      setForm({
        name: result.user.name,
        email: result.user.email || '',
        phone: result.user.phone || '',
      });
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Gagal memuat profil POS.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadProfile();
  }, []);

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    setMessage('');
    const name = form.name.trim();
    const phone = form.phone.replace(/[\s()-]/g, '');
    const email = form.email.trim();
    if (name.length < 2) {
      setError('Nama lengkap minimal 2 karakter.');
      return;
    }
    if (phone && !/^\+?[0-9]{8,15}$/.test(phone)) {
      setError('Isi nomor HP yang valid, 8–15 digit.');
      return;
    }

    setSaving(true);
    try {
      const updated = await updatePosMyProfile({ name, email: email || null, phone: phone || null });
      setProfile(updated);
      setForm({ name: updated.user.name, email: updated.user.email || '', phone: updated.user.phone || '' });
      const token = getToken();
      if (token) setSession(token, await getAuthMe());
      setMessage('Profil berhasil disimpan. Kartu staf sudah diperbarui.');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Gagal menyimpan profil.');
    } finally {
      setSaving(false);
    }
  }

  async function savePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    setMessage('');
    if (password.password !== password.password_confirmation) {
      setError('Konfirmasi kata sandi tidak cocok.');
      return;
    }

    setSaving(true);
    try {
      await changePassword(password);
      setPassword({ current_password: '', password: '', password_confirmation: '' });
      setMessage('Kata sandi berhasil diperbarui.');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Gagal mengubah kata sandi.');
    } finally {
      setSaving(false);
    }
  }

  async function downloadCard() {
    if (!cardRef.current) return;
    setDownloading(true);
    setError('');
    try {
      const canvas = await html2canvas(cardRef.current, {
        scale: 3,
        backgroundColor: null,
        useCORS: true,
      });
      const link = document.createElement('a');
      link.download = `kartu-staf-${(profile?.staff?.name || 'hellom').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.png`;
      link.href = canvas.toDataURL('image/png');
      link.click();
    } catch (reason) {
      setError(reason instanceof Error ? `Kartu gagal diunduh: ${reason.message}` : 'Kartu gagal diunduh.');
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <style>{`
        @media print {
          @page { size: 85.6mm 54mm; margin: 0; }
          body * { visibility: hidden !important; }
          #pos-staff-id-card, #pos-staff-id-card * { visibility: visible !important; }
          #pos-staff-id-card {
            position: fixed !important; inset: 0 auto auto 0 !important;
            width: 85.6mm !important; height: 54mm !important;
            margin: 0 !important; border-radius: 0 !important;
            box-shadow: none !important; print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
          }
        }
      `}</style>

      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#1d1914]">Profil saya</h1>
          <p className="mt-1 text-sm text-[#6a6255]">Perbarui informasi akun dan akses kartu identitas staf.</p>
        </div>
      </header>

      {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{message}</p>}
      {loading ? (
        <div role="status" className="rounded-2xl border border-[#eadfbe] bg-white p-8 text-sm text-[#6a6255]">Memuat profil...</div>
      ) : (
        <div className="grid gap-5 lg:grid-cols-[1fr_1.1fr]">
          <section className="rounded-2xl border border-[#eadfbe] bg-white p-5 shadow-sm">
            <h2 className="mb-4 text-base font-bold text-[#1d1914]">Informasi akun</h2>
            <form onSubmit={saveProfile} className="space-y-4">
              <label className="block text-sm font-medium text-[#4f463a]">
                <span className="inline-flex items-center gap-2"><UserRound className="h-4 w-4" />Nama lengkap</span>
                <input required minLength={2} maxLength={255} autoComplete="name" value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} className={inputClass} />
              </label>
              <label className="block text-sm font-medium text-[#4f463a]">
                <span className="inline-flex items-center gap-2"><Mail className="h-4 w-4" />Email</span>
                <input type="email" maxLength={255} autoComplete="email" value={form.email} onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} className={inputClass} placeholder="Email (opsional)" />
              </label>
              <label className="block text-sm font-medium text-[#4f463a]">
                <span className="inline-flex items-center gap-2"><Phone className="h-4 w-4" />Nomor HP</span>
                <input type="tel" maxLength={30} autoComplete="tel" value={form.phone} onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))} className={inputClass} placeholder="08xxxxxxxxxx" />
              </label>
              <button type="submit" disabled={saving} className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-amber-400 px-4 text-sm font-bold text-[#1d1914] hover:bg-amber-300 disabled:opacity-60">
                <Save className="h-4 w-4" />{saving ? 'Menyimpan...' : 'Simpan profil'}
              </button>
            </form>

            <details className="mt-5 rounded-xl border border-[#eadfbe]">
              <summary className="cursor-pointer px-4 py-3 text-sm font-semibold text-[#4f463a]">Keamanan · Ubah kata sandi</summary>
              <form onSubmit={savePassword} className="space-y-3 border-t border-[#efe5c8] p-4">
                {([
                  { key: 'current_password', label: 'Kata sandi saat ini', autoComplete: 'current-password' },
                  { key: 'password', label: 'Kata sandi baru', autoComplete: 'new-password' },
                  { key: 'password_confirmation', label: 'Ulangi kata sandi baru', autoComplete: 'new-password' },
                ] as const).map((field) => (
                  <label key={field.key} className="block text-sm font-medium text-[#4f463a]">
                    {field.label}
                    <input
                      required
                      type="password"
                      minLength={field.key === 'current_password' ? 1 : 8}
                      autoComplete={field.autoComplete}
                      value={password[field.key]}
                      onChange={(event) => setPassword((current) => ({ ...current, [field.key]: event.target.value }))}
                      className={inputClass}
                    />
                  </label>
                ))}
                <button type="submit" disabled={saving} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#1d1914] px-4 text-sm font-semibold text-white disabled:opacity-60">
                  <ShieldCheck className="h-4 w-4" />Perbarui kata sandi
                </button>
              </form>
            </details>
          </section>

          <section className="space-y-3 rounded-2xl border border-[#eadfbe] bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-base font-bold text-[#1d1914]">Kartu identitas staf</h2>
                <p className="mt-1 text-xs text-[#7a7063]">Kartu absensi otomatis mengikuti nama dan jabatan profil Anda.</p>
              </div>
              {profile?.staff && (
                <div className="flex gap-2 print:hidden">
                  <button type="button" onClick={() => void downloadCard()} disabled={downloading} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[#eadfbe] px-3 text-sm font-semibold text-[#4f463a] hover:bg-[#fffdf7] disabled:opacity-60">
                    <Download className="h-4 w-4" />{downloading ? 'Menyiapkan...' : 'Unduh PNG'}
                  </button>
                  <button type="button" onClick={() => window.print()} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#1d1914] px-3 text-sm font-semibold text-white hover:bg-[#332b22]">
                    <Printer className="h-4 w-4" />Cetak
                  </button>
                </div>
              )}
            </div>

            {profile?.staff ? (
              <>
                <div
                  id="pos-staff-id-card"
                  ref={cardRef}
                  className="relative mx-auto flex aspect-[1.586/1] w-full max-w-[560px] overflow-hidden rounded-[22px] border border-[#293d27] bg-[radial-gradient(circle_at_85%_15%,rgba(190,242,100,0.22),transparent_34%),linear-gradient(130deg,#17211b_0%,#26372b_58%,#344833_100%)] p-4 text-white shadow-[0_18px_40px_rgba(27,44,31,0.24)] sm:p-6"
                >
                  <div className="absolute -bottom-20 -left-12 h-44 w-44 rounded-full border border-lime-200/10" />
                  <div className="relative z-10 flex min-w-0 flex-1 flex-col justify-between pr-3">
                    <div className="flex items-center gap-2">
                      <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-lime-300 text-[#17211b]">
                        <BadgeCheck className="h-5 w-5" />
                      </div>
                      <div>
                        <p className="text-sm font-extrabold tracking-wide">HELLOM</p>
                        <p className="text-[9px] uppercase tracking-[0.2em] text-lime-100/70">Kartu identitas staf</p>
                      </div>
                    </div>
                    <div className="min-w-0">
                      <p className="truncate text-base font-bold sm:text-xl">{profile.staff.name}</p>
                      <p className="mt-1 inline-flex rounded-full bg-lime-300 px-2.5 py-1 text-[9px] font-bold uppercase tracking-wide text-[#17211b] sm:text-[10px]">
                        {roleLabels[profile.staff.role]}
                      </p>
                      <div className="mt-2 space-y-1 text-[9px] leading-tight text-white/75 sm:text-[10px]">
                        <p className="flex items-center gap-1.5"><Building2 className="h-3 w-3 shrink-0 text-lime-200" />{profile.organization.name}</p>
                        <p className="truncate pl-[18px]">{profile.staff.outlets.join(' · ') || 'Outlet POS'}</p>
                      </div>
                    </div>
                    <p className="font-mono text-[8px] tracking-[0.18em] text-lime-100/60">ID STAF · {String(profile.staff.id).padStart(6, '0')}</p>
                  </div>
                  <div className="relative z-10 flex w-[31%] shrink-0 flex-col items-center justify-center rounded-2xl bg-white p-2 text-center sm:p-3">
                    {profile.staff.attendance_qr.svg_data_uri ? (
                      <img src={profile.staff.attendance_qr.svg_data_uri} alt={`QR absensi ${profile.staff.name}`} className="aspect-square w-full max-w-[142px] object-contain" />
                    ) : (
                      <p className="text-xs text-red-700">QR absensi belum tersedia</p>
                    )}
                    <p className="mt-1 text-[8px] font-bold uppercase tracking-[0.12em] text-[#26372b] sm:text-[9px]">QR absensi</p>
                  </div>
                </div>
                <p className="text-center text-xs text-[#7a7063] print:hidden">Ukuran kartu standar · 85,6 × 54 mm. Tunjukkan QR ini saat check-in/pulang.</p>
              </>
            ) : (
              <div className="rounded-xl border border-dashed border-[#eadfbe] bg-[#fffdf7] p-6 text-center">
                <UserRound className="mx-auto h-8 w-8 text-[#a5977f]" />
                <p className="mt-2 text-sm font-semibold text-[#4f463a]">Akun ini belum terhubung ke data staf POS.</p>
                <p className="mt-1 text-xs text-[#7a7063]">Setelah Admin Utama menambahkan Anda sebagai staf, kartu identitas dan QR absensi akan muncul otomatis di sini.</p>
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  );
}
