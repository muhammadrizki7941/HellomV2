import { useEffect, useState } from 'react';
import { Check, ExternalLink, History, Home, Loader2, Monitor, Plus, Smartphone, Trash2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  createLandingSitePage,
  deleteLandingSitePage,
  getImageUrl,
  getLandingHistory,
  updateLandingSitePage,
  uploadLandingAsset,
} from '@/lib/hellomApi';
import type { LandingSite, LandingSitePage, LandingVersion } from '@/lib/hellomApi';

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-');

function Sheet({ title, onClose, children, wide }: { title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" onClick={onClose}>
      <div className={cn('flex max-h-[92svh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl', wide ? 'max-w-5xl' : 'max-w-lg')} onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label={title}>
        <header className="flex shrink-0 items-center justify-between border-b border-zinc-100 px-4 py-2">
          <h3 className="text-base font-bold">{title}</h3>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </header>
        <div className="flex-1 overflow-y-auto p-4" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom))' }}>{children}</div>
      </div>
    </div>
  );
}

export function HistoryDialog({ pageId, onClose, onRestore }: { pageId: number; onClose: () => void; onRestore: (versionId: number, versionNo: number) => Promise<void> }) {
  const [items, setItems] = useState<LandingVersion[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  useEffect(() => {
    getLandingHistory(pageId).then((r) => setItems(r.items)).catch((e) => setError(e instanceof Error ? e.message : 'Riwayat belum bisa dimuat'));
  }, [pageId]);

  return (
    <Sheet title="Riwayat terbit" onClose={onClose}>
      <p className="mb-3 text-sm text-zinc-500">Setiap kali terbit, versinya disimpan. Kembalikan versi lama ke draft, lalu terbitkan lagi kalau sudah pas.</p>
      {error && <p className="text-sm text-rose-600">{error}</p>}
      {items === null && !error && <div className="h-24 animate-pulse rounded-xl bg-zinc-100" />}
      {items?.length === 0 && <p className="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500">Belum pernah terbit.</p>}
      <ul className="divide-y divide-zinc-100">
        {items?.map((v) => (
          <li key={v.id} className="flex items-center justify-between gap-3 py-3">
            <div>
              <p className="font-semibold">Versi {v.version_no} {v.is_live && <span className="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Tayang</span>}</p>
              <p className="text-xs text-zinc-500">{when(v.published_at)}{v.blocks !== null ? ` · ${v.blocks} blok` : ''}</p>
            </div>
            <button type="button" disabled={busy !== null} onClick={async () => { setBusy(v.id); await onRestore(v.id, v.version_no); setBusy(null); }}
              className="flex min-h-11 items-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-semibold disabled:opacity-50">
              {busy === v.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <History className="h-4 w-4" />} Kembalikan
            </button>
          </li>
        ))}
      </ul>
    </Sheet>
  );
}

export function PreviewDialog({ url, onClose }: { url: string; onClose: () => void }) {
  const [device, setDevice] = useState<'phone' | 'desktop'>('phone');
  return (
    <Sheet title="Pratinjau draft" onClose={onClose} wide>
      <div className="mb-3 flex items-center justify-between gap-2">
        <div className="inline-flex rounded-xl bg-zinc-100 p-1">
          {([['phone', 'HP', Smartphone], ['desktop', 'Desktop', Monitor]] as const).map(([key, label, Icon]) => (
            <button key={key} type="button" onClick={() => setDevice(key)} className={cn('flex min-h-10 items-center gap-1 rounded-lg px-3 text-sm font-semibold', device === key ? 'bg-white shadow-sm' : 'text-zinc-500')}>
              <Icon className="h-4 w-4" /> {label}
            </button>
          ))}
        </div>
        <a href={url} target="_blank" rel="noopener" className="flex min-h-10 items-center gap-1 text-sm font-semibold text-zinc-600"><ExternalLink className="h-4 w-4" /> Buka di tab baru</a>
      </div>
      <p className="mb-3 text-xs text-zinc-500">Ini tampilan asli dari server (sama persis dengan halaman publik), belum tayang sampai kamu tekan Terbitkan.</p>
      <div className="flex justify-center overflow-hidden rounded-2xl bg-zinc-100 p-3">
        <iframe title="Pratinjau halaman" src={url} className="rounded-xl border border-zinc-200 bg-white" style={{ width: device === 'phone' ? 390 : '100%', height: '70svh', maxWidth: '100%' }} />
      </div>
    </Sheet>
  );
}

export function PagesDialog({ site, currentPageId, onClose, onChanged, onOpenPage }: {
  site: LandingSite;
  currentPageId: number | null;
  onClose: () => void;
  onChanged: () => Promise<void>;
  onOpenPage: (page: LandingSitePage) => void;
}) {
  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<LandingSitePage | null>(null);
  const full = site.quota.used >= site.quota.pages;

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      await onChanged();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gagal');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet title="Halaman" onClose={onClose}>
      <p className="mb-3 text-sm text-zinc-500">{site.quota.used} dari {site.quota.pages} halaman dipakai. {site.quota.pages <= site.quota.free ? 'Paket gratis: 1 halaman.' : ''}</p>
      {error && <p role="alert" className="mb-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <ul className="space-y-2">
        {site.pages.map((p) => (
          <li key={p.id} className={cn('rounded-2xl border p-3', p.id === currentPageId ? 'border-zinc-900' : 'border-zinc-200')}>
            <div className="flex items-start justify-between gap-2">
              <button type="button" onClick={() => onOpenPage(p)} className="min-w-0 flex-1 text-left">
                <p className="truncate font-semibold">{p.is_home && <Home className="mr-1 inline h-4 w-4" />}{p.title}</p>
                <p className="truncate text-xs text-zinc-500">{p.url.replace(/^https?:\/\//, '')}</p>
                <p className="text-xs">{p.status === 'published' ? (p.is_live ? <span className="text-emerald-700">Tayang</span> : <span className="text-amber-700">Terbit, tapi melebihi kuota paket</span>) : <span className="text-zinc-500">Draft</span>}{p.has_unpublished_changes && p.status === 'published' ? ' · ada perubahan belum terbit' : ''}</p>
              </button>
              <button type="button" onClick={() => setEditing(editing?.id === p.id ? null : p)} className="min-h-11 rounded-xl border px-3 text-sm font-semibold">Atur</button>
            </div>
            {editing?.id === p.id && <PageSettingsForm page={p} busy={busy} onSave={(body) => run(() => updateLandingSitePage(p.id, body))}
              onDelete={site.pages.length > 1 ? () => { if (window.confirm(`Hapus halaman "${p.title}"?`)) void run(() => deleteLandingSitePage(p.id)); } : undefined} />}
          </li>
        ))}
      </ul>
      <div className="mt-4 rounded-2xl bg-zinc-50 p-3">
        {full ? (
          <p className="text-sm text-zinc-600">Mau punya lebih banyak halaman (misalnya halaman khusus kelas atau promo)? Upgrade paket Hellom Page di menu <a href="/dashboard/billing" className="font-semibold underline">Tagihan &amp; Paket</a>.</p>
        ) : (
          <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); if (title.trim()) void run(async () => { await createLandingSitePage({ title: title.trim() }); setTitle(''); }); }}>
            <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Judul halaman baru" className="min-h-11 flex-1 rounded-xl border border-zinc-300 px-3 text-base" maxLength={120} />
            <button type="submit" disabled={busy || !title.trim()} className="flex min-h-11 items-center gap-1 rounded-xl bg-zinc-900 px-3 text-sm font-bold text-white disabled:opacity-40"><Plus className="h-4 w-4" /> Tambah</button>
          </form>
        )}
      </div>
    </Sheet>
  );
}

function PageSettingsForm({ page, busy, onSave, onDelete }: {
  page: LandingSitePage;
  busy: boolean;
  onSave: (body: Partial<LandingSitePage>) => void;
  onDelete?: () => void;
}) {
  const [form, setForm] = useState({ title: page.title, slug: page.slug, seo_title: page.seo_title ?? '', seo_description: page.seo_description ?? '' });
  const input = 'mt-1 min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-base';
  return (
    <div className="mt-3 space-y-2 border-t border-zinc-100 pt-3 text-sm">
      <label className="block font-medium">Judul<input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className={input} maxLength={120} /></label>
      {!page.is_home && <label className="block font-medium">Alamat (…/{form.slug})<input value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-') })} className={input} maxLength={60} /></label>}
      <label className="block font-medium">Judul untuk Google & WhatsApp <span className="font-normal text-zinc-500">(opsional)</span><input value={form.seo_title} onChange={(e) => setForm({ ...form, seo_title: e.target.value })} className={input} maxLength={120} /></label>
      <label className="block font-medium">Deskripsi singkat <span className="font-normal text-zinc-500">(opsional)</span>
        <textarea value={form.seo_description} onChange={(e) => setForm({ ...form, seo_description: e.target.value })} rows={2} maxLength={300} className="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-base" />
      </label>
      <ShareImage page={page} busy={busy} onSave={onSave} />
      <div className="flex flex-wrap gap-2 pt-1">
        <button type="button" disabled={busy} onClick={() => onSave({ title: form.title, ...(page.is_home ? {} : { slug: form.slug }), seo_title: form.seo_title || null, seo_description: form.seo_description || null })}
          className="flex min-h-11 items-center gap-1 rounded-xl bg-zinc-900 px-4 font-bold text-white disabled:opacity-40"><Check className="h-4 w-4" /> Simpan</button>
        {!page.is_home && <button type="button" disabled={busy} onClick={() => onSave({ is_home: true })} className="flex min-h-11 items-center gap-1 rounded-xl border px-3 font-semibold"><Home className="h-4 w-4" /> Jadikan halaman utama</button>}
        {onDelete && <button type="button" disabled={busy} onClick={onDelete} className="flex min-h-11 items-center gap-1 rounded-xl border border-rose-200 px-3 font-semibold text-rose-700"><Trash2 className="h-4 w-4" /> Hapus</button>}
      </div>
    </div>
  );
}

/** Picture shown when the link is shared (WhatsApp, Facebook…): automatic card, or the seller's own. */
function ShareImage({ page, busy, onSave }: { page: LandingSitePage; busy: boolean; onSave: (body: Partial<LandingSitePage>) => void }) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const shown = page.seo_image ?? page.share_card;
  const upload = async (file: File | undefined) => {
    if (!file) return;
    setUploading(true);
    setError(null);
    try {
      const asset = await uploadLandingAsset(file);
      onSave({ seo_image: asset.url });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Gambar belum bisa diunggah');
    } finally {
      setUploading(false);
    }
  };
  return (
    <div className="space-y-2 pt-1" data-share-image>
      <p className="font-medium">Gambar saat link dibagikan</p>
      {shown ? (
        <img src={getImageUrl(shown)} alt="Pratinjau gambar saat dibagikan" className="aspect-[1200/630] w-full rounded-xl border border-zinc-200 object-cover" />
      ) : (
        <p className="rounded-xl bg-zinc-50 p-3 text-xs text-zinc-500">Otomatis dibuat dari foto profil, nama, dan warna halaman setelah halaman terbit.</p>
      )}
      <p className="text-xs text-zinc-500">{page.seo_image ? 'Memakai gambar kamu sendiri.' : 'Otomatis: foto profil + nama toko. Ukuran terbaik gambar sendiri 1200 × 630.'}</p>
      <div className="flex flex-wrap gap-2">
        <label className={cn('flex min-h-11 cursor-pointer items-center gap-1 rounded-xl border px-3 font-semibold', (busy || uploading) && 'pointer-events-none opacity-50')}>
          {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />} {page.seo_image ? 'Ganti gambar' : 'Pakai gambar sendiri'}
          <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => void upload(e.target.files?.[0])} />
        </label>
        {page.seo_image && <button type="button" disabled={busy} onClick={() => onSave({ seo_image: null })} className="min-h-11 rounded-xl border px-3 font-semibold">Kembali ke otomatis</button>}
      </div>
      {error && <p className="text-xs text-rose-700">{error}</p>}
    </div>
  );
}
