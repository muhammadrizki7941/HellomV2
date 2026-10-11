import { useCallback, useEffect, useMemo, useState } from 'react';
import { Archive, Pencil, Plus, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { deleteHppIngredient, getHppIngredients, saveHppIngredient } from '@/lib/hellomApi';
import type { HppIngredient, HppOptions } from '@/lib/hellomApi';
import { Dialog, Field, MoneyInput, PrimaryButton, errorsFrom, inputClass, rupiah, rupiahUnit, toDecimal } from './shared';
import type { FieldErrors } from './shared';

// Master bahan baku & kemasan: what you buy (1 kg Rp180.000) → cost per use unit (Rp180/gram).

export default function IngredientsTab({ options, onToast, onChanged }: { options: HppOptions; onToast: (m: string) => void; onChanged: () => void }) {
  const [items, setItems] = useState<HppIngredient[] | null>(null);
  const [query, setQuery] = useState('');
  const [editing, setEditing] = useState<HppIngredient | 'new' | null>(null);

  const load = useCallback(() => {
    getHppIngredients().then((r) => setItems(r.ingredients)).catch(() => setItems([]));
  }, []);
  useEffect(load, [load]);

  const shown = useMemo(() => (items ?? []).filter((i) => i.name.toLowerCase().includes(query.toLowerCase())), [items, query]);
  const groups: Array<[string, HppIngredient[]]> = [
    ['Bahan baku', shown.filter((i) => i.kind === 'ingredient')],
    ['Kemasan', shown.filter((i) => i.kind === 'packaging')],
  ];

  return (
    <section className="space-y-3" data-hpp-ingredients>
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex min-h-11 flex-1 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3">
          <Search className="h-4 w-4 text-zinc-400" />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari bahan" className="min-w-0 flex-1 bg-transparent text-sm outline-none" />
        </div>
        <PrimaryButton type="button" onClick={() => setEditing('new')} data-add-ingredient><Plus className="h-4 w-4" /> Tambah bahan</PrimaryButton>
      </div>
      {items === null ? <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p> : items.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">Belum ada bahan. Tambahkan bahan yang biasa kamu beli.</p>
      ) : groups.map(([title, list]) => list.length > 0 && (
        <div key={title} className="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
          <div className="bg-zinc-50 px-4 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">{title}</div>
          <ul className="divide-y divide-zinc-100">
            {list.map((i) => (
              <li key={i.id} className="flex items-center gap-3 px-4 py-3" data-ingredient-row={i.name}>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium text-zinc-900">{i.name}</p>
                  <p className="text-xs text-zinc-500">
                    {rupiah(i.buy_price)}/{i.buy_unit} · <strong className="text-zinc-700">{rupiahUnit(i.effective_cost)}/{i.use_unit}</strong>
                    {i.yield_pct < 100 && ` · yield ${i.yield_pct}%`}
                    {i.used_in ? ` · dipakai di ${i.used_in} resep` : ''}
                  </p>
                </div>
                <button type="button" onClick={() => setEditing(i)} aria-label={`Ubah ${i.name}`} className="flex h-10 w-10 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100"><Pencil className="h-4 w-4" /></button>
              </li>
            ))}
          </ul>
        </div>
      ))}
      {editing && (
        <IngredientDialog ingredient={editing === 'new' ? null : editing} options={options} onClose={() => setEditing(null)}
          onSaved={(message) => { setEditing(null); onToast(message); load(); onChanged(); }} />
      )}
    </section>
  );
}

function IngredientDialog({ ingredient, options, onClose, onSaved }: { ingredient: HppIngredient | null; options: HppOptions; onClose: () => void; onSaved: (m: string) => void }) {
  const [name, setName] = useState(ingredient?.name ?? '');
  const [kind, setKind] = useState<'ingredient' | 'packaging'>(ingredient?.kind ?? 'ingredient');
  const [buyUnit, setBuyUnit] = useState(ingredient?.buy_unit ?? 'kg');
  const [useUnit, setUseUnit] = useState(ingredient?.use_unit ?? 'g');
  const [factor, setFactor] = useState(ingredient ? String(ingredient.buy_factor).replace('.', ',') : '');
  const [price, setPrice] = useState(ingredient?.buy_price ?? 0);
  const [yieldPct, setYieldPct] = useState(String(ingredient?.yield_pct ?? 100));
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  const buyDef = options.buy_units.find((u) => u.key === buyUnit);
  const fixedFactor = buyUnit === useUnit ? 1 : buyDef?.use_unit === useUnit ? buyDef.factor : null;
  const effectiveFactor = fixedFactor ?? toDecimal(factor);
  const perUnit = effectiveFactor > 0 ? price / effectiveFactor : 0;
  const yieldValue = Math.min(100, Math.max(1, toDecimal(yieldPct) || 100));

  const changeBuyUnit = (unit: string) => {
    setBuyUnit(unit);
    const def = options.buy_units.find((u) => u.key === unit);
    if (def?.use_unit) setUseUnit(def.use_unit);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await saveHppIngredient({ name, kind, use_unit: useUnit, buy_unit: buyUnit, buy_factor: fixedFactor ? null : toDecimal(factor), buy_price: price, yield_pct: yieldValue }, ingredient?.id);
      onSaved(ingredient ? `"${name}" disimpan. HPP produk ikut dihitung ulang.` : `"${name}" ditambahkan.`);
    } catch (err) {
      setErrors(errorsFrom(err));
    } finally {
      setBusy(false);
    }
  };

  const archive = async () => {
    if (!ingredient) return;
    setBusy(true);
    try {
      const r = await deleteHppIngredient(ingredient.id);
      onSaved(r.archived ? `"${ingredient.name}" diarsipkan (masih dipakai di resep).` : `"${ingredient.name}" dihapus.`);
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };

  return (
    <Dialog title={ingredient ? 'Ubah bahan' : 'Tambah bahan'} onClose={onClose} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate className="space-y-3" data-ingredient-form>
        <Field label="Nama bahan" error={errors.name}>
          <input name="name" value={name} onChange={(e) => setName(e.target.value)} className={inputClass(errors.name)} placeholder="mis. Susu UHT" autoFocus />
        </Field>
        <div className="grid grid-cols-2 gap-2 rounded-xl bg-zinc-100 p-1">
          {(['ingredient', 'packaging'] as const).map((k) => (
            <button key={k} type="button" aria-pressed={kind === k} onClick={() => setKind(k)}
              className={cn('min-h-10 rounded-lg text-sm font-semibold', kind === k ? 'bg-white shadow-sm' : 'text-zinc-600')}>
              {k === 'ingredient' ? 'Bahan baku' : 'Kemasan'}
            </button>
          ))}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Beli per" error={errors.buy_unit}>
            <select name="buy_unit" value={buyUnit} onChange={(e) => changeBuyUnit(e.target.value)} className={inputClass(errors.buy_unit)}>
              {options.buy_units.map((u) => <option key={u.key} value={u.key}>{u.key}</option>)}
            </select>
          </Field>
          <Field label="Harga beli" error={errors.buy_price}>
            <MoneyInput name="buy_price" value={price} onChange={setPrice} error={errors.buy_price} />
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Dipakai dalam satuan" error={errors.use_unit}>
            <select name="use_unit" value={useUnit} onChange={(e) => setUseUnit(e.target.value)} className={inputClass(errors.use_unit)}>
              {options.use_units.map((u) => <option key={u.key} value={u.key}>{u.label}</option>)}
            </select>
          </Field>
          {fixedFactor ? (
            <Field label="Isi per kemasan beli"><p className="flex min-h-11 items-center text-sm text-zinc-600">1 {buyUnit} = {fixedFactor.toLocaleString('id-ID')} {useUnit}</p></Field>
          ) : (
            <Field label={`Isi 1 ${buyUnit} (${useUnit})`} error={errors.buy_factor}>
              <input name="buy_factor" inputMode="decimal" value={factor} onChange={(e) => setFactor(e.target.value)} className={inputClass(errors.buy_factor)} placeholder="mis. 50" />
            </Field>
          )}
        </div>
        <Field label="Yield / susut (%)" error={errors.yield_pct} hint="Bagian yang benar-benar terpakai. Ayam 1 kg setelah dibersihkan tinggal 800 g → 80%.">
          <input name="yield_pct" inputMode="decimal" value={yieldPct} onChange={(e) => setYieldPct(e.target.value)} className={inputClass(errors.yield_pct)} />
        </Field>
        <div className="rounded-2xl bg-zinc-50 p-3 text-sm text-zinc-700" data-unit-cost>
          {perUnit > 0 ? (
            <>Biaya per {useUnit}: <strong>{rupiahUnit(perUnit)}</strong>{yieldValue < 100 && <> → setelah susut <strong>{rupiahUnit(perUnit * 100 / yieldValue)}</strong></>}</>
          ) : 'Isi harga dan isi kemasan untuk melihat biaya per satuan pakai.'}
        </div>
        {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
        <div className="flex gap-2 pt-1">
          {ingredient && (
            <button type="button" onClick={() => void archive()} disabled={busy} className="flex min-h-11 items-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-medium text-zinc-700">
              <Archive className="h-4 w-4" /> Hapus
            </button>
          )}
          <PrimaryButton type="submit" busy={busy} className="flex-1">Simpan bahan</PrimaryButton>
        </div>
      </form>
    </Dialog>
  );
}
