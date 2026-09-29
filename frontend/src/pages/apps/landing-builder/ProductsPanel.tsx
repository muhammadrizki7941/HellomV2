import { useCallback, useEffect, useState } from 'react';
import { Check, Copy, Eye, EyeOff, Package, Pencil, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { deleteSellerProduct, getSellerProducts, toggleSellerProduct } from '@/lib/hellomApi';
import type { ProductLimits, SellerProduct } from '@/lib/hellomApi';
import ProductForm from './ProductForm';

// Seller products (Produk tab). Each product has its own checkout link (/beli/{id}) that
// can be shared directly or placed on the landing page with a product block.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;

export default function ProductsPanel() {
  const [items, setItems] = useState<SellerProduct[] | null>(null);
  const [limits, setLimits] = useState<ProductLimits | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<SellerProduct | 'new' | null>(null);
  const [copied, setCopied] = useState<number | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    getSellerProducts()
      .then((r) => { setItems(r.items); setLimits(r.limits); setError(null); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Produk belum bisa dimuat'));
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    if (!toast) return undefined;
    const t = window.setTimeout(() => setToast(null), 3500);
    return () => window.clearTimeout(t);
  }, [toast]);

  const copy = async (p: SellerProduct) => {
    try {
      await navigator.clipboard.writeText(p.checkout_url);
    } catch {
      window.prompt('Salin link checkout:', p.checkout_url);
    }
    setCopied(p.db_id);
    window.setTimeout(() => setCopied(null), 2000);
  };

  const toggle = async (p: SellerProduct) => {
    try {
      const updated = await toggleSellerProduct(p.db_id, !p.is_active);
      setItems((list) => list?.map((x) => (x.db_id === p.db_id ? updated : x)) ?? null);
      setToast(updated.is_active ? 'Produk dijual lagi' : 'Produk disembunyikan');
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Gagal mengubah produk');
    }
  };

  const remove = async (p: SellerProduct) => {
    if (!window.confirm(`Hapus "${p.name}"? Pembeli lama tetap bisa membuka produknya.`)) return;
    try {
      await deleteSellerProduct(p.db_id);
      setItems((list) => list?.filter((x) => x.db_id !== p.db_id) ?? null);
      setToast('Produk dihapus');
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Gagal menghapus');
    }
  };

  const badge = (p: SellerProduct): [string, string] => {
    if (p.admin_disabled) return ['Dinonaktifkan Hellom', 'bg-rose-100 text-rose-800'];
    if (!p.deliverable) return ['Belum lengkap', 'bg-amber-100 text-amber-800'];
    if (!p.is_active) return ['Disembunyikan', 'bg-zinc-100 text-zinc-600'];
    if (!p.in_stock) return ['Stok habis', 'bg-amber-100 text-amber-800'];
    return ['Dijual', 'bg-emerald-100 text-emerald-800'];
  };

  return (
    <div className="mx-auto max-w-5xl space-y-5 pb-24 lg:pb-0">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Produk</h1>
          <p className="text-sm text-zinc-600">Semua yang kamu jual. Setiap produk punya link checkout sendiri.</p>
        </div>
        <button type="button" onClick={() => setEditing('new')} className="hidden min-h-11 items-center gap-2 rounded-xl bg-zinc-900 px-4 text-sm font-bold text-white md:flex">
          <Plus className="h-4 w-4" /> Tambah produk
        </button>
      </div>

      {error && <p className="rounded-xl border border-rose-100 bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}

      {items === null && !error && (
        <div className="grid gap-3 md:grid-cols-2" aria-busy="true">{[0, 1, 2, 3].map((i) => <div key={i} className="h-28 animate-pulse rounded-2xl bg-zinc-100" />)}</div>
      )}

      {items?.length === 0 && (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center">
          <Package className="mx-auto h-10 w-10 text-zinc-300" />
          <p className="mt-3 font-semibold">Belum ada produk</p>
          <p className="mt-1 text-sm text-zinc-500">Mulai dari yang paling gampang: e-book atau template lewat link Google Drive.</p>
          <button type="button" onClick={() => setEditing('new')} className="mt-4 inline-flex min-h-12 items-center gap-2 rounded-2xl bg-zinc-900 px-5 font-bold text-white"><Plus className="h-4 w-4" /> Tambah produk pertama</button>
        </div>
      )}

      <div className="grid gap-3 md:grid-cols-2">
        {items?.map((p) => {
          const [label, tone] = badge(p);
          return (
            <article key={p.db_id} className="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm">
              <div className="flex gap-3">
                {p.image_url ? <img src={p.image_url} alt="" className="h-20 w-20 shrink-0 rounded-xl object-cover" /> : <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl bg-zinc-100"><Package className="h-7 w-7 text-zinc-400" /></div>}
                <div className="min-w-0 flex-1">
                  <span className={cn('inline-block rounded-full px-2 py-0.5 text-xs font-semibold', tone)}>{label}</span>
                  <h3 className="mt-1 truncate font-semibold text-zinc-900">{p.name}</h3>
                  <p className="text-sm font-bold">{rupiah(p.price)}{p.compare_at_price && <span className="ml-2 text-xs font-normal text-zinc-400 line-through">{rupiah(p.compare_at_price)}</span>}</p>
                  <p className="text-xs text-zinc-500">{p.type_label} · terjual {p.sold_count}{p.stock !== null ? ` · stok ${p.stock}` : ''}</p>
                </div>
              </div>
              <div className="mt-3 grid grid-cols-4 gap-1 border-t border-zinc-100 pt-2">
                <button type="button" onClick={() => void copy(p)} className="flex min-h-11 flex-col items-center justify-center text-xs text-zinc-600">
                  {copied === p.db_id ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}{copied === p.db_id ? 'Tersalin' : 'Link'}
                </button>
                <button type="button" onClick={() => setEditing(p)} className="flex min-h-11 flex-col items-center justify-center text-xs text-zinc-600"><Pencil className="h-4 w-4" />Edit</button>
                <button type="button" onClick={() => void toggle(p)} className="flex min-h-11 flex-col items-center justify-center text-xs text-zinc-600">
                  {p.is_active ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}{p.is_active ? 'Sembunyi' : 'Jual'}
                </button>
                <button type="button" onClick={() => void remove(p)} className="flex min-h-11 flex-col items-center justify-center text-xs text-rose-600"><Trash2 className="h-4 w-4" />Hapus</button>
              </div>
            </article>
          );
        })}
      </div>

      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 p-3 backdrop-blur md:hidden" style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}>
        <button type="button" onClick={() => setEditing('new')} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 text-base font-bold text-white"><Plus className="h-5 w-5" /> Tambah produk</button>
      </div>

      {editing && (
        <ProductForm
          product={editing === 'new' ? null : editing}
          limits={limits}
          onClose={() => setEditing(null)}
          onSaved={(saved, message) => {
            setItems((list) => (list?.some((x) => x.db_id === saved.db_id) ? list.map((x) => (x.db_id === saved.db_id ? saved : x)) : [saved, ...(list ?? [])]));
            setEditing(saved);
            setToast(message);
            if (message !== 'File dihapus') setEditing(null);
          }}
        />
      )}

      {toast && <div role="status" className="fixed inset-x-4 bottom-24 z-[70] mx-auto max-w-sm rounded-2xl bg-zinc-900 px-4 py-3 text-center text-sm text-white shadow-lg md:bottom-6">{toast}</div>}
    </div>
  );
}
