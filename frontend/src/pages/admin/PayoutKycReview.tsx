import { useEffect, useRef, useState } from 'react';
import { BadgeCheck, Eye, ShieldCheck, X, RefreshCw } from 'lucide-react';
import { getAdminPayoutProfiles, approvePayoutProfile, rejectPayoutProfile, fetchAuthorizedBlobUrl } from '@/lib/hellomApi';

interface KycProfile {
  id: number;
  organization?: { id: number; name: string; slug: string } | null;
  status: string;
  full_name?: string;
  nik?: string;
  bank_code?: string;
  bank_name?: string;
  account_number?: string;
  account_name?: string;
  ktp_image_url?: string | null;
  submitted_at?: string | null;
}

/** Super-admin: review seller KYC (KTP + bank) before withdrawals are unlocked. */
export default function PayoutKycReview() {
  const [items, setItems] = useState<KycProfile[]>([]);
  const [loading, setLoading] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  // The KTP image is behind the API token, so it is loaded as a blob, never a plain link.
  const [ktpPreview, setKtpPreview] = useState<Record<number, string>>({});
  const [ktpLoadingId, setKtpLoadingId] = useState<number | null>(null);
  const previewUrls = useRef<string[]>([]);

  useEffect(() => () => previewUrls.current.forEach((url) => URL.revokeObjectURL(url)), []);

  const toggleKtp = async (id: number) => {
    if (ktpPreview[id]) {
      setKtpPreview(({ [id]: _hidden, ...rest }) => rest);
      return;
    }
    setKtpLoadingId(id);
    try {
      const url = await fetchAuthorizedBlobUrl(`/admin/payout-profiles/${id}/ktp`);
      previewUrls.current.push(url);
      setKtpPreview((current) => ({ ...current, [id]: url }));
    } catch {
      setError('Foto KTP tidak bisa dimuat. Coba refresh halaman.');
    } finally {
      setKtpLoadingId(null);
    }
  };

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getAdminPayoutProfiles('pending') as { items?: KycProfile[] };
      setItems(res.items || []);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gagal memuat antrian verifikasi');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const approve = async (id: number) => {
    if (!window.confirm('Setujui verifikasi ini? Pastikan nama di KTP sama dengan nama pemilik rekening.')) return;
    setBusyId(id);
    try {
      await approvePayoutProfile(id);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gagal menyetujui');
    } finally {
      setBusyId(null);
    }
  };

  const reject = async (id: number) => {
    const notes = window.prompt('Alasan penolakan (akan dilihat user):');
    if (!notes) return;
    setBusyId(id);
    try {
      await rejectPayoutProfile(id, notes);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gagal menolak');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
      <div className="flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-sm font-bold text-zinc-900">
          <ShieldCheck className="h-4 w-4 text-emerald-600" /> Verifikasi KTP & Rekening Penjual
          {items.length > 0 && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{items.length} menunggu</span>}
        </h3>
        <button onClick={() => void load()} className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-50">
          <RefreshCw className={loading ? 'h-3.5 w-3.5 animate-spin' : 'h-3.5 w-3.5'} /> Refresh
        </button>
      </div>

      {error && <p className="mt-3 rounded-lg bg-red-50 border border-red-100 px-3 py-2 text-sm text-red-700">{error}</p>}

      <div className="mt-4 space-y-3">
        {items.length === 0 && !loading && (
          <p className="rounded-xl border border-dashed border-zinc-200 px-4 py-6 text-center text-sm text-zinc-500">Tidak ada verifikasi yang menunggu.</p>
        )}
        {items.map((p) => (
          <div key={p.id} className="rounded-xl border border-zinc-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="font-semibold text-zinc-900">{p.organization?.name || '—'} <span className="text-xs text-zinc-400">/{p.organization?.slug}</span></p>
                <p className="mt-1 text-sm text-zinc-600">{p.full_name} · NIK {p.nik}</p>
                <p className="text-sm text-zinc-600">{p.bank_code} {p.bank_name} · {p.account_number} a.n. {p.account_name}</p>
                {p.ktp_image_url && (
                  <button type="button" onClick={() => void toggleKtp(p.id)} disabled={ktpLoadingId === p.id} className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-blue-600 underline disabled:opacity-60">
                    <Eye className="h-3.5 w-3.5" /> {ktpLoadingId === p.id ? 'Memuat foto KTP…' : ktpPreview[p.id] ? 'Sembunyikan foto KTP' : 'Lihat foto KTP'}
                  </button>
                )}
                {ktpPreview[p.id] && (
                  <img src={ktpPreview[p.id]} alt={`KTP ${p.full_name ?? ''}`} className="mt-2 max-h-72 w-full max-w-md rounded-lg border border-zinc-200 object-contain" />
                )}
              </div>
              <div className="flex shrink-0 gap-2">
                <button onClick={() => void approve(p.id)} disabled={busyId === p.id} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                  <BadgeCheck className="h-4 w-4" /> Setujui
                </button>
                <button onClick={() => void reject(p.id)} disabled={busyId === p.id} className="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-100 disabled:opacity-60">
                  <X className="h-4 w-4" /> Tolak
                </button>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
