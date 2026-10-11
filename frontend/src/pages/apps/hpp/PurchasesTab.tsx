import { useCallback, useEffect, useState } from 'react';
import { Plus, ShoppingBag, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { createHppPurchase, getHppIngredients, getHppPurchases } from '@/lib/hellomApi';
import type { HppIngredient, HppPurchase } from '@/lib/hellomApi';
import { Dialog, Field, MoneyInput, PrimaryButton, errorsFrom, inputClass, pct, rupiah, rupiahUnit, toDecimal } from './shared';
import type { FieldErrors } from './shared';

// Belanja bahan: one entry updates ingredient prices (last price / weighted average / FIFO) and
// stock, then every product's HPP is recalculated.

export default function PurchasesTab({ onToast, onChanged }: { onToast: (m: string) => void; onChanged: () => void }) {
  const [data, setData] = useState<{ purchases: HppPurchase[]; outlets: Array<{ id: number; name: string }> } | null>(null);
  const [adding, setAdding] = useState(false);

  const load = useCallback(() => {
    getHppPurchases().then(setData).catch(() => setData({ purchases: [], outlets: [] }));
  }, []);
  useEffect(load, [load]);

  return (
    <section className="space-y-3" data-hpp-purchases>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-zinc-600">Catat belanja — harga bahan dan HPP semua produk ikut diperbarui otomatis.</p>
        <PrimaryButton type="button" onClick={() => setAdding(true)} data-add-purchase><Plus className="h-4 w-4" /> Catat belanja</PrimaryButton>
      </div>
      {data === null ? <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p> : data.purchases.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">
          <ShoppingBag className="mx-auto mb-2 h-8 w-8 text-zinc-300" />
          Belum ada belanja tercatat. Setiap kali belanja bahan, catat di sini supaya HPP selalu sesuai harga terbaru.
        </div>
      ) : (
        <ul className="space-y-2">
          {data.purchases.map((p) => (
            <li key={p.id} className="rounded-2xl border border-zinc-200 bg-white p-4" data-purchase-row={p.id}>
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold text-zinc-900">{new Date(p.purchased_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}{p.supplier && <span className="font-normal text-zinc-500"> · {p.supplier}</span>}</p>
                <p className="font-semibold text-zinc-900">{rupiah(p.total)}</p>
              </div>
              <ul className="mt-2 space-y-1 text-sm text-zinc-600">
                {p.items.map((i, idx) => (
                  <li key={idx} className="flex flex-wrap justify-between gap-2">
                    <span>{i.qty.toLocaleString('id-ID')} {i.unit} {i.name}</span>
                    <span>{rupiah(i.total_price)}{i.change_pct !== null && i.change_pct !== 0 && (
                      <span className={cn('ml-2 text-xs font-semibold', i.change_pct > 0 ? 'text-red-600' : 'text-emerald-700')}>{i.change_pct > 0 ? '▲' : '▼'} {pct(Math.abs(i.change_pct))}</span>
                    )}</span>
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ul>
      )}
      {adding && data && (
        <PurchaseDialog outlets={data.outlets} onClose={() => setAdding(false)} onSaved={(message) => { setAdding(false); onToast(message); load(); onChanged(); }} />
      )}
    </section>
  );
}

type Line = { key: string; ingredient: string; qty: string; total: number };
const newLine = (): Line => ({ key: Math.random().toString(36).slice(2), ingredient: '', qty: '', total: 0 });

function PurchaseDialog({ outlets, onClose, onSaved }: { outlets: Array<{ id: number; name: string }>; onClose: () => void; onSaved: (m: string) => void }) {
  const [ingredients, setIngredients] = useState<HppIngredient[]>([]);
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [supplier, setSupplier] = useState('');
  const [outlet, setOutlet] = useState(outlets.length === 1 ? String(outlets[0].id) : '');
  const [lines, setLines] = useState<Line[]>([newLine()]);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => { getHppIngredients().then((r) => setIngredients(r.ingredients)).catch(() => undefined); }, []);
  const set = (key: string, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const total = lines.reduce((s, l) => s + l.total, 0);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const items = lines.filter((l) => l.ingredient).map((l) => ({ ingredient_id: Number(l.ingredient), qty: toDecimal(l.qty), total_price: l.total }));
    setBusy(true);
    setErrors({});
    try {
      const r = await createHppPurchase({ purchased_at: date, supplier: supplier.trim() || undefined, outlet_id: outlet ? Number(outlet) : null, items });
      const up = r.changes.filter((c) => (c.change_pct ?? 0) >= 1).sort((a, b) => (b.change_pct ?? 0) - (a.change_pct ?? 0));
      onSaved(up.length ? `Belanja dicatat. Harga ${up[0].name} naik ${pct(up[0].change_pct)} — HPP produk diperbarui.` : 'Belanja dicatat. Harga bahan & HPP diperbarui.');
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };

  return (
    <Dialog title="Catat belanja bahan" onClose={onClose} busy={busy} wide>
      <form onSubmit={(e) => void submit(e)} noValidate className="space-y-3" data-purchase-form>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Tanggal" error={errors.purchased_at}><input type="date" name="purchased_at" value={date} onChange={(e) => setDate(e.target.value)} className={inputClass(errors.purchased_at)} /></Field>
          <Field label="Toko / pemasok (opsional)" error={errors.supplier}><input name="supplier" value={supplier} onChange={(e) => setSupplier(e.target.value)} className={inputClass(errors.supplier)} /></Field>
          {outlets.length > 0 && (
            <Field label="Untuk outlet" error={errors.outlet_id}>
              <select name="outlet_id" value={outlet} onChange={(e) => setOutlet(e.target.value)} className={inputClass(errors.outlet_id)}>
                <option value="">Gudang utama</option>
                {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select>
            </Field>
          )}
        </div>
        <div className="space-y-2">
          {lines.map((line, index) => {
            const ing = ingredients.find((i) => String(i.id) === line.ingredient);
            const qty = toDecimal(line.qty);
            const unitCost = ing && qty > 0 && line.total > 0 ? line.total / (qty * ing.buy_factor) : null;
            const change = ing && unitCost !== null && ing.cost_per_unit > 0 ? ((unitCost - ing.cost_per_unit) / ing.cost_per_unit) * 100 : null;
            const lineError = errors[`items.${index}.ingredient_id`] ?? errors[`items.${index}.qty`] ?? errors[`items.${index}.total_price`];
            return (
              <div key={line.key} className="rounded-xl border border-zinc-200 p-2" data-purchase-line={index}>
                <div className="grid gap-2 sm:grid-cols-[1fr_130px_160px_44px]">
                  <select name={`items.${index}.ingredient_id`} value={line.ingredient} onChange={(e) => set(line.key, { ingredient: e.target.value })} className={inputClass(errors[`items.${index}.ingredient_id`])}>
                    <option value="">Pilih bahan</option>
                    {ingredients.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                  </select>
                  <div className={cn(inputClass(errors[`items.${index}.qty`]), 'flex items-center gap-1')}>
                    <input name={`items.${index}.qty`} inputMode="decimal" value={line.qty} onChange={(e) => set(line.key, { qty: e.target.value })} placeholder="Jumlah" className="w-full min-w-0 bg-transparent outline-none" />
                    <span className="text-xs text-zinc-500">{ing?.buy_unit ?? ''}</span>
                  </div>
                  <MoneyInput name={`items.${index}.total_price`} value={line.total} onChange={(v) => set(line.key, { total: v })} placeholder="Total harga" error={errors[`items.${index}.total_price`]} />
                  <button type="button" aria-label="Hapus baris" onClick={() => setLines((ls) => (ls.length > 1 ? ls.filter((l) => l.key !== line.key) : ls))} className="flex h-11 items-center justify-center rounded-xl text-zinc-400 hover:bg-zinc-100"><Trash2 className="h-4 w-4" /></button>
                </div>
                {ing && unitCost !== null && (
                  <p className="mt-1 text-xs text-zinc-600" data-purchase-preview>
                    = {rupiahUnit(unitCost)}/{ing.use_unit}
                    {change !== null && Math.abs(change) >= 0.5 && <span className={cn('ml-1 font-semibold', change > 0 ? 'text-red-600' : 'text-emerald-700')}>({change > 0 ? 'naik' : 'turun'} {pct(Math.abs(change))} dari {rupiahUnit(ing.cost_per_unit)})</span>}
                  </p>
                )}
                {lineError && <p role="alert" className="mt-1 text-xs text-red-600">{lineError}</p>}
              </div>
            );
          })}
          <button type="button" onClick={() => setLines((ls) => [...ls, newLine()])} className="flex min-h-10 items-center gap-1 rounded-xl border border-dashed border-zinc-300 px-3 text-sm font-semibold"><Plus className="h-4 w-4" /> Tambah bahan</button>
        </div>
        {errors.items && <p role="alert" className="text-sm text-red-600">{errors.items}</p>}
        {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
        <div className="flex items-center justify-between gap-3 border-t border-zinc-100 pt-3">
          <p className="text-sm text-zinc-600">Total belanja <strong className="text-zinc-900">{rupiah(total)}</strong></p>
          <PrimaryButton type="submit" busy={busy}>Simpan belanja</PrimaryButton>
        </div>
      </form>
    </Dialog>
  );
}
