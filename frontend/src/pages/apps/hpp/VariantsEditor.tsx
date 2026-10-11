import { useEffect, useState } from 'react';
import { Download, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getHppPosOptions } from '@/lib/hellomApi';
import type { HppIngredient, HppPosOption, HppProductSummary } from '@/lib/hellomApi';
import { MoneyInput, inputClass, rupiah, toDecimal } from './shared';

// Varian & modifier (Large, Extra shot, Topping): extra ingredients per ONE unit + price change.
// Linked to a POS option so the POS sale also takes the extra ingredients from stock.

export type VariantDraft = {
  key: string;
  name: string;
  kind: 'size' | 'modifier';
  price_delta: number;
  pos_option_value_id: string;
  items: Array<{ key: string; component: string; qty: string }>;
};

export const newKey = () => Math.random().toString(36).slice(2);

export default function VariantsEditor({ variants, onChange, ingredients, subrecipes, posProductId, errors }: {
  variants: VariantDraft[];
  onChange: (next: VariantDraft[]) => void;
  ingredients: HppIngredient[];
  subrecipes: HppProductSummary[];
  posProductId: number | null;
  errors: Record<string, string | undefined>;
}) {
  const [posOptions, setPosOptions] = useState<HppPosOption[]>([]);
  useEffect(() => {
    if (!posProductId) { setPosOptions([]); return; }
    getHppPosOptions(posProductId).then((r) => setPosOptions(r.options)).catch(() => setPosOptions([]));
  }, [posProductId]);

  const update = (key: string, patch: Partial<VariantDraft>) => onChange(variants.map((v) => (v.key === key ? { ...v, ...patch } : v)));
  const extraCost = (v: VariantDraft) => v.items.reduce((sum, item) => {
    const [type, rawId] = item.component.split(':');
    const qty = toDecimal(item.qty);
    if (type === 'ingredient') return sum + (ingredients.find((i) => i.id === Number(rawId))?.effective_cost ?? 0) * qty;
    if (type === 'product') return sum + (subrecipes.find((p) => p.id === Number(rawId))?.cost.direct ?? 0) * qty;
    return sum;
  }, 0);
  const importFromPos = () => {
    const existing = new Set(variants.map((v) => v.pos_option_value_id));
    const added = posOptions.flatMap((o) => o.values.filter((val) => !existing.has(String(val.id))).map((val) => ({
      key: newKey(), name: val.name, kind: /ukuran|size/i.test(o.name) ? 'size' as const : 'modifier' as const,
      price_delta: val.price_delta, pos_option_value_id: String(val.id), items: [],
    })));
    onChange([...variants, ...added]);
  };

  return (
    <div className="space-y-2" data-variants>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-zinc-700">Varian & modifier</p>
          <p className="text-xs text-zinc-500">Ukuran (regular/large), extra shot, topping — masing-masing punya tambahan HPP sendiri.</p>
        </div>
        {posOptions.length > 0 && (
          <button type="button" onClick={importFromPos} className="flex min-h-10 items-center gap-1 rounded-xl border border-zinc-300 px-3 text-xs font-semibold" data-import-variants>
            <Download className="h-4 w-4" /> Ambil dari pilihan POS
          </button>
        )}
      </div>
      {variants.map((v, index) => {
        const extra = extraCost(v);
        return (
          <div key={v.key} className="rounded-xl border border-zinc-200 p-3" data-variant={v.name || index}>
            <div className="grid gap-2 sm:grid-cols-[1fr_120px_140px_44px]">
              <input name={`variants.${index}.name`} value={v.name} onChange={(e) => update(v.key, { name: e.target.value })} placeholder="mis. Extra shot" aria-label="Nama varian" className={inputClass(errors[`variants.${index}.name`])} />
              <select value={v.kind} onChange={(e) => update(v.key, { kind: e.target.value as VariantDraft['kind'] })} aria-label="Jenis" className={inputClass()}>
                <option value="modifier">Modifier</option><option value="size">Ukuran</option>
              </select>
              <MoneyInput name={`variants.${index}.price_delta`} value={v.price_delta} onChange={(price) => update(v.key, { price_delta: price })} placeholder="+ harga" />
              <button type="button" aria-label="Hapus varian" onClick={() => onChange(variants.filter((x) => x.key !== v.key))} className="flex h-11 items-center justify-center rounded-xl text-zinc-400 hover:bg-zinc-100"><Trash2 className="h-4 w-4" /></button>
            </div>
            {posOptions.length > 0 && (
              <select value={v.pos_option_value_id} onChange={(e) => update(v.key, { pos_option_value_id: e.target.value })} aria-label="Pilihan di POS"
                className={cn(inputClass(errors[`variants.${index}.pos_option_value_id`]), 'mt-2')}>
                <option value="">Tidak ditautkan ke pilihan POS</option>
                {posOptions.map((o) => <optgroup key={o.id} label={o.name}>{o.values.map((val) => <option key={val.id} value={val.id}>{val.name}</option>)}</optgroup>)}
              </select>
            )}
            <div className="mt-2 space-y-1">
              {v.items.map((item, i) => (
                <div key={item.key} className="flex gap-2">
                  <select value={item.component} aria-label="Bahan tambahan" onChange={(e) => update(v.key, { items: v.items.map((x) => (x.key === item.key ? { ...x, component: e.target.value } : x)) })}
                    className={cn(inputClass(errors[`variants.${index}.items.${i}.component_id`]), 'min-w-0 flex-1')}>
                    <option value="">Pilih bahan</option>
                    {ingredients.map((ing) => <option key={ing.id} value={`ingredient:${ing.id}`}>{ing.name}</option>)}
                    {subrecipes.map((p) => <option key={p.id} value={`product:${p.id}`}>{p.name} (sub-resep)</option>)}
                  </select>
                  <input inputMode="decimal" value={item.qty} aria-label="Takaran tambahan" placeholder="takaran"
                    onChange={(e) => update(v.key, { items: v.items.map((x) => (x.key === item.key ? { ...x, qty: e.target.value } : x)) })}
                    className={cn(inputClass(errors[`variants.${index}.items.${i}.qty`]), 'w-24 shrink-0')} />
                  <button type="button" aria-label="Hapus bahan" onClick={() => update(v.key, { items: v.items.filter((x) => x.key !== item.key) })} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-zinc-400 hover:bg-zinc-100"><Trash2 className="h-4 w-4" /></button>
                </div>
              ))}
              <div className="flex items-center justify-between gap-2">
                <button type="button" onClick={() => update(v.key, { items: [...v.items, { key: newKey(), component: '', qty: '' }] })} className="text-xs font-semibold text-zinc-600 underline-offset-2 hover:underline">+ bahan tambahan</button>
                <span className="text-xs text-zinc-600" data-variant-extra>Tambahan HPP {rupiah(extra)}{v.price_delta ? ` · harga +${rupiah(v.price_delta)}` : ''}</span>
              </div>
            </div>
          </div>
        );
      })}
      <button type="button" onClick={() => onChange([...variants, { key: newKey(), name: '', kind: 'modifier', price_delta: 0, pos_option_value_id: '', items: [] }])}
        className="flex min-h-10 items-center gap-1 rounded-xl border border-dashed border-zinc-300 px-3 text-sm font-semibold text-zinc-700" data-add-variant>
        <Plus className="h-4 w-4" /> Tambah varian / modifier
      </button>
    </div>
  );
}
