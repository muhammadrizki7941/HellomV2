import { useEffect, useState } from 'react';
import { AlertTriangle, Camera, CheckCircle2, Clock3, Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ApiError, getPayoutProfile, submitPayoutProfile } from '@/lib/hellomApi';

/**
 * KTP & rekening for withdrawing the Saldo Penjualan (moved here from Pembayaran). Verified
 * sellers can change the account: it goes back to review and the next withdrawal is held
 * (see WithdrawalService bank-change hold); the backend e-mails the owner.
 */
type Profile = {
  status: 'unverified' | 'pending' | 'verified' | 'rejected';
  full_name?: string | null;
  destination_type?: 'bank' | 'ewallet';
  bank_code?: string | null;
  bank_name?: string | null;
  account_number_masked?: string | null;
  account_name?: string | null;
  review_notes?: string | null;
  has_ktp_image?: boolean;
};

const inputClass = 'min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base text-zinc-900 outline-none focus:border-zinc-900 focus:ring-1 focus:ring-zinc-900 aria-[invalid=true]:border-rose-400';

export default function PayoutAccountSheet({ onClose, onSaved }: { onClose: () => void; onSaved: (message: string) => void }) {
  const [profile, setProfile] = useState<Profile | null>(null);
  const [role, setRole] = useState<string>('member');
  const [loadError, setLoadError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ destination_type: 'bank' as 'bank' | 'ewallet', full_name: '', nik: '', bank_code: '', bank_name: '', account_number: '', account_name: '' });
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    getPayoutProfile()
      .then((res) => {
        const data = res as { profile?: Profile | null; status?: Profile['status']; requester_role?: string };
        const p = data.profile ?? { status: data.status ?? 'unverified' };
        setProfile(p);
        setRole(String(data.requester_role ?? 'member'));
        setEditing(p.status === 'unverified' || p.status === 'rejected');
        if (p.full_name) setForm((f) => ({ ...f, full_name: p.full_name ?? '', destination_type: p.destination_type ?? 'bank', account_name: p.account_name ?? '' }));
      })
      .catch((err) => setLoadError(err instanceof Error ? err.message : 'Data rekening belum bisa dimuat'));
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !saving) onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [saving, onClose]);

  const set = (key: keyof typeof form, value: string) => {
    setForm((f) => ({ ...f, [key]: value }));
    setErrors((e) => ({ ...e, [key]: '' }));
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (form.full_name.trim().length < 3) found.full_name = 'Isi nama sesuai KTP.';
    if (!/^\d{16}$/.test(form.nik)) found.nik = 'NIK harus 16 angka.';
    if (!form.bank_code.trim()) found.bank_code = form.destination_type === 'ewallet' ? 'Isi nama e-wallet.' : 'Isi kode bank, mis. BCA.';
    if (form.account_number.replace(/\D/g, '').length < 5) found.account_number = 'Nomor belum lengkap.';
    if (form.account_name.trim().length < 3) found.account_name = 'Isi nama pemilik rekening.';
    setErrors(found);
    setFormError(null);
    if (Object.values(found).some(Boolean)) return;

    setSaving(true);
    try {
      const body = new FormData();
      body.append('destination_type', form.destination_type);
      body.append('full_name', form.full_name.trim());
      body.append('nik', form.nik);
      body.append('bank_code', form.bank_code.trim());
      if (form.bank_name.trim()) body.append('bank_name', form.bank_name.trim());
      body.append('account_number', form.account_number.replace(/\s/g, ''));
      body.append('account_name', form.account_name.trim());
      if (file) body.append('ktp_image', file);
      await submitPayoutProfile(body);
      onSaved('Data terkirim. Tim Hellom meninjau paling lambat 1×24 jam; kamu dapat kabar lewat email.');
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) {
        setErrors(Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k, v[0]])));
      }
      setFormError(err instanceof Error ? err.message : 'Data belum terkirim. Coba lagi.');
    } finally {
      setSaving(false);
    }
  };

  const field = (key: keyof typeof form, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <label className="block space-y-1 text-sm font-medium text-zinc-800">
      <span>{label}</span>
      <input value={form[key]} onChange={(e) => set(key, key === 'nik' ? e.target.value.replace(/\D/g, '').slice(0, 16) : e.target.value)}
        aria-invalid={errors[key] ? true : undefined} className={inputClass} {...props} />
      {errors[key] && <span className="block text-xs text-rose-600">{errors[key]}</span>}
    </label>
  );

  const canManage = ['owner', 'admin', 'super_admin'].includes(role);
  const status = profile?.status ?? 'unverified';

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-labelledby="payout-title" onClick={(e) => e.stopPropagation()}
        className="flex max-h-[100svh] w-full max-w-lg flex-col rounded-t-3xl bg-white sm:max-h-[90svh] sm:rounded-3xl">
        <header className="flex items-center justify-between border-b border-zinc-100 px-5 py-2">
          <h2 id="payout-title" className="text-lg font-bold text-zinc-900">KTP & rekening</h2>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-4">
          {!profile && !loadError && (
            <div className="space-y-3" aria-hidden="true">
              <div className="h-16 animate-pulse rounded-2xl bg-zinc-100" />
              <div className="h-12 animate-pulse rounded-xl bg-zinc-100" />
              <div className="h-12 animate-pulse rounded-xl bg-zinc-100" />
            </div>
          )}
          {loadError && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{loadError}</p>}

          {profile && (
            <>
              <div className={cn('flex gap-3 rounded-2xl p-3 text-sm',
                status === 'verified' ? 'bg-emerald-50 text-emerald-900' : status === 'pending' ? 'bg-amber-50 text-amber-900' : status === 'rejected' ? 'bg-rose-50 text-rose-800' : 'bg-zinc-50 text-zinc-700')}>
                {status === 'verified' ? <CheckCircle2 className="h-5 w-5 shrink-0" /> : status === 'pending' ? <Clock3 className="h-5 w-5 shrink-0" /> : <AlertTriangle className="h-5 w-5 shrink-0" />}
                <div>
                  {status === 'verified' && <p><span className="font-semibold">Terverifikasi:</span> {profile.bank_name || profile.bank_code} · {profile.account_number_masked} · a.n. {profile.account_name}</p>}
                  {status === 'pending' && <p>Data kamu sedang ditinjau tim Hellom (paling lambat 1×24 jam). Penarikan terbuka setelah disetujui.</p>}
                  {status === 'rejected' && <p><span className="font-semibold">Belum disetujui.</span> {profile.review_notes || 'Periksa lagi data kamu.'} Perbaiki lalu kirim ulang.</p>}
                  {status === 'unverified' && <p>Wajib sekali saja sebelum menarik Saldo Penjualan. Nama rekening harus sama dengan nama di KTP.</p>}
                </div>
              </div>

              {!canManage && <p className="mt-4 text-sm text-zinc-600">Hanya pemilik atau admin toko yang bisa mengubah rekening.</p>}

              {canManage && status === 'verified' && !editing && (
                <button type="button" onClick={() => setEditing(true)} className="mt-4 flex min-h-12 w-full items-center justify-center rounded-2xl border border-zinc-300 text-sm font-semibold text-zinc-800">
                  Ganti rekening
                </button>
              )}

              {canManage && editing && status !== 'pending' && (
                <form onSubmit={submit} noValidate className="mt-4 space-y-4">
                  {status === 'verified' && (
                    <p className="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                      Rekening baru ditinjau ulang, dan penarikan berikutnya ditahan 24 jam demi keamanan. Kami kirim email pemberitahuan ke pemilik toko.
                    </p>
                  )}
                  <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Tujuan pencairan">
                    {([['bank', 'Rekening bank'], ['ewallet', 'E-wallet']] as const).map(([value, label]) => (
                      <button key={value} type="button" role="radio" aria-checked={form.destination_type === value} onClick={() => set('destination_type', value)}
                        className={cn('min-h-12 rounded-xl border text-sm font-semibold', form.destination_type === value ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-700')}>
                        {label}
                      </button>
                    ))}
                  </div>
                  {field('full_name', 'Nama sesuai KTP', { autoComplete: 'name' })}
                  {field('nik', 'NIK (16 angka)', { inputMode: 'numeric', autoComplete: 'off' })}
                  {field('bank_code', form.destination_type === 'ewallet' ? 'E-wallet' : 'Kode bank', { placeholder: form.destination_type === 'ewallet' ? 'DANA / OVO / GoPay' : 'BCA / BRI / Mandiri', autoCapitalize: 'characters' })}
                  {form.destination_type === 'bank' && field('bank_name', 'Nama bank (opsional)', { placeholder: 'Bank Central Asia' })}
                  {field('account_number', form.destination_type === 'ewallet' ? 'Nomor HP e-wallet' : 'Nomor rekening', { inputMode: 'numeric', autoComplete: 'off' })}
                  {field('account_name', 'Nama pemilik rekening (sama dengan KTP)')}
                  <label className="flex min-h-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed border-zinc-300 bg-zinc-50 p-3 text-center text-sm text-zinc-600">
                    <Camera className="h-5 w-5" />
                    <span className="font-semibold text-zinc-800">{file ? file.name : profile.has_ktp_image ? 'Ganti foto KTP (opsional)' : 'Foto KTP'}</span>
                    <span className="text-xs">JPG/PNG/WebP, maks 4 MB. Disimpan privat, hanya untuk verifikasi.</span>
                    <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                  </label>
                  {errors.ktp_image && <p className="text-xs text-rose-600">{errors.ktp_image}</p>}
                  {formError && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{formError}</p>}
                  <button type="submit" disabled={saving} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 text-base font-bold text-white disabled:opacity-50">
                    {saving && <Loader2 className="h-5 w-5 animate-spin" />} {saving ? 'Mengirim…' : 'Kirim untuk diverifikasi'}
                  </button>
                </form>
              )}
            </>
          )}
        </div>
        <div style={{ height: 'env(safe-area-inset-bottom)' }} />
      </div>
    </div>
  );
}
