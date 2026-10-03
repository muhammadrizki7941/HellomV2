import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Check, Copy, ExternalLink, FileUp, Link2, Loader2, MessageCircle, Share2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  ApiError,
  createLandingSitePage,
  createSellerProduct,
  getLandingDraft,
  getSessionUser,
  publishLandingSitePage,
  saveLandingDraft,
  updateLandingUsername,
  uploadSellerProductFile,
} from '@/lib/hellomApi';
import type { LandingDocument, LandingOnboarding, PageTemplate, SellerProduct } from '@/lib/hellomApi';
import { loadPageTemplates, templateBlocks } from './editor/pageTemplates';
import { resetSellerProducts } from './sellerProducts';
import DriveGuide from './DriveGuide';
import { useOptionalEditorPreference } from './editorPreference';

/**
 * New seller onboarding (Fase 5): 1 username → 2 template → 3 first product, then the page is
 * published and ready to share. Every step saves for real, so closing halfway keeps progress.
 */
type Step = 1 | 2 | 3 | 4;
type ProductKind = 'drive' | 'file';

const KEEP_DRAFT = 'keep';
const USERNAME_RE = /^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/;

const inputClass = 'min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base text-zinc-900 outline-none focus:border-zinc-900 focus:ring-1 focus:ring-zinc-900';

function errorText(err: unknown, fallback: string): string {
  if (err instanceof ApiError) {
    const first = Object.values(err.fieldErrors)[0]?.[0];
    return first || err.message || fallback;
  }
  return err instanceof Error && err.message ? err.message : fallback;
}

export default function OnboardingWizard({ data, onClose, onDone }: { data: LandingOnboarding; onClose: () => void; onDone: () => void }) {
  const [step, setStep] = useState<Step>(1);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [username, setUsername] = useState(data.username);
  const hostPrefix = data.public_url.replace(/^https?:\/\//, '').replace(new RegExp(`${data.username}/?$`), '');
  // Starting template suggested by the editor preset (what the seller used before).
  const presetTemplate = useOptionalEditorPreference()?.preset.templateId;
  const [templates, setTemplates] = useState<PageTemplate[] | null>(null);
  const [template, setTemplate] = useState<string>(data.home_page && data.home_page.draft_blocks > 0 ? KEEP_DRAFT : '');
  useEffect(() => {
    loadPageTemplates().then((list) => {
      setTemplates(list.templates);
      // Suggested template for what the seller used before (hidden by super admin → the first one).
      setTemplate((current) => current || (list.templates.find((t) => t.id === presetTemplate) ?? list.templates[0])?.id || '');
    }).catch(() => setTemplates([]));
  }, [presetTemplate]);

  const [kind, setKind] = useState<ProductKind>('drive');
  const [name, setName] = useState('');
  const [price, setPrice] = useState('');
  const [driveUrl, setDriveUrl] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [publicUrl, setPublicUrl] = useState(data.public_url);
  const [copied, setCopied] = useState(false);
  const bodyRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bodyRef.current?.scrollTo({ top: 0 });
    setError(null);
  }, [step]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !busy) onClose(); };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; };
  }, [busy, onClose]);

  const saveUsername = async () => {
    const value = username.trim().toLowerCase();
    if (!USERNAME_RE.test(value)) {
      setError('Username 3–30 karakter: huruf kecil, angka, atau tanda minus (tidak di awal/akhir).');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      if (value !== data.username || !data.username_is_custom) {
        const site = await updateLandingUsername(value);
        setPublicUrl(site.public_url);
      }
      setStep(2);
    } catch (err) {
      setError(errorText(err, 'Username belum tersimpan. Coba lagi.'));
    } finally {
      setBusy(false);
    }
  };

  /** Template (or the existing draft) + the new product, saved as the home page draft. */
  const buildDocument = async (product: SellerProduct | null): Promise<{ doc: LandingDocument; revision: number | null }> => {
    let doc: LandingDocument;
    let revision: number | null = null;
    if (data.home_page) {
      const draft = await getLandingDraft(data.home_page.id);
      revision = draft.revision;
      doc = draft.document;
    } else {
      doc = { theme: {}, settings: {}, blocks: [] };
    }
    const chosen = templates?.find((t) => t.id === template);
    if (chosen) {
      const shopName = getSessionUser<{ current_organization?: { name?: string } }>()?.current_organization?.name ?? '';
      doc = { ...doc, theme: { ...chosen.document.theme }, blocks: templateBlocks(chosen, doc.blocks, shopName) };
    }
    if (product) {
      const blocks = [...doc.blocks];
      const empty = blocks.findIndex((b) => b.type === 'product' && !b.content.productId);
      const listed = blocks.some((b) => b.type === 'catalog' && b.content.showAll !== false);
      if (empty >= 0) {
        blocks[empty] = { ...blocks[empty], content: { ...blocks[empty].content, productId: product.id } };
      } else if (!listed) {
        blocks.splice(Math.min(1, blocks.length), 0, { id: `p${Date.now().toString(36)}`, type: 'product', hidden: false, content: { productId: product.id, buttonText: 'Beli sekarang' }, styles: {} });
      }
      doc = { ...doc, blocks };
    }

    return { doc, revision };
  };

  const publish = async (withProduct: boolean) => {
    setError(null);
    const amount = Number(price.replace(/\D/g, ''));
    if (withProduct) {
      if (name.trim().length < 3) return setError('Nama produk minimal 3 huruf.');
      if (amount < 10000) return setError('Harga minimal Rp10.000.');
      if (kind === 'drive' && !/^https:\/\/(drive|docs)\.google\.com\//.test(driveUrl.trim())) return setError('Tempel link Google Drive produk kamu (diawali https://drive.google.com/).');
      if (kind === 'file' && !file) return setError('Pilih file produk dulu.');
    }
    setBusy(true);
    try {
      let product: SellerProduct | null = null;
      if (withProduct) {
        product = await createSellerProduct({ type: kind, name: name.trim(), price: amount, delivery_url: kind === 'drive' ? driveUrl.trim() : null });
        if (kind === 'file' && file) {
          const form = new FormData();
          form.append('file', file);
          product = await uploadSellerProductFile(product.db_id, form);
        }
        resetSellerProducts();
      }
      const { doc, revision } = await buildDocument(product);
      let pageId = data.home_page?.id ?? null;
      if (pageId) {
        await saveLandingDraft(pageId, doc, revision);
      } else {
        pageId = (await createLandingSitePage({ title: 'Beranda', document: doc })).id;
      }
      const published = await publishLandingSitePage(pageId);
      setPublicUrl(published.page.url || publicUrl);
      setStep(4);
    } catch (err) {
      setError(errorText(err, 'Halaman belum bisa diterbitkan. Coba lagi.'));
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(publicUrl);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setError('Link belum tersalin. Tekan lama link di atas untuk menyalin.');
    }
  };

  const share = async () => {
    if (navigator.share) {
      try { await navigator.share({ title: 'Toko aku di Hellom', url: publicUrl }); } catch { /* dibatalkan */ }
    } else {
      await copy();
    }
  };

  const finish = () => { onDone(); onClose(); };

  const primary = (() => {
    if (step === 1) return { label: 'Simpan & lanjut', action: () => void saveUsername() };
    if (step === 2) return { label: 'Pakai template ini', action: () => setStep(3) };
    if (step === 3) return { label: 'Terbitkan halaman', action: () => void publish(true) };
    return { label: 'Selesai', action: finish };
  })();

  return (
    <div className="fixed inset-0 z-[80] flex items-end justify-center bg-black/40 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="onboarding-title">
      <div className="flex h-[100svh] w-full flex-col bg-white sm:h-auto sm:max-h-[90svh] sm:max-w-lg sm:rounded-3xl sm:shadow-2xl">
        <header className="flex items-center gap-2 border-b border-zinc-100 px-2 py-2 sm:px-4">
          {step > 1 && step < 4 ? (
            <button type="button" onClick={() => setStep((step - 1) as Step)} disabled={busy} aria-label="Kembali" className="flex h-11 w-11 items-center justify-center rounded-full text-zinc-600 hover:bg-zinc-100">
              <ArrowLeft className="h-5 w-5" />
            </button>
          ) : <span className="w-11" />}
          <div className="min-w-0 flex-1 text-center">
            <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">{step < 4 ? `Langkah ${step} dari 3` : 'Siap dibagikan'}</p>
            <div className="mx-auto mt-1 flex max-w-[160px] gap-1" aria-hidden="true">
              {[1, 2, 3].map((n) => <span key={n} className={cn('h-1 flex-1 rounded-full', n <= step ? 'bg-yellow-400' : 'bg-zinc-200')} />)}
            </div>
          </div>
          <button type="button" onClick={onClose} disabled={busy} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full text-zinc-600 hover:bg-zinc-100">
            <X className="h-5 w-5" />
          </button>
        </header>

        <div ref={bodyRef} className="flex-1 overflow-y-auto px-4 py-5 sm:px-6">
          {step === 1 && (
            <section className="space-y-4">
              <div>
                <h2 id="onboarding-title" className="text-xl font-bold text-zinc-900">Pilih alamat toko kamu</h2>
                <p className="mt-1 text-sm text-zinc-600">Ini link yang kamu pasang di bio Instagram/TikTok dan bagikan ke pembeli.</p>
              </div>
              <label className="block text-sm font-semibold text-zinc-800" htmlFor="onb-username">Username</label>
              <div className="flex min-h-12 items-center overflow-hidden rounded-xl border border-zinc-300 bg-white focus-within:border-zinc-900 focus-within:ring-1 focus-within:ring-zinc-900">
                <span className="shrink-0 pl-3 text-sm text-zinc-400">{hostPrefix}</span>
                <input
                  id="onb-username"
                  value={username}
                  onChange={(e) => setUsername(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                  onKeyDown={(e) => { if (e.key === 'Enter') void saveUsername(); }}
                  maxLength={30}
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  autoFocus
                  className="min-w-0 flex-1 bg-transparent py-3 pr-3 text-base text-zinc-900 outline-none"
                />
              </div>
              <p className="text-xs text-zinc-500">3–30 karakter: huruf kecil, angka, atau tanda minus. Bisa diganti nanti; link lama tetap diarahkan.</p>
            </section>
          )}

          {step === 2 && (
            <section className="space-y-4">
              <div>
                <h2 id="onboarding-title" className="text-xl font-bold text-zinc-900">Pilih tampilan halaman</h2>
                <p className="mt-1 text-sm text-zinc-600">Semua bisa diubah lagi di Editor: teks, warna, urutan blok.</p>
              </div>
              <div className="grid gap-3" role="radiogroup" aria-label="Template">
                {data.home_page && data.home_page.draft_blocks > 0 && (
                  <TemplateOption selected={template === KEEP_DRAFT} onSelect={() => setTemplate(KEEP_DRAFT)} name="Pakai isi halaman sekarang" description={`Draft kamu (${data.home_page.draft_blocks} blok) tetap dipakai, produk baru ditambahkan.`} colors={['#ffffff', '#18181b', '#facc15']} />
                )}
                {templates === null && <div className="h-40 animate-pulse rounded-2xl bg-zinc-100" aria-busy="true" />}
                {(templates ?? []).map((t) => {
                  const theme = t.document.theme;
                  return (
                    <TemplateOption key={t.id} selected={template === t.id} onSelect={() => setTemplate(t.id)} name={t.name} description={t.description} suggested={t.id === presetTemplate}
                      colors={[theme.bg?.color ?? theme.bg?.from ?? theme.background ?? '#ffffff', theme.text ?? '#000000', theme.primary ?? '#facc15']} />
                  );
                })}
              </div>
            </section>
          )}

          {step === 3 && (
            <section className="space-y-4">
              <div>
                <h2 id="onboarding-title" className="text-xl font-bold text-zinc-900">Tambah produk pertama</h2>
                <p className="mt-1 text-sm text-zinc-600">Pembeli bayar, lalu akses produk dikirim otomatis ke email mereka.</p>
              </div>
              <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Jenis produk">
                {([['drive', 'Link Google Drive', Link2], ['file', 'Upload file', FileUp]] as const).map(([key, label, Icon]) => (
                  <button key={key} type="button" role="radio" aria-checked={kind === key} onClick={() => setKind(key)}
                    className={cn('flex min-h-12 items-center justify-center gap-2 rounded-xl border px-3 text-sm font-semibold', kind === key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-700')}>
                    <Icon className="h-4 w-4" /> {label}
                  </button>
                ))}
              </div>
              <label className="block space-y-1 text-sm font-semibold text-zinc-800">
                <span>Nama produk</span>
                <input value={name} onChange={(e) => setName(e.target.value)} maxLength={200} placeholder="Contoh: E-book Resep Kue Kering" className={inputClass} />
              </label>
              <label className="block space-y-1 text-sm font-semibold text-zinc-800">
                <span>Harga</span>
                <div className="flex min-h-12 items-center rounded-xl border border-zinc-300 bg-white focus-within:border-zinc-900 focus-within:ring-1 focus-within:ring-zinc-900">
                  <span className="pl-3 text-base text-zinc-500">Rp</span>
                  <input value={price} onChange={(e) => { const n = e.target.value.replace(/\D/g, ''); setPrice(n ? Number(n).toLocaleString('id-ID') : ''); }}
                    inputMode="numeric" placeholder="49.000" className="min-w-0 flex-1 bg-transparent px-2 py-3 text-base text-zinc-900 outline-none" />
                </div>
              </label>
              {kind === 'drive' ? (
                <>
                  <label className="block space-y-1 text-sm font-semibold text-zinc-800">
                    <span>Link Google Drive</span>
                    <input value={driveUrl} onChange={(e) => setDriveUrl(e.target.value)} inputMode="url" autoCapitalize="none" placeholder="https://drive.google.com/…" className={inputClass} />
                    <span className="block text-xs font-normal text-zinc-500">Link tidak pernah tampil di halaman; pembeli membukanya lewat halaman akses setelah bayar.</span>
                  </label>
                  <DriveGuide />
                </>
              ) : (
                <label className="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed border-zinc-300 bg-zinc-50 p-4 text-center text-sm text-zinc-600">
                  <FileUp className="h-5 w-5" />
                  <span className="font-semibold text-zinc-800">{file ? file.name : 'Pilih file (PDF, ZIP, video, dll.)'}</span>
                  <span className="text-xs">Maksimal 10 MB. Disimpan privat, hanya pembeli yang bisa mengunduh.</span>
                  <input type="file" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                </label>
              )}
              <button type="button" onClick={() => void publish(false)} disabled={busy} className="min-h-11 w-full text-sm font-semibold text-zinc-500 underline">
                Lewati, terbitkan tanpa produk dulu
              </button>
            </section>
          )}

          {step === 4 && (
            <section className="space-y-5 text-center">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-green-700"><Check className="h-7 w-7" /></div>
              <div>
                <h2 id="onboarding-title" className="text-xl font-bold text-zinc-900">Halaman kamu sudah online!</h2>
                <p className="mt-1 text-sm text-zinc-600">Bagikan link ini ke pembeli atau pasang di bio.</p>
              </div>
              <p className="break-all rounded-xl bg-zinc-100 px-3 py-3 text-base font-semibold text-zinc-900">{publicUrl}</p>
              <div className="grid grid-cols-2 gap-2">
                <button type="button" onClick={() => void copy()} className={cn('flex min-h-12 items-center justify-center gap-2 rounded-xl border text-sm font-semibold', copied ? 'border-green-600 bg-green-600 text-white' : 'border-zinc-200 text-zinc-800')}>
                  {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} {copied ? 'Tersalin' : 'Salin link'}
                </button>
                <a href={`https://wa.me/?text=${encodeURIComponent(`Cek toko aku: ${publicUrl}`)}`} target="_blank" rel="noopener noreferrer" className="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 text-sm font-semibold text-zinc-800">
                  <MessageCircle className="h-4 w-4" /> WhatsApp
                </a>
                <button type="button" onClick={() => void share()} className="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 text-sm font-semibold text-zinc-800">
                  <Share2 className="h-4 w-4" /> Bagikan
                </button>
                <a href={publicUrl} target="_blank" rel="noopener noreferrer" className="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-zinc-200 text-sm font-semibold text-zinc-800">
                  <ExternalLink className="h-4 w-4" /> Lihat halaman
                </a>
              </div>
              <p className="text-xs text-zinc-500">Langkah berikutnya: verifikasi email dan data rekening supaya hasil penjualan bisa ditarik.</p>
            </section>
          )}

          {error && <p role="alert" className="mt-4 rounded-xl border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
        </div>

        <footer className="border-t border-zinc-100 px-4 pb-[max(12px,env(safe-area-inset-bottom))] pt-3 sm:px-6">
          <button type="button" onClick={primary.action} disabled={busy}
            className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-zinc-900 px-4 text-base font-bold text-white hover:bg-zinc-800 disabled:opacity-60">
            {busy && <Loader2 className="h-4 w-4 animate-spin" />} {busy ? 'Menyimpan…' : primary.label}
          </button>
        </footer>
      </div>
    </div>
  );
}

function TemplateOption({ selected, onSelect, name, description, colors, suggested = false }: { selected: boolean; onSelect: () => void; name: string; description: string; colors: string[]; suggested?: boolean }) {
  return (
    <button type="button" role="radio" aria-checked={selected} onClick={onSelect}
      className={cn('flex min-h-16 w-full items-center gap-3 rounded-2xl border p-3 text-left', selected ? 'border-zinc-900 ring-1 ring-zinc-900' : 'border-zinc-200 hover:border-zinc-300')}>
      <span className="flex shrink-0 overflow-hidden rounded-lg border border-zinc-200" aria-hidden="true">
        {colors.map((c, i) => <span key={i} className="h-10 w-4" style={{ background: c }} />)}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-bold text-zinc-900">
          {name}
          {suggested && <span className="ml-2 rounded-full bg-yellow-100 px-2 py-0.5 text-[11px] font-semibold text-yellow-800">Cocok untukmu</span>}
        </span>
        <span className="block text-xs text-zinc-500">{description}</span>
      </span>
      <span className={cn('flex h-5 w-5 shrink-0 items-center justify-center rounded-full border', selected ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-300')}>
        {selected && <Check className="h-3 w-3" />}
      </span>
    </button>
  );
}
