import { useCallback, useEffect, useMemo, useState } from 'react';
import { Download, Plus, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getHppProducts, importHppPosProducts } from '@/lib/hellomApi';
import type { HppProductSummary, HppSettings } from '@/lib/hellomApi';
import ProductEditor from './ProductEditor';
import { FoodCostBadge, MarginText, PrimaryButton, errorsFrom, rupiah } from './shared';

// Products and their HPP. Sub-recipes (sirup, saus…) are listed separately: they are not sold
// on their own, they are used inside other recipes.

export default function ProductsTab({ settings, overheadTotal, onToast, onChanged }: { settings: HppSettings; overheadTotal: number; onToast: (m: string) => void; onChanged: () => void }) {
  const [products, setProducts] = useState<HppProductSummary[] | null>(null);
  const [query, setQuery] = useState('');
  const [editing, setEditing] = useState<number | 'new' | 'new-sub' | null>(null);
  const [importing, setImporting] = useState(false);

  const load = useCallback(() => {
    getHppProducts().then((r) => setProducts(r.products)).catch(() => setProducts([]));
  }, []);
  useEffect(load, [load]);

  const shown = useMemo(() => (products ?? []).filter((p) => p.name.toLowerCase().includes(query.toLowerCase())), [products, query]);
  const sellable = shown.filter((p) => !p.is_subrecipe);
  const subs = shown.filter((p) => p.is_subrecipe);
  const showSubrecipes = settings.mode !== 'quick';

  const importPos = async () => {
    setImporting(true);
    try {
      const r = await importHppPosProducts();
      onToast(r.created ? `${r.created} menu POS diimpor. Lengkapi resepnya.` : 'Semua menu POS sudah ada di sini.');
      load();
    } catch (err) {
      onToast(errorsFrom(err).form ?? 'Belum bisa impor menu POS.');
    } finally {
      setImporting(false);
    }
  };

  return (
    <section className="space-y-3" data-hpp-products>
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex min-h-11 flex-1 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3">
          <Search className="h-4 w-4 text-zinc-400" />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari produk" className="min-w-0 flex-1 bg-transparent text-sm outline-none" />
        </div>
        <button type="button" onClick={() => void importPos()} disabled={importing} className="flex min-h-11 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-semibold text-zinc-700 disabled:opacity-50" data-import-pos>
          <Download className="h-4 w-4" /> Impor menu POS
        </button>
        <PrimaryButton type="button" onClick={() => setEditing('new')} data-add-product><Plus className="h-4 w-4" /> Tambah produk</PrimaryButton>
      </div>

      {products === null ? <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p> : sellable.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">
          Belum ada produk. Tambah produk, atau impor dari menu POS kalau kamu memakai POS Hellom.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
          <table className="w-full min-w-[720px] text-left text-sm text-zinc-800">
            <thead className="bg-zinc-50 text-xs text-zinc-500">
              <tr>
                <th className="px-4 py-2 font-medium">Produk</th><th className="px-3 py-2 font-medium">HPP / unit</th><th className="px-3 py-2 font-medium">Harga jual</th>
                <th className="px-3 py-2 font-medium">Margin</th><th className="px-3 py-2 font-medium">Food cost</th><th className="px-3 py-2 font-medium">Saran harga</th>
              </tr>
            </thead>
            <tbody>
              {sellable.map((p) => (
                <tr key={p.id} onClick={() => setEditing(p.id)} className="cursor-pointer border-t border-zinc-100 hover:bg-zinc-50" data-product-row={p.name}>
                  <td className="px-4 py-3">
                    <p className="font-medium text-zinc-900">{p.name}</p>
                    <p className="text-xs text-zinc-500">{p.mode === 'quick' ? 'Mode Cepat' : 'Resep'}{p.pos_product_id ? ' · tertaut POS' : ''}</p>
                  </td>
                  <td className="px-3 py-3 font-semibold">{p.incomplete ? <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Lengkapi resep</span> : rupiah(p.cost.full)}</td>
                  <td className="px-3 py-3">{p.selling_price ? rupiah(p.selling_price) : <span className="text-zinc-400">belum diisi</span>}</td>
                  <td className="px-3 py-3"><MarginText value={p.margin_pct} target={p.target_margin_pct} /></td>
                  <td className="px-3 py-3"><FoodCostBadge status={p.food_cost_status} value={p.food_cost_pct} /></td>
                  <td className={cn('px-3 py-3', p.suggested_price && p.selling_price && p.suggested_price > p.selling_price ? 'font-semibold text-amber-800' : 'text-zinc-600')}>
                    {rupiah(p.suggested_price)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showSubrecipes && (
        <div className="rounded-2xl border border-zinc-200 bg-white p-4">
          <div className="flex items-center justify-between gap-2">
            <div>
              <p className="font-semibold text-zinc-900">Sub-resep</p>
              <p className="text-xs text-zinc-500">Dibuat sekali, dipakai di banyak menu. Contoh: sirup gula aren 1 liter.</p>
            </div>
            <button type="button" onClick={() => setEditing('new-sub')} className="flex min-h-10 items-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-semibold" data-add-subrecipe>
              <Plus className="h-4 w-4" /> Sub-resep
            </button>
          </div>
          {subs.length > 0 && (
            <ul className="mt-3 divide-y divide-zinc-100">
              {subs.map((p) => (
                <li key={p.id}>
                  <button type="button" onClick={() => setEditing(p.id)} className="flex w-full items-center justify-between gap-3 py-2 text-left text-sm">
                    <span className="font-medium text-zinc-900">{p.name}</span>
                    <span className="text-zinc-600">{rupiah(p.cost.direct * p.yield_qty)} / {p.yield_qty.toLocaleString('id-ID')} {p.yield_unit}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {editing !== null && (
        <ProductEditor
          productId={typeof editing === 'number' ? editing : null}
          asSubrecipe={editing === 'new-sub'}
          settings={settings}
          overheadTotal={overheadTotal}
          products={products ?? []}
          onClose={() => setEditing(null)}
          onSaved={(message) => { setEditing(null); onToast(message); load(); onChanged(); }}
        />
      )}
    </section>
  );
}
