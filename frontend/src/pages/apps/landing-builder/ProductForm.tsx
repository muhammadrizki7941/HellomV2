import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, CheckCircle2, FileUp, HardDrive, ImagePlus, Link2, Loader2, Package, Plus, Trash2, Wrench, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  ApiError,
  checkDriveLink,
  createSellerProduct,
  deleteSellerProductFile,
  getShopShipping,
  updateSellerProduct,
  uploadSellerProductFile,
  uploadSellerProductImage,
} from '@/lib/hellomApi';
import type { CheckoutField, ProductInput, ProductLimits, ProductType, SellerProduct, ShippingMode, ShopShipping } from '@/lib/hellomApi';
import DriveGuide from './DriveGuide';
import RichTextField from './RichTextField';

// Create / edit a Hellom Page product. Full screen on phones, save button sticks to the bottom.
const TYPES: Array<{ type: ProductType; title: string; hint: string; icon: typeof Package }> = [
  { type: 'drive', title: 'Digital via Google Drive', hint: 'E-book, template, video — paling mudah', icon: HardDrive },
  { type: 'file', title: 'Upload file', hint: 'PDF, ZIP, audio, dll. Maks 10 MB', icon: FileUp },
  { type: 'link', title: 'Link / akses', hint: 'Kelas online, grup Telegram/WA, Notion', icon: Link2 },
  { type: 'physical', title: 'Produk fisik', hint: 'Dikirim ke alamat pembeli', icon: Package },
  { type: 'service', title: 'Jasa / booking', hint: 'Pembeli isi kebutuhan saat checkout', icon: Wrench },
];

const inputClass = 'mt-1 min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none focus:border-zinc-900';
const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(/\D/g, '')));
const money = (v: number | null | undefined) => (v === null || v === undefined ? '' : v.toLocaleString('id-ID'));

type Draft = {
  type: ProductType | null;
  name: string;
  description: string;
  price: string;
  compare_at_price: string;
  stock: string;
  is_active: boolean;
  require_phone: boolean;
  delivery_url: string;
  delivery_note: string;
  access_max_opens: string;
  access_days: string;
  download_limit: string;
  shipping_mode: ShippingMode;
  shipping_fee: string;
  weight_grams: string;
  checkout_fields: Array<Pick<CheckoutField, 'label' | 'type' | 'required' | 'options'>>;
};

function toDraft(p: SellerProduct | null): Draft {
  return {
    type: p?.type ?? null,
    name: p?.name ?? '',
    description: p?.description ?? '',
    price: money(p?.price),
    compare_at_price: money(p?.compare_at_price),
    stock: p?.stock === null || p?.stock === undefined ? '' : String(p.stock),
    is_active: p?.is_active ?? true,
    require_phone: p?.require_phone ?? false,
    delivery_url: p?.delivery_url ?? '',
    delivery_note: p?.delivery_note ?? '',
    access_max_opens: p?.access_max_opens ? String(p.access_max_opens) : '',
    access_days: p?.access_days ? String(p.access_days) : '',
    download_limit: p?.download_limit ? String(p.download_limit) : '',
    shipping_mode: p?.shipping_mode ?? 'free',
    shipping_fee: money(p?.shipping_fee || null),
    weight_grams: p?.weight_grams ? String(p.weight_grams) : '',
    checkout_fields: p?.raw_checkout_fields?.map(({ label, type, required, options }) => ({ label, type, required, options })) ?? [],
  };
}

export default function ProductForm({ product, limits, onClose, onSaved }: {
  product: SellerProduct | null;
  limits: ProductLimits | null;
  onClose: () => void;
  onSaved: (product: SellerProduct, message: string) => void;
}) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(product));
  const [image, setImage] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(product?.image_url ?? null);
  const [file, setFile] = useState<File | null>(null);
  const [driveCheck, setDriveCheck] = useState<'idle' | 'checking' | 'valid' | 'invalid'>('idle');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const maxMb = limits?.max_file_mb ?? 10;

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));

  // Live Drive link check.
  useEffect(() => {
    if (draft.type !== 'drive' || draft.delivery_url.trim().length < 20) {
      setDriveCheck('idle');
      return undefined;
    }
    setDriveCheck('checking');
    const timer = window.setTimeout(() => {
      checkDriveLink(draft.delivery_url.trim()).then((r) => setDriveCheck(r.valid ? 'valid' : 'invalid')).catch(() => setDriveCheck('idle'));
    }, 400);
    return () => window.clearTimeout(timer);
  }, [draft.type, draft.delivery_url]);

  useEffect(() => () => { if (imagePreview?.startsWith('blob:')) URL.revokeObjectURL(imagePreview); }, [imagePreview]);

  const pickImage = (f: File | null) => {
    if (!f) return;
    if (f.size > 8 * 1024 * 1024) {
      setErrors((e) => ({ ...e, image: 'Gambar maksimal 8 MB.' }));
      return;
    }
    setImage(f);
    setImagePreview(URL.createObjectURL(f));
    setErrors((e) => ({ ...e, image: '' }));
  };

  const pickFile = (f: File | null) => {
    if (!f) return;
    const ext = f.name.split('.').pop()?.toLowerCase() ?? '';
    if (f.size > maxMb * 1024 * 1024) {
      setErrors((e) => ({ ...e, file: `Ukuran file maksimal ${maxMb} MB. File kamu ${(f.size / 1048576).toFixed(1)} MB — pakai Google Drive untuk file besar.` }));
      return;
    }
    if (limits && !limits.file_extensions.includes(ext)) {
      setErrors((e) => ({ ...e, file: `File .${ext} belum didukung.` }));
      return;
    }
    setFile(f);
    setErrors((e) => ({ ...e, file: '' }));
  };

  const payload = (): ProductInput => ({
    type: draft.type as ProductType,
    name: draft.name.trim(),
    description: draft.description || null,
    price: num(draft.price) ?? 0,
    compare_at_price: num(draft.compare_at_price),
    stock: num(draft.stock),
    is_active: draft.is_active,
    require_phone: draft.require_phone,
    delivery_url: draft.delivery_url.trim() || null,
    delivery_note: draft.delivery_note.trim() || null,
    access_max_opens: num(draft.access_max_opens),
    access_days: num(draft.access_days),
    download_limit: num(draft.download_limit),
    shipping_mode: draft.type === 'physical' ? draft.shipping_mode : null,
    shipping_fee: draft.type === 'physical' && draft.shipping_mode === 'flat' ? num(draft.shipping_fee) : null,
    weight_grams: num(draft.weight_grams),
    checkout_fields: draft.checkout_fields.filter((f) => f.label.trim() !== ''),
  });

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (draft.name.trim().length < 3) found.name = 'Nama produk minimal 3 huruf.';
    if ((num(draft.price) ?? 0) < 10000) found.price = 'Harga minimal Rp10.000.';
    if (draft.type === 'drive' && driveCheck === 'invalid') found.delivery_url = 'Link harus link Google Drive/Docs.';
    if (draft.type === 'file' && !file && !product?.file_name) found.file = 'Pilih file produk.';
    setErrors(found);
    setFormError(null);
    if (Object.keys(found).some((k) => found[k])) {
      window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(), 0);
      return;
    }
    setSaving(true);
    try {
      let saved = product ? await updateSellerProduct(product.db_id, payload()) : await createSellerProduct(payload());
      if (image) {
        const form = new FormData();
        form.append('image', image);
        saved = await uploadSellerProductImage(saved.db_id, form);
      }
      if (file) {
        const form = new FormData();
        form.append('file', file);
        saved = await uploadSellerProductFile(saved.db_id, form);
      }
      onSaved(saved, product ? 'Produk disimpan' : 'Produk dibuat. Salin link checkout atau pasang di halaman kamu.');
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) {
        setErrors(Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k, v[0]])));
      }
      setFormError(err instanceof Error ? err.message : 'Produk belum tersimpan');
    } finally {
      setSaving(false);
    }
  };

  const removeFile = async () => {
    if (!product) {
      setFile(null);
      return;
    }
    if (!window.confirm('Hapus file produk? Pembeli tidak bisa mengunduh sampai kamu upload file baru.')) return;
    const saved = await deleteSellerProductFile(product.db_id);
    onSaved(saved, 'File dihapus');
  };

  const err = (key: string) => (errors[key] ? <p className="mt-1 text-sm text-rose-600">{errors[key]}</p> : null);
  const invalid = (key: string) => (errors[key] ? true : undefined);

  // Step 1 for new products: choose the type.
  if (!draft.type) {
    return (
      <Sheet title="Tambah produk" onClose={onClose}>
        <p className="text-sm text-zinc-500">Kamu jual apa?</p>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          {TYPES.map(({ type, title, hint, icon: Icon }) => (
            <button key={type} type="button" onClick={() => set('type', type)} className="flex min-h-16 items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-left hover:border-zinc-900">
              <Icon className="h-6 w-6 shrink-0 text-zinc-700" />
              <span><span className="block font-semibold">{title}</span><span className="block text-xs text-zinc-500">{hint}</span></span>
            </button>
          ))}
        </div>
      </Sheet>
    );
  }

  const typeInfo = TYPES.find((t) => t.type === draft.type)!;
  const digital = draft.type === 'drive' || draft.type === 'file' || draft.type === 'link';

  return (
    <Sheet title={product ? 'Edit produk' : typeInfo.title} onClose={onClose} onBack={product ? undefined : () => set('type', null)}>
      <form ref={formRef} onSubmit={save} noValidate className="space-y-5 pb-24">
        {product?.admin_disabled && (
          <p className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">Produk dinonaktifkan tim Hellom{product.admin_disabled_reason ? `: ${product.admin_disabled_reason}` : ''}.</p>
        )}

        {/* Image */}
        <div className="flex items-center gap-4">
          <label className="flex h-24 w-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-2xl border border-dashed border-zinc-300 bg-zinc-50">
            {imagePreview ? <img src={imagePreview} alt="" className="h-full w-full object-cover" /> : <ImagePlus className="h-7 w-7 text-zinc-400" />}
            <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => pickImage(e.target.files?.[0] ?? null)} />
          </label>
          <div className="text-sm text-zinc-500">
            <p className="font-medium text-zinc-800">Gambar produk</p>
            <p>JPG/PNG/WebP, otomatis dikompres. Rasio 1:1 paling pas.</p>
            {err('image')}
          </div>
        </div>

        <label className="block text-sm font-medium">
          Nama produk
          <input value={draft.name} onChange={(e) => set('name', e.target.value)} maxLength={200} aria-invalid={invalid('name')} className={inputClass} placeholder="Contoh: E-book 30 Resep Jualan Laris" />
          {err('name')}
        </label>

        <div className="grid grid-cols-2 gap-3">
          <label className="block text-sm font-medium">
            Harga (Rp)
            <input inputMode="numeric" value={draft.price} onChange={(e) => set('price', money(num(e.target.value)))} aria-invalid={invalid('price')} className={inputClass} placeholder="49.000" />
            {err('price')}
          </label>
          <label className="block text-sm font-medium">
            Harga coret <span className="font-normal text-zinc-500">(opsional)</span>
            <input inputMode="numeric" value={draft.compare_at_price} onChange={(e) => set('compare_at_price', money(num(e.target.value)))} aria-invalid={invalid('compare_at_price')} className={inputClass} placeholder="99.000" />
            {err('compare_at_price')}
          </label>
        </div>

        <div className="text-sm font-medium">
          Deskripsi
          <div className="mt-1"><RichTextField value={draft.description} onChange={(html) => set('description', html)} placeholder="Jelaskan manfaat produk, isi, dan untuk siapa." /></div>
        </div>

        {/* Delivery */}
        {draft.type === 'drive' && (
          <section className="space-y-3">
            <label className="block text-sm font-medium">
              Link Google Drive
              <input type="url" inputMode="url" value={draft.delivery_url} onChange={(e) => set('delivery_url', e.target.value)} aria-invalid={invalid('delivery_url') ?? (driveCheck === 'invalid' ? true : undefined)} className={inputClass} placeholder="https://drive.google.com/…" />
              {driveCheck === 'checking' && <p className="mt-1 text-sm text-zinc-500">Mengecek link…</p>}
              {driveCheck === 'valid' && <p className="mt-1 flex items-center gap-1 text-sm text-emerald-700"><CheckCircle2 className="h-4 w-4" /> Link Google Drive valid</p>}
              {driveCheck === 'invalid' && <p className="mt-1 text-sm text-rose-600">Ini bukan link Google Drive/Docs.</p>}
              {err('delivery_url')}
            </label>
            <DriveGuide />
          </section>
        )}
        {draft.type === 'link' && (
          <label className="block text-sm font-medium">
            Link akses
            <input type="url" inputMode="url" value={draft.delivery_url} onChange={(e) => set('delivery_url', e.target.value)} aria-invalid={invalid('delivery_url')} className={inputClass} placeholder="https://t.me/+… atau https://notion.so/…" />
            <span className="mt-1 block text-xs font-normal text-zinc-500">Tidak pernah tampil di halaman publik; pembeli membukanya dari halaman akses setelah bayar.</span>
            {err('delivery_url')}
          </label>
        )}
        {draft.type === 'file' && (
          <section className="text-sm">
            <p className="font-medium">File produk <span className="font-normal text-zinc-500">(maks {maxMb} MB)</span></p>
            {(file || product?.file_name) ? (
              <div className="mt-1 flex min-h-12 items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3">
                <FileUp className="h-4 w-4 text-zinc-500" />
                <span className="flex-1 truncate">{file ? file.name : product?.file_name}</span>
                <button type="button" onClick={() => void removeFile()} aria-label="Hapus file" className="flex h-11 w-11 items-center justify-center text-zinc-500"><Trash2 className="h-4 w-4" /></button>
              </div>
            ) : null}
            <label className="mt-2 flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 font-semibold text-zinc-700">
              <FileUp className="h-4 w-4" /> {file || product?.file_name ? 'Ganti file' : 'Pilih file'}
              <input type="file" className="sr-only" onChange={(e) => pickFile(e.target.files?.[0] ?? null)} />
            </label>
            <p className="mt-1 text-xs text-zinc-500">Disimpan privat. Pembeli mengunduh lewat link sementara setelah bayar.</p>
            {err('file')}
          </section>
        )}

        {digital && (
          <section className="space-y-3 rounded-2xl bg-zinc-50 p-4">
            <p className="text-sm font-semibold">Batas akses <span className="font-normal text-zinc-500">(kosongkan = tidak terbatas)</span></p>
            <div className="grid grid-cols-2 gap-3">
              {draft.type === 'file' ? (
                <label className="block text-sm font-medium">Maks unduh<input inputMode="numeric" value={draft.download_limit} onChange={(e) => set('download_limit', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="∞" /></label>
              ) : (
                <label className="block text-sm font-medium">Maks buka<input inputMode="numeric" value={draft.access_max_opens} onChange={(e) => set('access_max_opens', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="∞" /></label>
              )}
              <label className="block text-sm font-medium">Berlaku (hari)<input inputMode="numeric" value={draft.access_days} onChange={(e) => set('access_days', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="∞" /></label>
            </div>
          </section>
        )}

        {(digital || draft.type === 'service') && (
          <label className="block text-sm font-medium">
            Catatan untuk pembeli <span className="font-normal text-zinc-500">(tampil setelah bayar)</span>
            <textarea rows={3} value={draft.delivery_note} onChange={(e) => set('delivery_note', e.target.value)} maxLength={2000} className={cn(inputClass, 'py-3')} placeholder="Contoh: password file: JUALAN2026. Gabung grup dalam 7 hari." />
          </label>
        )}

        {draft.type === 'physical' && (
          <section className="space-y-3 rounded-2xl bg-zinc-50 p-4">
            <p className="text-sm font-semibold">Pengiriman</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Cara hitung ongkir">
              {([['courier', 'Ongkir otomatis'], ['free', 'Gratis ongkir'], ['flat', 'Ongkir tetap'], ['manual', 'Diatur manual']] as const).map(([mode, label]) => (
                <button key={mode} type="button" role="radio" aria-checked={draft.shipping_mode === mode} onClick={() => set('shipping_mode', mode)}
                  className={cn('min-h-12 rounded-xl border px-2 text-sm font-semibold', draft.shipping_mode === mode ? 'border-zinc-900 bg-white' : 'border-zinc-200 text-zinc-600')}>
                  {label}
                </button>
              ))}
            </div>
            {draft.shipping_mode === 'flat' && (
              <label className="block text-sm font-medium">Ongkir (Rp)<input inputMode="numeric" value={draft.shipping_fee} onChange={(e) => set('shipping_fee', money(num(e.target.value)))} aria-invalid={invalid('shipping_fee')} className={inputClass} />{err('shipping_fee')}</label>
            )}
            {draft.shipping_mode === 'manual' && <p className="text-xs text-zinc-500">Pembeli diberi tahu ongkir akan dikonfirmasi setelah pesanan masuk.</p>}
            {draft.shipping_mode === 'courier' && <CourierShippingNote />}
            {err('shipping_mode')}
            <label className="block text-sm font-medium">Berat per barang (gram) <span className="font-normal text-zinc-500">{draft.shipping_mode === 'courier' ? '(wajib, termasuk kemasan)' : '(opsional)'}</span><input inputMode="numeric" value={draft.weight_grams} onChange={(e) => set('weight_grams', e.target.value.replace(/\D/g, ''))} aria-invalid={invalid('weight_grams')} className={inputClass} />{err('weight_grams')}</label>
          </section>
        )}

        <div className="grid grid-cols-2 gap-3">
          <label className="block text-sm font-medium">
            Stok/kuota <span className="font-normal text-zinc-500">(opsional)</span>
            <input inputMode="numeric" value={draft.stock} onChange={(e) => set('stock', e.target.value.replace(/\D/g, ''))} className={inputClass} placeholder="Tak terbatas" />
          </label>
        </div>

        {/* Extra checkout questions */}
        <section className="space-y-3">
          <div>
            <p className="text-sm font-semibold">Pertanyaan saat checkout <span className="font-normal text-zinc-500">(opsional)</span></p>
            <p className="text-xs text-zinc-500">{draft.type === 'service' ? 'Kalau kosong, pembeli ditanya "Ceritakan kebutuhan kamu".' : 'Misalnya ukuran baju, nama untuk sertifikat, atau link Instagram.'}</p>
          </div>
          {draft.checkout_fields.map((field, index) => (
            <div key={index} className="space-y-2 rounded-2xl border border-zinc-200 p-3">
              <div className="flex gap-2">
                <input value={field.label} onChange={(e) => set('checkout_fields', draft.checkout_fields.map((f, i) => (i === index ? { ...f, label: e.target.value } : f)))} placeholder="Pertanyaan" maxLength={80} className={cn(inputClass, 'mt-0')} />
                <button type="button" aria-label="Hapus pertanyaan" onClick={() => set('checkout_fields', draft.checkout_fields.filter((_, i) => i !== index))} className="flex h-12 w-12 shrink-0 items-center justify-center text-zinc-500"><X className="h-4 w-4" /></button>
              </div>
              <div className="flex flex-wrap items-center gap-3 text-sm">
                <select value={field.type} onChange={(e) => set('checkout_fields', draft.checkout_fields.map((f, i) => (i === index ? { ...f, type: e.target.value as CheckoutField['type'] } : f)))} className="min-h-11 rounded-xl border border-zinc-300 bg-white px-2">
                  <option value="text">Jawaban singkat</option>
                  <option value="textarea">Paragraf</option>
                  <option value="number">Angka</option>
                  <option value="select">Pilihan</option>
                </select>
                <label className="flex min-h-11 items-center gap-2"><input type="checkbox" checked={field.required} onChange={(e) => set('checkout_fields', draft.checkout_fields.map((f, i) => (i === index ? { ...f, required: e.target.checked } : f)))} className="h-5 w-5" /> Wajib</label>
              </div>
              {field.type === 'select' && (
                <input value={field.options.join(', ')} onChange={(e) => set('checkout_fields', draft.checkout_fields.map((f, i) => (i === index ? { ...f, options: e.target.value.split(',').map((o) => o.trim()).filter(Boolean) } : f)))} placeholder="Pilihan, pisahkan dengan koma: S, M, L" className={cn(inputClass, 'mt-0')} />
              )}
            </div>
          ))}
          {draft.checkout_fields.length < 10 && (
            <button type="button" onClick={() => set('checkout_fields', [...draft.checkout_fields, { label: '', type: 'text', required: false, options: [] }])} className="flex min-h-11 items-center gap-2 text-sm font-semibold text-zinc-700">
              <Plus className="h-4 w-4" /> Tambah pertanyaan
            </button>
          )}
        </section>

        <section className="space-y-1 rounded-2xl bg-zinc-50 p-2">
          <Toggle label="Wajib isi nomor WhatsApp" checked={draft.require_phone || draft.type === 'physical'} disabled={draft.type === 'physical'} onChange={(v) => set('require_phone', v)} />
          <Toggle label="Tampilkan & jual produk ini" checked={draft.is_active} onChange={(v) => set('is_active', v)} />
        </section>

        {formError && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{formError}</p>}

        <div className="fixed inset-x-0 bottom-0 z-[60] border-t border-zinc-200 bg-white p-3 sm:absolute" style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}>
          <button type="submit" disabled={saving} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 text-base font-bold text-white disabled:opacity-50">
            {saving && <Loader2 className="h-5 w-5 animate-spin" />} {product ? 'Simpan perubahan' : 'Simpan produk'}
          </button>
        </div>
      </form>
    </Sheet>
  );
}

function Toggle({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <label className={cn('flex min-h-12 items-center justify-between gap-3 px-2 text-sm font-medium', disabled && 'opacity-60')}>
      {label}
      <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)} className={cn('relative h-7 w-12 shrink-0 rounded-full transition', checked ? 'bg-zinc-900' : 'bg-zinc-300')}>
        <span className={cn('absolute top-1 h-5 w-5 rounded-full bg-white transition', checked ? 'left-6' : 'left-1')} />
      </button>
    </label>
  );
}

function Sheet({ title, onClose, onBack, children }: { title: string; onClose: () => void; onBack?: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/40" onClick={onClose}>
      <div className="relative flex h-full w-full max-w-xl flex-col bg-white" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label={title}>
        <header className="flex items-center gap-2 border-b border-zinc-200 px-2 py-2">
          {onBack && <button type="button" onClick={onBack} aria-label="Kembali" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><ArrowLeft className="h-5 w-5" /></button>}
          <h2 className="flex-1 px-2 text-lg font-bold">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </header>
        <div className="flex-1 overflow-y-auto p-4">{children}</div>
      </div>
    </div>
  );
}

/** Courier rates need the platform switched on and the shop's ship-from place (Pengaturan › Pengiriman). */
function CourierShippingNote() {
  const [shop, setShop] = useState<ShopShipping | null>(null);
  useEffect(() => { getShopShipping().then(setShop).catch(() => undefined); }, []);
  if (!shop) return <p className="text-xs text-zinc-500">Pembeli memilih kurir & melihat ongkir asli (JNE, J&T, SiCepat, …) saat checkout.</p>;
  if (!shop.enabled) return <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-800">Ongkir otomatis belum diaktifkan oleh tim Hellom. Pakai ongkir tetap dulu.</p>;
  if (!shop.origin) {
    return (
      <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-800">
        Atur alamat asal pengiriman dulu di <a href="?tab=pengaturan" className="font-semibold underline">Pengaturan › Pengiriman</a>, lalu simpan produk ini.
      </p>
    );
  }
  const names = shop.couriers.map((c) => shop.available_couriers.find((a) => a.code === c)?.name ?? c).join(', ');
  return <p className="text-xs text-zinc-600">Dikirim dari <strong>{shop.origin.label}</strong>. Pembeli memilih kurir ({names}) dan melihat ongkir asli saat checkout. Ongkir untuk kamu sepenuhnya.</p>;
}
