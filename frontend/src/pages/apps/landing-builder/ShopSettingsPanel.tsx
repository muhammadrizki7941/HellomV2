import { useEffect, useState } from 'react';
import { Check, Copy, Download, ExternalLink, Loader2, Megaphone, Store } from 'lucide-react';
import QRCode from 'qrcode';
import { ApiError, getLandingSite, getLandingTracking, updateLandingTracking, updateLandingUsername } from '@/lib/hellomApi';
import type { LandingSite, LandingTracking, LandingTrackingInput } from '@/lib/hellomApi';

// Pengaturan tab: shop address (username), share link + QR, and ad pixels (Fase 4).
const inputClass = 'mt-1 min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none focus:border-zinc-900';

export default function ShopSettingsPanel() {
  return (
    <div className="mx-auto max-w-2xl space-y-6 pb-8">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Pengaturan toko</h1>
        <p className="text-sm text-zinc-600">Alamat halaman, link untuk dibagikan, dan pelacakan iklan.</p>
      </div>
      <AddressCard />
      <TrackingCard />
    </div>
  );
}

function AddressCard() {
  const [site, setSite] = useState<LandingSite | null>(null);
  const [username, setUsername] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    getLandingSite().then((s) => { setSite(s); setUsername(s.username); }).catch((e) => setMessage({ ok: false, text: e instanceof Error ? e.message : 'Gagal memuat' }));
  }, []);

  const save = async () => {
    setSaving(true);
    setMessage(null);
    try {
      const next = await updateLandingUsername(username.trim().toLowerCase());
      setSite(next);
      setMessage({ ok: true, text: 'Alamat disimpan. Link lama otomatis diarahkan ke alamat baru.' });
    } catch (e) {
      setMessage({ ok: false, text: e instanceof ApiError ? (e.fieldErrors.username?.[0] ?? e.message) : 'Gagal menyimpan' });
    } finally {
      setSaving(false);
    }
  };

  const copy = async () => {
    if (!site) return;
    try { await navigator.clipboard.writeText(site.public_url); } catch { window.prompt('Salin link:', site.public_url); }
    setCopied(true);
    window.setTimeout(() => setCopied(false), 2000);
  };

  const downloadQr = async () => {
    if (!site) return;
    const url = await QRCode.toDataURL(site.public_url, { width: 1024, margin: 2 });
    const a = document.createElement('a');
    a.href = url;
    a.download = `qr-${site.username}.png`;
    a.click();
  };

  const host = site ? new URL(site.public_url).host : '';

  return (
    <section className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
      <h2 className="flex items-center gap-2 font-bold"><Store className="h-5 w-5" /> Alamat halaman</h2>
      {!site ? (
        <div className="h-24 animate-pulse rounded-xl bg-zinc-100" />
      ) : (
        <>
          {site.suspended && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">Toko kamu sedang dinonaktifkan tim Hellom. Hubungi dukungan.</p>}
          <div className="flex flex-wrap gap-2">
            <input readOnly value={site.public_url} onFocus={(e) => e.currentTarget.select()} className="min-h-12 min-w-0 flex-1 rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm" />
            <button type="button" onClick={() => void copy()} className="flex min-h-12 items-center gap-1 rounded-xl bg-zinc-900 px-4 text-sm font-bold text-white">{copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}{copied ? 'Tersalin' : 'Salin'}</button>
          </div>
          <div className="grid grid-cols-3 gap-2">
            <a href={`https://wa.me/?text=${encodeURIComponent(site.public_url)}`} target="_blank" rel="noopener" className="flex min-h-12 items-center justify-center rounded-xl border border-zinc-200 text-sm font-semibold">WhatsApp</a>
            <button type="button" onClick={() => void downloadQr()} className="flex min-h-12 items-center justify-center gap-1 rounded-xl border border-zinc-200 text-sm font-semibold"><Download className="h-4 w-4" /> QR</button>
            <a href={site.public_url} target="_blank" rel="noopener" className="flex min-h-12 items-center justify-center gap-1 rounded-xl border border-zinc-200 text-sm font-semibold"><ExternalLink className="h-4 w-4" /> Buka</a>
          </div>
          <label className="block text-sm font-medium">
            Username
            <div className="mt-1 flex items-center rounded-xl border border-zinc-300 bg-white focus-within:border-zinc-900">
              <span className="pl-3 text-sm text-zinc-500">{host}/</span>
              <input value={username} onChange={(e) => setUsername(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} maxLength={30} className="min-h-12 min-w-0 flex-1 bg-transparent px-1 text-base outline-none" />
            </div>
            <span className="mt-1 block text-xs font-normal text-zinc-500">3–30 karakter: huruf kecil, angka, atau tanda minus.</span>
          </label>
          {message && <p role="status" className={message.ok ? 'text-sm text-emerald-700' : 'text-sm text-rose-600'}>{message.text}</p>}
          <button type="button" disabled={saving || username === site.username || username.length < 3} onClick={() => void save()} className="flex min-h-12 items-center gap-2 rounded-xl bg-zinc-900 px-5 font-bold text-white disabled:opacity-40">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />} Simpan username
          </button>
        </>
      )}
    </section>
  );
}

const FIELDS: Array<{ key: keyof LandingTrackingInput; label: string; placeholder: string; hint: string }> = [
  { key: 'meta_pixel_id', label: 'Meta (Facebook/Instagram) Pixel ID', placeholder: '123456789012345', hint: 'Events Manager › Data sources › Pixel ID (angka).' },
  { key: 'ga4_id', label: 'Google Analytics 4', placeholder: 'G-XXXXXXXXXX', hint: 'Admin › Data streams › Measurement ID.' },
  { key: 'google_ads_id', label: 'Google Ads Conversion ID', placeholder: 'AW-123456789', hint: 'Tools › Conversions › tag setup.' },
  { key: 'google_ads_label', label: 'Google Ads Conversion Label', placeholder: 'AbCdEfGhIj', hint: 'Label konversi pembelian.' },
  { key: 'tiktok_pixel_id', label: 'TikTok Pixel ID', placeholder: 'C1234567890ABCDEFGHI', hint: 'TikTok Ads Manager › Events › Web events.' },
];

function TrackingCard() {
  const [data, setData] = useState<LandingTracking | null>(null);
  const [form, setForm] = useState<LandingTrackingInput>({});
  const [token, setToken] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    getLandingTracking().then((t) => {
      setData(t);
      setForm({ meta_pixel_id: t.meta_pixel_id ?? '', ga4_id: t.ga4_id ?? '', google_ads_id: t.google_ads_id ?? '', google_ads_label: t.google_ads_label ?? '', tiktok_pixel_id: t.tiktok_pixel_id ?? '', meta_test_event_code: t.meta_test_event_code ?? '' });
    }).catch(() => undefined);
  }, []);

  const save = async (extra: LandingTrackingInput = {}) => {
    setSaving(true);
    setErrors({});
    setSaved(false);
    try {
      const next = await updateLandingTracking({ ...form, ...(token.trim() ? { meta_capi_token: token.trim() } : {}), ...extra });
      setData(next);
      setToken('');
      setSaved(true);
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]])));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
      <div>
        <h2 className="flex items-center gap-2 font-bold"><Megaphone className="h-5 w-5" /> Iklan & pelacakan</h2>
        <p className="mt-1 text-sm text-zinc-500">Pasang ID pixel supaya iklan Meta, Google, dan TikTok bisa mengukur kunjungan dan pembelian. Script dimuat setelah halaman tampil, jadi tidak memperlambat.</p>
      </div>
      {!data ? <div className="h-40 animate-pulse rounded-xl bg-zinc-100" /> : (
        <form onSubmit={(e) => { e.preventDefault(); void save(); }} className="space-y-3">
          {FIELDS.map((f) => (
            <label key={f.key} className="block text-sm font-medium">
              {f.label}
              <input value={String(form[f.key] ?? '')} onChange={(e) => setForm({ ...form, [f.key]: e.target.value.trim() })} placeholder={f.placeholder} className={inputClass} aria-invalid={errors[f.key] ? true : undefined} />
              {errors[f.key] ? <span className="mt-1 block text-sm text-rose-600">{errors[f.key]}</span> : <span className="mt-1 block text-xs font-normal text-zinc-500">{f.hint}</span>}
            </label>
          ))}
          <details className="rounded-xl bg-zinc-50 p-3 text-sm">
            <summary className="cursor-pointer font-semibold">Meta Conversions API (opsional, lanjutan)</summary>
            <p className="mt-2 text-xs text-zinc-500">Kirim pembelian dari server Hellom langsung ke Meta, lebih akurat untuk iklan (tidak terhalang ad-blocker). Token disimpan terenkripsi dan tidak pernah ditampilkan lagi.</p>
            <label className="mt-2 block font-medium">Access token
              <input type="password" autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)} placeholder={data.meta_capi_token_set ? '•••••••• (sudah terisi)' : 'EAAB…'} className={inputClass} />
              {errors.meta_capi_token && <span className="mt-1 block text-sm text-rose-600">{errors.meta_capi_token}</span>}
            </label>
            <label className="mt-2 block font-medium">Kode uji (opsional)
              <input value={String(form.meta_test_event_code ?? '')} onChange={(e) => setForm({ ...form, meta_test_event_code: e.target.value.trim() })} placeholder="TEST12345" className={inputClass} />
            </label>
            {data.meta_capi_token_set && <button type="button" onClick={() => void save({ clear_meta_capi_token: true })} className="mt-2 min-h-11 text-sm font-semibold text-rose-600">Hapus token</button>}
          </details>
          <div className="flex items-center gap-3">
            <button type="submit" disabled={saving} className="flex min-h-12 items-center gap-2 rounded-xl bg-zinc-900 px-5 font-bold text-white disabled:opacity-40">{saving && <Loader2 className="h-4 w-4 animate-spin" />} Simpan</button>
            {saved && <span className="flex items-center gap-1 text-sm text-emerald-700"><Check className="h-4 w-4" /> Tersimpan</span>}
          </div>
        </form>
      )}
    </section>
  );
}
