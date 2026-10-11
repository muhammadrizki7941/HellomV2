import { useEffect, useMemo, useState } from 'react';
import { Archive, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { applyHppPrice, deleteHppProduct, getHppCosts, getHppIngredients, getHppPosProducts, getHppProduct, saveHppProduct } from '@/lib/hellomApi';
import type { HppChannel, HppIngredient, HppProductDetail, HppProductSummary, HppSettings } from '@/lib/hellomApi';
import { Dialog, Field, FoodCostBadge, MarginText, MoneyInput, PrimaryButton, errorsFrom, inputClass, rupiah, rupiahUnit, toDecimal } from './shared';
import type { FieldErrors } from './shared';
import { foodCostStatus, marginPct, suggestPrice } from './pricing';

// One product: Mode Cepat (total belanja ÷ jumlah hasil) or a recipe (bahan, kemasan, sub-resep).
// The summary on the right updates while typing; saving returns the server's numbers.

type Line = { key: string; component: string; qty: string; note?: string | null };
const newKey = () => Math.random().toString(36).slice(2);

const SHOP_PRICE_KINDS = ['cash', 'qris', 'hellom', 'custom'];

export default function ProductEditor({ productId, asSubrecipe, settings, overheadTotal, products, onClose, onSaved }: {
  productId: number | null;
  asSubrecipe: boolean;
  settings: HppSettings;
  overheadTotal: number;
  products: HppProductSummary[];
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const [loaded, setLoaded] = useState<HppProductDetail | null>(null);
  const [ingredients, setIngredients] = useState<HppIngredient[]>([]);
  const [posProducts, setPosProducts] = useState<Array<{ id: number; name: string; price: number; hpp_product_id: number | null }>>([]);
  const [name, setName] = useState('');
  const [mode, setMode] = useState<'quick' | 'recipe'>(settings.mode === 'quick' ? 'quick' : 'recipe');
  const [isSub, setIsSub] = useState(asSubrecipe);
  const [batchCost, setBatchCost] = useState(0);
  const [yieldQty, setYieldQty] = useState('1');
  const [yieldUnit, setYieldUnit] = useState(asSubrecipe ? 'ml' : 'porsi');
  const [lines, setLines] = useState<Line[]>([]);
  const [laborMinutes, setLaborMinutes] = useState('0');
  const [otherCost, setOtherCost] = useState(0);
  const [price, setPrice] = useState(0);
  const [ownMargin, setOwnMargin] = useState('');
  const [posId, setPosId] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);
  const [showExtra, setShowExtra] = useState(false);
  const [channels, setChannels] = useState<HppChannel[]>([]);
  const [channelPrices, setChannelPrices] = useState<Record<string, number>>({});

  useEffect(() => {
    getHppIngredients().then((r) => setIngredients(r.ingredients)).catch(() => undefined);
    getHppPosProducts().then((r) => setPosProducts(r.products)).catch(() => undefined);
    getHppCosts().then((r) => setChannels(r.channels.filter((c) => c.is_active))).catch(() => undefined);
    if (!productId) return;
    getHppProduct(productId).then(({ product: p }) => {
      setLoaded(p);
      setName(p.name);
      setMode(p.mode);
      setIsSub(p.is_subrecipe);
      setBatchCost(p.quick_batch_cost);
      setYieldQty(String(p.yield_qty).replace('.', ','));
      setYieldUnit(p.yield_unit);
      setLines(p.lines.map((l) => ({ key: newKey(), component: `${l.component_type}:${l.component_id}`, qty: String(l.qty).replace('.', ','), note: l.note })));
      setLaborMinutes(String(p.labor_minutes));
      setOtherCost(p.other_cost);
      setPrice(p.selling_price);
      setOwnMargin(p.own_target_margin_pct === null ? '' : String(p.own_target_margin_pct));
      setPosId(p.pos_product_id ? String(p.pos_product_id) : '');
      setShowExtra(p.labor_minutes > 0 || p.other_cost > 0);
      setChannelPrices(p.channel_prices ?? {});
    }).catch((err) => setErrors(errorsFrom(err)));
  }, [productId]);

  const subrecipes = products.filter((p) => p.is_subrecipe && p.id !== productId);
  const linkedPos = posProducts.find((p) => String(p.id) === posId);
  const effectivePrice = linkedPos ? linkedPos.price : price;
  const target = ownMargin.trim() === '' ? settings.target_margin_pct : toDecimal(ownMargin);

  // Live preview (same rules as the server engine).
  const preview = useMemo(() => {
    const y = Math.max(0.001, toDecimal(yieldQty) || 1);
    let material = 0;
    let packaging = 0;
    const lineCosts: Record<string, { unit: string; unitCost: number; cost: number }> = {};
    if (mode === 'quick') {
      material = batchCost;
    } else {
      for (const line of lines) {
        const [type, rawId] = line.component.split(':');
        const id = Number(rawId);
        const qty = toDecimal(line.qty);
        if (type === 'ingredient') {
          const ing = ingredients.find((i) => i.id === id);
          if (!ing) continue;
          const cost = ing.effective_cost * qty;
          lineCosts[line.key] = { unit: ing.use_unit, unitCost: ing.effective_cost, cost };
          if (ing.kind === 'packaging') packaging += cost; else material += cost;
        } else if (type === 'product') {
          const sub = products.find((p) => p.id === id);
          if (!sub) continue;
          const cost = sub.cost.direct * qty;
          lineCosts[line.key] = { unit: sub.yield_unit, unitCost: sub.cost.direct, cost };
          material += (sub.cost.material + sub.cost.labor + sub.cost.other) * qty;
          packaging += sub.cost.packaging * qty;
        }
      }
    }
    const labor = (toDecimal(laborMinutes) / 60) * settings.labor_rate_per_hour;
    const unit = { material: material / y, packaging: packaging / y, labor: labor / y, other: otherCost / y };
    const direct = unit.material + unit.packaging + unit.labor + unit.other;
    // Overhead, same three methods as the server (revenue % works like an extra fee on price).
    const overheadPct = settings.overhead_method === 'revenue_pct' && settings.monthly_revenue > 0 ? (overheadTotal / settings.monthly_revenue) * 100 : 0;
    const fixedOverhead = isSub ? 0 : settings.overhead_method === 'per_unit'
      ? (settings.monthly_units > 0 ? overheadTotal / settings.monthly_units : 0)
      : settings.overhead_method === 'labor_hour' && settings.monthly_labor_hours > 0
        ? (overheadTotal / settings.monthly_labor_hours) * (toDecimal(laborMinutes) / 60) / y : 0;
    const overhead = isSub ? 0 : fixedOverhead + (effectivePrice * overheadPct) / 100;
    const full = direct + overhead;
    const main = channels.find((c) => c.id === settings.main_channel_id) ?? channels.find((c) => c.kind === 'qris') ?? channels[0];
    const fc = effectivePrice > 0 ? Math.round(((unit.material + unit.packaging) / effectivePrice) * 1000) / 10 : null;
    const perChannel = channels.map((c) => {
      const own = channelPrices[String(c.id)];
      const p = own && own > 0 ? own : SHOP_PRICE_KINDS.includes(c.kind) && effectivePrice > 0 ? effectivePrice : null;
      const chOverhead = fixedOverhead + ((p ?? 0) * overheadPct) / 100;
      return { channel: c, price: p, own: !!own, margin: p ? marginPct(p, direct + chOverhead, c.fee_pct, c.fee_flat) : null,
        suggested: suggestPrice(direct + fixedOverhead, target, settings.rounding, c.fee_pct + overheadPct, c.fee_flat) };
    });
    const fee = main ? (effectivePrice * main.fee_pct) / 100 + main.fee_flat : 0;
    const contribution = effectivePrice - direct - fee;
    const bep = overheadTotal > 0 && effectivePrice > 0 && contribution > 0 ? Math.ceil(overheadTotal / contribution) : null;
    return {
      unit, direct, overhead, full, lineCosts, foodCost: fc, perChannel, main, bep,
      margin: marginPct(effectivePrice, full, main?.fee_pct ?? 0, main?.fee_flat ?? 0),
      suggested: suggestPrice(direct + fixedOverhead, target, settings.rounding, (main?.fee_pct ?? 0) + overheadPct, main?.fee_flat ?? 0),
    };
  }, [mode, batchCost, lines, ingredients, products, yieldQty, laborMinutes, otherCost, settings, effectivePrice, target, isSub, overheadTotal, channels, channelPrices]);

  const addLine = () => setLines((l) => [...l, { key: newKey(), component: '', qty: '' }]);
  const setLine = (key: string, patch: Partial<Line>) => setLines((l) => l.map((x) => (x.key === key ? { ...x, ...patch } : x)));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const items = mode === 'recipe' ? lines.filter((l) => l.component).map((l) => {
        const [type, id] = l.component.split(':');
        return { component_type: type as 'ingredient' | 'product', component_id: Number(id), qty: toDecimal(l.qty), note: l.note ?? null };
      }) : [];
      const r = await saveHppProduct({
        name, mode, is_subrecipe: isSub, pos_product_id: isSub || !posId ? null : Number(posId), selling_price: isSub ? 0 : price,
        target_margin_pct: ownMargin.trim() === '' ? null : toDecimal(ownMargin), yield_qty: toDecimal(yieldQty), yield_unit: yieldUnit,
        quick_batch_cost: batchCost, labor_minutes: Math.round(toDecimal(laborMinutes)), other_cost: otherCost, items, channel_prices: channelPrices,
      }, productId ?? undefined);
      onSaved(`"${r.product.name}" disimpan. HPP ${rupiah(r.product.cost.full)} per ${r.product.yield_unit}.`);
    } catch (err) {
      setErrors(errorsFrom(err));
    } finally {
      setBusy(false);
    }
  };

  const applySuggested = async () => {
    if (!preview.suggested) return;
    if (linkedPos && productId) {
      setBusy(true);
      try {
        await applyHppPrice(productId, preview.suggested);
        setPosProducts((list) => list.map((p) => (p.id === linkedPos.id ? { ...p, price: preview.suggested ?? p.price } : p)));
        setErrors({ notice: `Harga ${rupiah(preview.suggested)} sudah dipakai di POS.` });
      } catch (err) {
        setErrors(errorsFrom(err));
      } finally {
        setBusy(false);
      }
      return;
    }
    setPrice(preview.suggested);
  };

  const remove = async () => {
    if (!productId) return;
    setBusy(true);
    try {
      const r = await deleteHppProduct(productId);
      onSaved(r.archived ? `"${name}" diarsipkan (masih dipakai sebagai sub-resep).` : `"${name}" dihapus.`);
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };

  const lineError = (index: number) => errors[`items.${index}.component_id`] ?? errors[`items.${index}.qty`];

  return (
    <Dialog title={productId ? (isSub ? 'Ubah sub-resep' : 'Ubah produk') : isSub ? 'Sub-resep baru' : 'Produk baru'} onClose={onClose} busy={busy} wide>
      {productId && !loaded && !errors.form ? <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p> : (
        <form onSubmit={(e) => void submit(e)} noValidate className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_260px]" data-product-form>
          <div className="min-w-0 space-y-4">
            <Field label={isSub ? 'Nama sub-resep' : 'Nama produk'} error={errors.name}>
              <input name="name" value={name} onChange={(e) => setName(e.target.value)} className={inputClass(errors.name)} placeholder={isSub ? 'mis. Sirup gula aren' : 'mis. Es Kopi Susu'} autoFocus={!productId} />
            </Field>

            {!isSub && (
              <div className="grid grid-cols-2 gap-2 rounded-xl bg-zinc-100 p-1" role="radiogroup" aria-label="Cara hitung">
                {([['quick', 'Cepat', 'Total belanja ÷ hasil'], ['recipe', 'Resep', 'Bahan & takaran']] as const).map(([key, label, hint]) => (
                  <button key={key} type="button" role="radio" aria-checked={mode === key} onClick={() => setMode(key)}
                    className={cn('min-h-12 rounded-lg px-2 text-sm', mode === key ? 'bg-white font-semibold shadow-sm' : 'text-zinc-600')} data-mode={key}>
                    {label}<span className="block text-[11px] font-normal text-zinc-500">{hint}</span>
                  </button>
                ))}
              </div>
            )}

            {mode === 'quick' && !isSub ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Total belanja satu kali produksi" error={errors.quick_batch_cost} hint="Semua bahan & kemasan untuk satu batch.">
                  <MoneyInput name="quick_batch_cost" value={batchCost} onChange={setBatchCost} error={errors.quick_batch_cost} placeholder="200.000" />
                </Field>
                <Field label="Jadi berapa?" error={errors.yield_qty}>
                  <div className="flex gap-2">
                    <input name="yield_qty" inputMode="decimal" value={yieldQty} onChange={(e) => setYieldQty(e.target.value)} className={cn(inputClass(errors.yield_qty), 'w-24')} />
                    <input name="yield_unit" value={yieldUnit} onChange={(e) => setYieldUnit(e.target.value)} className={inputClass()} placeholder="porsi" />
                  </div>
                </Field>
              </div>
            ) : (
              <div className="space-y-2">
                <p className="text-sm font-medium text-zinc-700">Bahan & takaran</p>
                {lines.length === 0 && <p className="rounded-xl bg-zinc-50 px-3 py-2 text-sm text-zinc-500">Tambahkan bahan yang dipakai, termasuk kemasan (cup, tutup, sedotan).</p>}
                {lines.map((line, index) => {
                  const info = preview.lineCosts[line.key];
                  return (
                    <div key={line.key} className="rounded-xl border border-zinc-200 p-2" data-recipe-line={index}>
                      <div className="flex gap-2">
                        <select name={`items.${index}.component`} value={line.component} onChange={(e) => setLine(line.key, { component: e.target.value })}
                          className={cn(inputClass(lineError(index)), 'min-w-0 flex-1')}>
                          <option value="">Pilih bahan</option>
                          <optgroup label="Bahan baku">{ingredients.filter((i) => i.kind === 'ingredient').map((i) => <option key={i.id} value={`ingredient:${i.id}`}>{i.name}</option>)}</optgroup>
                          <optgroup label="Kemasan">{ingredients.filter((i) => i.kind === 'packaging').map((i) => <option key={i.id} value={`ingredient:${i.id}`}>{i.name}</option>)}</optgroup>
                          {subrecipes.length > 0 && <optgroup label="Sub-resep">{subrecipes.map((p) => <option key={p.id} value={`product:${p.id}`}>{p.name}</option>)}</optgroup>}
                        </select>
                        <div className={cn(inputClass(errors[`items.${index}.qty`]), 'flex w-28 shrink-0 items-center gap-1 sm:w-32')}>
                          <input name={`items.${index}.qty`} inputMode="decimal" value={line.qty} onChange={(e) => setLine(line.key, { qty: e.target.value })} placeholder="0" className="w-full min-w-0 bg-transparent outline-none" />
                          <span className="text-xs text-zinc-500">{info?.unit ?? ''}</span>
                        </div>
                        <button type="button" aria-label="Hapus baris" onClick={() => setLines((l) => l.filter((x) => x.key !== line.key))} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-zinc-400 hover:bg-zinc-100"><Trash2 className="h-4 w-4" /></button>
                      </div>
                      {info && <p className="mt-1 text-right text-xs text-zinc-500">{rupiahUnit(info.unitCost)}/{info.unit} → <strong className="text-zinc-800">{rupiah(info.cost)}</strong></p>}
                      {lineError(index) && <p role="alert" className="mt-1 text-xs text-red-600">{lineError(index)}</p>}
                    </div>
                  );
                })}
                <button type="button" onClick={addLine} className="flex min-h-10 items-center gap-1 rounded-xl border border-dashed border-zinc-300 px-3 text-sm font-semibold text-zinc-700" data-add-line>
                  <Plus className="h-4 w-4" /> Tambah bahan
                </button>
                <Field label="Resep ini menghasilkan" error={errors.yield_qty} hint={isSub ? 'Mis. 1000 ml sirup. Menu lain memakai sub-resep ini per ml.' : 'Biasanya 1 porsi/cup. Untuk produksi batch, isi jumlah hasil satu batch.'}>
                  <div className="flex gap-2">
                    <input name="yield_qty" inputMode="decimal" value={yieldQty} onChange={(e) => setYieldQty(e.target.value)} className={cn(inputClass(errors.yield_qty), 'w-28')} />
                    <input name="yield_unit" value={yieldUnit} onChange={(e) => setYieldUnit(e.target.value)} className={inputClass()} />
                  </div>
                </Field>
              </div>
            )}

            {settings.mode !== 'quick' && (
              <div>
                <button type="button" onClick={() => setShowExtra(!showExtra)} className="text-sm font-medium text-zinc-600 underline-offset-2 hover:underline">
                  {showExtra ? 'Sembunyikan' : 'Tambah'} biaya tenaga kerja / ongkir
                </button>
                {showExtra && (
                  <div className="mt-2 grid gap-3 sm:grid-cols-2">
                    <Field label="Waktu kerja per batch (menit)" error={errors.labor_minutes} hint={settings.labor_rate_per_hour ? `Tarif ${rupiah(settings.labor_rate_per_hour)}/jam` : 'Isi tarif per jam di Pengaturan.'}>
                      <input name="labor_minutes" inputMode="numeric" value={laborMinutes} onChange={(e) => setLaborMinutes(e.target.value)} className={inputClass(errors.labor_minutes)} />
                    </Field>
                    <Field label="Biaya lain per batch" error={errors.other_cost} hint="Ongkir belanja, biaya titip, dll.">
                      <MoneyInput name="other_cost" value={otherCost} onChange={setOtherCost} error={errors.other_cost} />
                    </Field>
                  </div>
                )}
              </div>
            )}

            {!isSub && (
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Menu POS (opsional)" error={errors.pos_product_id} hint={posProducts.length ? 'Harga jual ikut menu POS, dan stok bahan bisa dipotong otomatis.' : 'Belum ada menu POS.'}>
                  <select name="pos_product_id" value={posId} onChange={(e) => setPosId(e.target.value)} className={inputClass(errors.pos_product_id)} disabled={!posProducts.length}>
                    <option value="">Tidak ditautkan</option>
                    {posProducts.filter((p) => !p.hpp_product_id || p.hpp_product_id === productId || String(p.id) === posId).map((p) => <option key={p.id} value={p.id}>{p.name} · {rupiah(p.price)}</option>)}
                  </select>
                </Field>
                <Field label="Harga jual" error={errors.selling_price} hint={linkedPos ? 'Diambil dari menu POS.' : undefined}>
                  {linkedPos ? <p className="flex min-h-11 items-center font-semibold text-zinc-900">{rupiah(linkedPos.price)}</p>
                    : <MoneyInput name="selling_price" value={price} onChange={setPrice} error={errors.selling_price} />}
                </Field>
                <Field label="Target margin produk ini (%)" error={errors.target_margin_pct} hint={`Kosongkan untuk memakai target toko (${settings.target_margin_pct}%).`}>
                  <input name="target_margin_pct" inputMode="decimal" value={ownMargin} onChange={(e) => setOwnMargin(e.target.value)} className={inputClass(errors.target_margin_pct)} placeholder={String(settings.target_margin_pct)} />
                </Field>
              </div>
            )}
            {!isSub && preview.perChannel.length > 0 && (
              <div className="overflow-x-auto rounded-xl border border-zinc-200" data-channel-prices>
                <table className="w-full min-w-[460px] text-left text-sm text-zinc-800">
                  <thead className="bg-zinc-50 text-xs text-zinc-500">
                    <tr><th className="px-3 py-2 font-medium">Channel</th><th className="px-3 py-2 font-medium">Harga di channel</th><th className="px-3 py-2 font-medium">Margin</th><th className="px-3 py-2 font-medium">Saran</th></tr>
                  </thead>
                  <tbody>
                    {preview.perChannel.map((row) => (
                      <tr key={row.channel.id} className="border-t border-zinc-100" data-channel={row.channel.name}>
                        <td className="px-3 py-2">{row.channel.name}<span className="block text-xs text-zinc-500">fee {row.channel.fee_pct.toLocaleString('id-ID')}%</span></td>
                        <td className="px-3 py-2">
                          <input name={`channel_prices.${row.channel.id}`} inputMode="numeric" aria-label={`Harga di ${row.channel.name}`}
                            value={channelPrices[String(row.channel.id)] ? channelPrices[String(row.channel.id)].toLocaleString('id-ID') : ''}
                            placeholder={row.price && !row.own ? row.price.toLocaleString('id-ID') : 'harga sendiri'}
                            onChange={(e) => setChannelPrices((cp) => ({ ...cp, [String(row.channel.id)]: Number(e.target.value.replace(/\D/g, '')) || 0 }))}
                            className="min-h-10 w-28 rounded-lg border border-zinc-300 px-2 text-sm" />
                        </td>
                        <td className="px-3 py-2"><MarginText value={row.margin} target={target} /></td>
                        <td className="px-3 py-2">{rupiah(row.suggested)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
            {errors.notice && <p role="status" className="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{errors.notice}</p>}
          </div>

          <aside className="space-y-3 lg:sticky lg:top-0 lg:self-start" data-cost-summary>
            <div className="rounded-2xl bg-zinc-50 p-4 text-sm">
              <p className="text-xs font-semibold uppercase tracking-wider text-zinc-500">HPP per {yieldUnit || 'unit'}</p>
              <p className="mt-1 text-2xl font-bold text-zinc-900" data-hpp-value>{rupiah(preview.full)}</p>
              <dl className="mt-2 space-y-1 text-zinc-600">
                <div className="flex justify-between"><dt>Bahan</dt><dd>{rupiah(preview.unit.material)}</dd></div>
                <div className="flex justify-between"><dt>Kemasan</dt><dd>{rupiah(preview.unit.packaging)}</dd></div>
                {preview.unit.labor > 0 && <div className="flex justify-between"><dt>Tenaga kerja</dt><dd>{rupiah(preview.unit.labor)}</dd></div>}
                {preview.unit.other > 0 && <div className="flex justify-between"><dt>Lain-lain</dt><dd>{rupiah(preview.unit.other)}</dd></div>}
                {preview.overhead > 0 && <div className="flex justify-between border-t border-zinc-200 pt-1"><dt>HPP bahan</dt><dd>{rupiah(preview.direct)}</dd></div>}
                {preview.overhead > 0 && <div className="flex justify-between"><dt>Overhead</dt><dd>{rupiah(preview.overhead)}</dd></div>}
              </dl>
              {loaded && loaded.trend.length > 1 && <Trend points={loaded.trend} />}
            </div>
            {!isSub && (
              <div className="rounded-2xl border border-zinc-200 p-4 text-sm">
                <div className="flex justify-between"><span className="text-zinc-600">Margin{preview.main ? ` (${preview.main.name})` : ''}</span><MarginText value={preview.margin} target={target} /></div>
                <div className="mt-2 flex items-center justify-between gap-2"><span className="text-zinc-600">Food cost</span><FoodCostBadge status={foodCostStatus(preview.foodCost)} value={preview.foodCost} /></div>
                <div className="mt-3 border-t border-zinc-100 pt-3">
                  {preview.bep !== null && <p className="mb-2 text-xs text-zinc-600" data-bep>BEP: <strong>{preview.bep.toLocaleString('id-ID')}</strong>/bulan (±{Math.round((preview.bep / 30) * 10) / 10}/hari) supaya biaya tetap tertutup.</p>}
                  <p className="text-xs text-zinc-500">Saran harga (margin {target}%)</p>
                  <p className="text-xl font-bold text-zinc-900" data-suggested-price>{rupiah(preview.suggested)}</p>
                  {preview.suggested !== null && preview.suggested !== effectivePrice && (
                    <button type="button" onClick={() => void applySuggested()} disabled={busy || (!!linkedPos && !productId)} className="mt-2 w-full rounded-xl border border-zinc-300 py-2 text-sm font-semibold hover:bg-zinc-50 disabled:opacity-50" data-use-suggested>
                      {linkedPos ? (productId ? 'Pakai harga ini di POS' : 'Simpan dulu, lalu pakai di POS') : 'Pakai harga ini'}
                    </button>
                  )}
                </div>
              </div>
            )}
            <PrimaryButton type="submit" busy={busy} className="w-full">Simpan</PrimaryButton>
            {productId && (
              <button type="button" onClick={() => void remove()} disabled={busy} className="flex min-h-10 w-full items-center justify-center gap-1 rounded-xl text-sm text-zinc-500 hover:bg-zinc-100">
                <Archive className="h-4 w-4" /> Hapus
              </button>
            )}
          </aside>
        </form>
      )}
    </Dialog>
  );
}

/** Small HPP trend line (daily snapshots). */
function Trend({ points }: { points: Array<{ date: string; cost: number }> }) {
  const values = points.map((p) => p.cost);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || 1;
  const d = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${(i / (points.length - 1)) * 100} ${30 - ((p.cost - min) / span) * 26 - 2}`).join(' ');
  const change = values[0] > 0 ? ((values[values.length - 1] - values[0]) / values[0]) * 100 : 0;
  return (
    <div className="mt-3 border-t border-zinc-200 pt-2" data-trend>
      <svg viewBox="0 0 100 30" className="h-10 w-full" preserveAspectRatio="none" aria-hidden="true">
        <path d={d} fill="none" stroke="currentColor" strokeWidth="2" vectorEffect="non-scaling-stroke" className={change > 0 ? 'text-red-500' : 'text-emerald-600'} />
      </svg>
      <p className="text-xs text-zinc-500">HPP {points.length} hari terakhir: {change >= 0 ? 'naik' : 'turun'} {Math.abs(Math.round(change * 10) / 10).toLocaleString('id-ID')}%</p>
    </div>
  );
}
