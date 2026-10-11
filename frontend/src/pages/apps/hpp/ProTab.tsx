import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Calculator, Check, Download, FlaskConical, Lock, Store, Warehouse } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  createHppStockCount,
  downloadHppExport,
  getHppIngredients,
  getHppOutletPrices,
  getHppVariance,
  saveHppOutletPrice,
  simulateHpp,
} from '@/lib/hellomApi';
import type { HppIngredient, HppOutletPrices, HppPro, HppSimulationRow, HppVariance } from '@/lib/hellomApi';
import SubscriptionModal from '@/components/SubscriptionModal';
import { Field, MarginText, PrimaryButton, errorsFrom, inputClass, pct, rupiah, rupiahUnit, toDecimal } from './shared';

// Kalkulator HPP Pro (Tahap 4). Free for POS Kasir subscribers, or its own subscription. Locked →
// what Pro does and how to get it; open → stok opname & selisih, simulasi, harga per outlet, ekspor.

type Section = 'variance' | 'simulate' | 'outlets' | 'export';
const FEATURES = [
  'Selisih stok teoritis vs aktual — tangkap bahan bocor, boros, atau curang',
  'Simulasi "bagaimana jika" harga bahan atau biaya naik',
  'Metode harga FIFO (stok lama dipakai duluan)',
  'Harga bahan berbeda per outlet',
  'Ekspor laporan ke Excel (CSV)',
];

export default function ProTab({ pro, outlets, onToast, onChanged }: { pro: HppPro; outlets: Array<{ id: number; name: string }>; onToast: (m: string) => void; onChanged: () => void }) {
  const [section, setSection] = useState<Section>('variance');
  if (!pro.active) return <Upsell pro={pro} onChanged={onChanged} />;

  const sections: Array<[Section, string, typeof Warehouse]> = [
    ['variance', 'Stok opname & selisih', Warehouse], ['simulate', 'Simulasi', FlaskConical], ['outlets', 'Harga per outlet', Store], ['export', 'Ekspor', Download],
  ];
  return (
    <section className="space-y-4" data-hpp-pro>
      <p className="text-sm text-zinc-600">
        Pro aktif {pro.source === 'pos' ? 'gratis karena toko berlangganan POS Kasir' : 'lewat langganan Kalkulator HPP Pro'}.
      </p>
      <div className="flex gap-2 overflow-x-auto">
        {sections.map(([key, label, Icon]) => (
          <button key={key} type="button" onClick={() => setSection(key)} aria-pressed={section === key}
            className={cn('flex min-h-10 shrink-0 items-center gap-2 rounded-xl border px-3 text-sm font-semibold', section === key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-700')}>
            <Icon className="h-4 w-4" /> {label}
          </button>
        ))}
      </div>
      {section === 'variance' && <VarianceSection outlets={outlets} onToast={onToast} onChanged={onChanged} />}
      {section === 'simulate' && <SimulationSection outlets={outlets} />}
      {section === 'outlets' && <OutletPricesSection onToast={onToast} onChanged={onChanged} />}
      {section === 'export' && <ExportSection outlets={outlets} onToast={onToast} />}
    </section>
  );
}

function Upsell({ pro, onChanged }: { pro: HppPro; onChanged: () => void }) {
  const [subscribing, setSubscribing] = useState(false);
  const cheapest = pro.plans[0];
  return (
    <section className="grid gap-4 lg:grid-cols-[1fr_320px]" data-hpp-upsell>
      <div className="rounded-2xl border border-zinc-200 bg-white p-5">
        <p className="flex items-center gap-2 text-lg font-bold text-zinc-900"><Lock className="h-5 w-5" /> Kalkulator HPP Pro</p>
        <p className="mt-1 text-sm text-zinc-600">Untuk usaha multi-outlet dan pengusaha yang ingin mengawasi modal sampai ke gram.</p>
        <ul className="mt-4 space-y-2 text-sm text-zinc-800">
          {FEATURES.map((f) => <li key={f} className="flex gap-2"><Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />{f}</li>)}
        </ul>
      </div>
      <div className="space-y-3">
        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
          <p className="font-semibold text-emerald-900">Gratis untuk pelanggan POS Kasir</p>
          <p className="mt-1 text-sm text-emerald-800">Berlangganan POS Kasir, Pro langsung terbuka tanpa biaya tambahan.</p>
          <Link to="/dashboard/apps/pos" className="mt-3 flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 text-sm font-bold text-white">Lihat POS Kasir</Link>
        </div>
        <div className="rounded-2xl border border-zinc-200 bg-white p-4">
          <p className="font-semibold text-zinc-900">Atau langganan Pro saja</p>
          {cheapest ? (
            <p className="mt-1 text-sm text-zinc-600">Mulai <strong className="text-zinc-900">{rupiah(cheapest.price)}</strong>{cheapest.billing_cycles.includes('yearly') ? '/tahun' : '/bulan'}.</p>
          ) : <p className="mt-1 text-sm text-zinc-500">Paket belum tersedia.</p>}
          <PrimaryButton type="button" onClick={() => setSubscribing(true)} disabled={!cheapest} className="mt-3 w-full" data-subscribe-pro>Berlangganan Pro</PrimaryButton>
        </div>
      </div>
      <SubscriptionModal isOpen={subscribing} onClose={() => setSubscribing(false)} appName="Kalkulator HPP" appSlug="hpp" appIcon={Calculator} onSuccess={() => { setSubscribing(false); onChanged(); }} />
    </section>
  );
}

function OutletSelect({ outlets, value, onChange }: { outlets: Array<{ id: number; name: string }>; value: number | null; onChange: (v: number | null) => void }) {
  if (outlets.length === 0) return null;
  return (
    <select aria-label="Outlet" value={value ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)} className={cn(inputClass(), 'w-auto')}>
      <option value="">Gudang utama / tanpa outlet</option>
      {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
    </select>
  );
}

function VarianceSection({ outlets, onToast, onChanged }: { outlets: Array<{ id: number; name: string }>; onToast: (m: string) => void; onChanged: () => void }) {
  const [outlet, setOutlet] = useState<number | null>(outlets[0]?.id ?? null);
  const [report, setReport] = useState<HppVariance | null>(null);
  const [counting, setCounting] = useState(false);
  const load = useCallback(() => { getHppVariance(outlet).then(setReport).catch(() => setReport(null)); }, [outlet]);
  useEffect(load, [load]);

  return (
    <div className="space-y-3" data-variance>
      <div className="flex flex-wrap items-center gap-2">
        <OutletSelect outlets={outlets} value={outlet} onChange={setOutlet} />
        <PrimaryButton type="button" onClick={() => setCounting(true)} data-start-count>Catat stok opname</PrimaryButton>
      </div>
      <p className="text-sm text-zinc-600">
        Pemakaian <strong>teoritis</strong> = menurut resep dari penjualan POS. Pemakaian <strong>aktual</strong> = stok awal + pembelian − stok akhir (hasil opname).
        Selisih positif berarti bahan keluar lebih banyak dari resep: bocor, boros, porsi kebesaran, atau kecurangan.
      </p>
      {!report || !report.from ? (
        <p className="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 text-center text-sm text-zinc-500">
          Butuh dua kali stok opname untuk membandingkan. {report?.to ? 'Satu opname sudah tercatat — catat lagi di akhir periode.' : 'Catat opname pertama sekarang.'}
        </p>
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
          <p className="border-b border-zinc-100 px-4 py-3 text-sm text-zinc-600">
            {new Date(report.from.counted_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })} → {new Date(report.to!.counted_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}
            {' · '}nilai selisih <strong className={report.total_value > 0 ? 'text-red-700' : 'text-zinc-900'}>{rupiah(report.total_value)}</strong>
          </p>
          <table className="w-full min-w-[760px] text-left text-sm text-zinc-800" data-variance-table>
            <thead className="bg-zinc-50 text-xs text-zinc-500">
              <tr><th className="px-4 py-2 font-medium">Bahan</th><th className="px-3 py-2 font-medium">Awal</th><th className="px-3 py-2 font-medium">Beli</th><th className="px-3 py-2 font-medium">Akhir</th>
                <th className="px-3 py-2 font-medium">Teoritis</th><th className="px-3 py-2 font-medium">Aktual</th><th className="px-3 py-2 font-medium">Selisih</th><th className="px-3 py-2 font-medium">Nilai</th></tr>
            </thead>
            <tbody>
              {report.rows.map((r) => (
                <tr key={r.ingredient_id} className={cn('border-t border-zinc-100', r.flag && 'bg-red-50/60')} data-variance-row={r.name}>
                  <td className="px-4 py-2 font-medium">{r.flag && <AlertTriangle className="mr-1 inline h-4 w-4 text-red-600" />}{r.name}</td>
                  <td className="px-3 py-2">{r.opening.toLocaleString('id-ID')} {r.unit}</td>
                  <td className="px-3 py-2">{r.purchased.toLocaleString('id-ID')}</td>
                  <td className="px-3 py-2">{r.closing.toLocaleString('id-ID')}</td>
                  <td className="px-3 py-2">{r.theoretical.toLocaleString('id-ID')}</td>
                  <td className="px-3 py-2">{r.actual.toLocaleString('id-ID')}</td>
                  <td className={cn('px-3 py-2 font-semibold', r.variance > 0 ? 'text-red-700' : 'text-emerald-700')}>{r.variance > 0 ? '+' : ''}{r.variance.toLocaleString('id-ID')}{r.variance_pct !== null && <span className="ml-1 text-xs font-normal">({pct(r.variance_pct)})</span>}</td>
                  <td className="px-3 py-2">{rupiah(r.value)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {counting && <CountForm outletId={outlet} onClose={() => setCounting(false)} onSaved={() => { setCounting(false); onToast('Stok opname disimpan. Stok sistem disesuaikan dengan hasil hitung.'); load(); onChanged(); }} />}
    </div>
  );
}

function CountForm({ outletId, onClose, onSaved }: { outletId: number | null; onClose: () => void; onSaved: () => void }) {
  const [ingredients, setIngredients] = useState<HppIngredient[]>([]);
  const [counts, setCounts] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { getHppIngredients().then((r) => setIngredients(r.ingredients.filter((i) => i.kind === 'ingredient'))).catch(() => undefined); }, []);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    const items = Object.entries(counts).filter(([, v]) => v.trim() !== '').map(([id, v]) => ({ ingredient_id: Number(id), counted_qty: toDecimal(v) }));
    if (items.length === 0) { setError('Isi hasil hitung minimal satu bahan.'); return; }
    setBusy(true);
    try {
      await createHppStockCount({ outlet_id: outletId, items });
      onSaved();
    } catch (err) {
      setError(errorsFrom(err).form ?? 'Belum tersimpan.');
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void save(e)} noValidate className="rounded-2xl border border-zinc-900 bg-white p-4" data-count-form>
      <p className="font-semibold text-zinc-900">Hitung stok fisik sekarang</p>
      <p className="text-xs text-zinc-500">Isi jumlah yang benar-benar ada. Bahan yang tidak dihitung boleh dikosongkan.</p>
      <div className="mt-3 grid gap-2 sm:grid-cols-2">
        {ingredients.map((i) => (
          <label key={i.id} className="flex items-center justify-between gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm">
            <span className="min-w-0"><span className="block truncate font-medium">{i.name}</span><span className="text-xs text-zinc-500">sistem: {(i.stock ?? 0).toLocaleString('id-ID')} {i.use_unit}</span></span>
            <span className="flex items-center gap-1">
              <input inputMode="decimal" aria-label={`Hasil hitung ${i.name}`} data-count-input={i.name} value={counts[i.id] ?? ''} onChange={(e) => setCounts((c) => ({ ...c, [i.id]: e.target.value }))} className="min-h-10 w-24 rounded-lg border border-zinc-300 px-2" />
              <span className="text-xs text-zinc-500">{i.use_unit}</span>
            </span>
          </label>
        ))}
      </div>
      {error && <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>}
      <div className="mt-3 flex gap-2">
        <button type="button" onClick={onClose} className="min-h-11 rounded-xl border border-zinc-300 px-4 text-sm font-semibold">Batal</button>
        <PrimaryButton type="submit" busy={busy}>Simpan opname</PrimaryButton>
      </div>
    </form>
  );
}

function SimulationSection({ outlets }: { outlets: Array<{ id: number; name: string }> }) {
  const [ingredients, setIngredients] = useState<HppIngredient[]>([]);
  const [changes, setChanges] = useState<Record<number, string>>({});
  const [overheadPct, setOverheadPct] = useState('');
  const [outlet, setOutlet] = useState<number | null>(null);
  const [rows, setRows] = useState<HppSimulationRow[] | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => { getHppIngredients().then((r) => setIngredients(r.ingredients)).catch(() => undefined); }, []);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await simulateHpp({
        ingredients: Object.entries(changes).filter(([, v]) => v.trim() !== '' && toDecimal(v) !== 0).map(([id, v]) => ({ id: Number(id), pct: toDecimal(v) })),
        overhead_pct: toDecimal(overheadPct), outlet_id: outlet,
      });
      setRows(r.products);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid gap-4 lg:grid-cols-[320px_1fr]" data-simulation>
      <form onSubmit={(e) => void run(e)} noValidate className="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4">
        <p className="font-semibold text-zinc-900">Bagaimana jika…</p>
        <OutletSelect outlets={outlets} value={outlet} onChange={setOutlet} />
        <div className="max-h-72 space-y-1 overflow-y-auto pr-1">
          {ingredients.map((i) => (
            <label key={i.id} className="flex items-center justify-between gap-2 text-sm">
              <span className="truncate">{i.name}</span>
              <span className="flex items-center gap-1"><input inputMode="decimal" aria-label={`Perubahan harga ${i.name}`} data-sim-input={i.name} value={changes[i.id] ?? ''} placeholder="0"
                onChange={(e) => setChanges((c) => ({ ...c, [i.id]: e.target.value }))} className="min-h-9 w-16 rounded-lg border border-zinc-300 px-2 text-right" />%</span>
            </label>
          ))}
        </div>
        <Field label="Biaya tetap (sewa, gaji…) naik/turun"><div className="flex items-center gap-1"><input inputMode="decimal" value={overheadPct} onChange={(e) => setOverheadPct(e.target.value)} placeholder="0" className={cn(inputClass(), "w-24")} /><span>%</span></div></Field>
        <PrimaryButton type="submit" busy={busy} className="w-full" data-run-simulation>Hitung simulasi</PrimaryButton>
      </form>
      <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
        {rows === null ? <p className="p-6 text-sm text-zinc-500">Isi perubahan harga (mis. gula +20%), lalu hitung. Data asli tidak berubah.</p> : (
          <table className="w-full min-w-[620px] text-left text-sm text-zinc-800" data-simulation-table>
            <thead className="bg-zinc-50 text-xs text-zinc-500">
              <tr><th className="px-4 py-2 font-medium">Produk</th><th className="px-3 py-2 font-medium">HPP sekarang</th><th className="px-3 py-2 font-medium">HPP simulasi</th>
                <th className="px-3 py-2 font-medium">Margin</th><th className="px-3 py-2 font-medium">Saran harga</th></tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-t border-zinc-100" data-sim-row={r.name}>
                  <td className="px-4 py-2 font-medium">{r.name}</td>
                  <td className="px-3 py-2">{rupiah(r.cost_now)}</td>
                  <td className={cn('px-3 py-2 font-semibold', r.cost_sim > r.cost_now + 0.5 ? 'text-red-700' : r.cost_sim < r.cost_now - 0.5 ? 'text-emerald-700' : '')}>{rupiah(r.cost_sim)}</td>
                  <td className="px-3 py-2"><MarginText value={r.margin_now} target={r.target_margin_pct} /> → <MarginText value={r.margin_sim} target={r.target_margin_pct} /></td>
                  <td className="px-3 py-2">{rupiah(r.suggested_now)} → <strong>{rupiah(r.suggested_sim)}</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function OutletPricesSection({ onToast, onChanged }: { onToast: (m: string) => void; onChanged: () => void }) {
  const [data, setData] = useState<HppOutletPrices | null>(null);
  const [outlet, setOutlet] = useState<number | null>(null);
  const [draft, setDraft] = useState<Record<number, string>>({});
  const load = useCallback(() => {
    getHppOutletPrices(outlet).then((d) => { setData(d); setOutlet(d.outlet_id); setDraft({}); }).catch(() => setData(null));
  }, [outlet]);
  useEffect(load, [load]);

  if (!data) return <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p>;
  if (data.outlets.length === 0) return <p className="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 text-center text-sm text-zinc-500">Harga per outlet butuh lebih dari satu outlet POS.</p>;
  const save = async (ingredientId: number, value: string) => {
    if (!data.outlet_id) return;
    await saveHppOutletPrice({ outlet_id: data.outlet_id, ingredient_id: ingredientId, cost_per_unit: value.trim() === '' ? null : toDecimal(value) });
    onToast('Harga outlet disimpan. HPP outlet ini dihitung ulang.');
    load();
    onChanged();
  };

  return (
    <div className="space-y-3" data-outlet-prices>
      <OutletSelect outlets={data.outlets} value={data.outlet_id} onChange={setOutlet} />
      <div className="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
        <table className="w-full text-left text-sm text-zinc-800">
          <thead className="bg-zinc-50 text-xs text-zinc-500"><tr><th className="px-4 py-2 font-medium">Bahan</th><th className="px-3 py-2 font-medium">Harga umum</th><th className="px-3 py-2 font-medium">Harga di outlet ini</th></tr></thead>
          <tbody>
            {data.ingredients.map((i) => (
              <tr key={i.id} className="border-t border-zinc-100">
                <td className="px-4 py-2 font-medium">{i.name}</td>
                <td className="px-3 py-2">{rupiahUnit(i.cost_per_unit)}/{i.use_unit}</td>
                <td className="px-3 py-2">
                  <input inputMode="decimal" aria-label={`Harga ${i.name} di outlet`} data-outlet-price={i.name} placeholder="sama"
                    value={draft[i.id] ?? (i.outlet_cost === null ? '' : String(i.outlet_cost).replace('.', ','))}
                    onChange={(e) => setDraft((d) => ({ ...d, [i.id]: e.target.value }))}
                    onBlur={(e) => { if (draft[i.id] !== undefined) void save(i.id, e.target.value); }}
                    className="min-h-9 w-28 rounded-lg border border-zinc-300 px-2" /> <span className="text-xs text-zinc-500">/{i.use_unit}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-zinc-500">Kosongkan untuk memakai harga umum. Pilih outlet di tab Produk untuk melihat HPP per outlet. Belanja yang dicatat untuk outlet bisa otomatis mengisi harga ini (Pengaturan).</p>
    </div>
  );
}

function ExportSection({ outlets, onToast }: { outlets: Array<{ id: number; name: string }>; onToast: (m: string) => void }) {
  const [outlet, setOutlet] = useState<number | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const run = async (type: 'products' | 'variance') => {
    setBusy(type);
    try {
      await downloadHppExport(type, outlet);
    } catch (err) {
      onToast(err instanceof Error ? err.message : 'Ekspor gagal.');
    } finally {
      setBusy(null);
    }
  };
  return (
    <div className="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4" data-export>
      <OutletSelect outlets={outlets} value={outlet} onChange={setOutlet} />
      <div className="flex flex-wrap gap-2">
        <PrimaryButton type="button" busy={busy === 'products'} onClick={() => void run('products')}><Download className="h-4 w-4" /> Laporan HPP & margin</PrimaryButton>
        <PrimaryButton type="button" busy={busy === 'variance'} onClick={() => void run('variance')}><Download className="h-4 w-4" /> Selisih stok</PrimaryButton>
      </div>
      <p className="text-xs text-zinc-500">File CSV, bisa dibuka di Excel / Google Sheets.</p>
    </div>
  );
}
