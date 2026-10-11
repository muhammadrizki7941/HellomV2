import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Calculator, CheckCircle2, Coffee, Laptop, Loader2, Package, Scissors, Store } from 'lucide-react';
import { cn } from '@/lib/utils';
import { applyHppPrice, getHppOverview, onboardHpp, updateHppSettings } from '@/lib/hellomApi';
import type { HppAlert, HppMode, HppOverview, HppRounding, HppSettings } from '@/lib/hellomApi';
import CostsTab from './CostsTab';
import IngredientsTab from './IngredientsTab';
import ProductsTab from './ProductsTab';
import PurchasesTab from './PurchasesTab';
import ReportsTab from './ReportsTab';
import { FoodCostBadge, Field, MarginText, MoneyInput, PrimaryButton, errorsFrom, inputClass, rupiah, useToast } from './shared';
import type { FieldErrors } from './shared';
import { ROUNDING_LABELS } from './pricing';

// Kalkulator HPP — dashboard app "hpp" (free; Pro tier later). Principle: input once, keep counting.
// Users record purchases, recipes and monthly fixed costs; HPP, suggested prices, margin and
// warnings are calculated. Backend: App\Services\Hpp\CostEngine.

type Tab = 'overview' | 'products' | 'ingredients' | 'purchases' | 'costs' | 'reports' | 'settings';

const MODES: Array<{ key: HppMode; label: string; hint: string }> = [
  { key: 'quick', label: 'Cepat', hint: 'Pemula: total belanja ÷ jumlah hasil' },
  { key: 'recipe', label: 'Resep', hint: 'Bahan & takaran per menu, kemasan, biaya' },
];

const BUSINESS_ICONS: Record<string, typeof Coffee> = { fnb: Coffee, retail: Store, service: Scissors, production: Package, digital: Laptop };

export default function HppApp() {
  const [data, setData] = useState<HppOverview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>('overview');
  const [, toast, toastNode] = useToast();

  const load = useCallback(() => {
    getHppOverview().then(setData).catch((err) => setError(errorsFrom(err).form ?? 'Gagal memuat.'));
  }, []);
  useEffect(() => {
    document.title = 'Kalkulator HPP | Hellom';
    load();
  }, [load]);

  if (error) return <p className="p-6 text-sm text-red-700">{error}</p>;
  if (!data) return <div className="flex justify-center p-10"><Loader2 className="h-6 w-6 animate-spin text-zinc-400" /></div>;
  if (!data.settings.onboarded) return <Onboarding data={data} onDone={load} />;

  const settings = data.settings;
  const setMode = async (mode: HppMode) => {
    try {
      await updateHppSettings({ mode });
      load();
    } catch (err) {
      toast(errorsFrom(err).form ?? 'Mode belum diganti.');
    }
  };
  // Mode Cepat keeps the screen simple; Resep/Pro show purchases, costs & channels, and reports.
  const tabs: Array<[Tab, string]> = settings.mode === 'quick'
    ? [['overview', 'Ringkasan'], ['products', 'Produk'], ['ingredients', 'Bahan & kemasan'], ['settings', 'Pengaturan']]
    : [['overview', 'Ringkasan'], ['products', 'Produk'], ['ingredients', 'Bahan & kemasan'], ['purchases', 'Pembelian'], ['costs', 'Biaya & channel'], ['reports', 'Laporan'], ['settings', 'Pengaturan']];

  return (
    <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6" data-hpp-app>
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-zinc-900"><Calculator className="h-6 w-6" /> Kalkulator HPP</h1>
          <p className="text-sm text-zinc-600">Catat belanja dan resep sekali — HPP, harga jual, dan margin dihitung otomatis.</p>
        </div>
        <div role="radiogroup" aria-label="Mode" className="flex gap-1 rounded-2xl bg-zinc-100 p-1">
          {MODES.map((m) => (
            <button key={m.key} type="button" role="radio" aria-checked={settings.mode === m.key} title={m.hint} onClick={() => void setMode(m.key)}
              className={cn('min-h-10 rounded-xl px-4 text-sm font-semibold', settings.mode === m.key ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-600')} data-app-mode={m.key}>
              {m.label}
            </button>
          ))}
        </div>
      </header>

      <nav role="tablist" className="flex gap-1 overflow-x-auto border-b border-zinc-200">
        {tabs.map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setTab(key)}
            className={cn('min-h-11 whitespace-nowrap border-b-2 px-3 text-sm font-semibold', tab === key ? 'border-zinc-900 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-800')}>
            {label}
          </button>
        ))}
      </nav>

      {tab === 'overview' && <OverviewTab data={data} goTo={setTab} onToast={toast} onChanged={load} />}
      {tab === 'products' && <ProductsTab settings={settings} overheadTotal={data.overhead_total} onToast={toast} onChanged={load} />}
      {tab === 'purchases' && <PurchasesTab onToast={toast} onChanged={load} />}
      {tab === 'costs' && <CostsTab settings={settings} onToast={toast} onChanged={load} />}
      {tab === 'reports' && <ReportsTab />}
      {tab === 'ingredients' && <IngredientsTab options={data.options} onToast={toast} onChanged={load} />}
      {tab === 'settings' && <SettingsTab settings={settings} data={data} onSaved={(m) => { toast(m); load(); }} />}
      {toastNode}
    </div>
  );
}

function Onboarding({ data, onDone }: { data: HppOverview; onDone: () => void }) {
  const [type, setType] = useState(data.settings.business_type || 'fnb');
  const [mode, setMode] = useState<'quick' | 'recipe'>('quick');
  const [template, setTemplate] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const start = async () => {
    setBusy(true);
    setError(null);
    try {
      await onboardHpp({ business_type: type, mode, use_template: template });
      onDone();
    } catch (err) {
      setError(errorsFrom(err).form ?? 'Belum tersimpan.');
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-4 sm:p-6" data-hpp-onboarding>
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-zinc-900"><Calculator className="h-6 w-6" /> Kalkulator HPP</h1>
        <p className="mt-1 text-zinc-600">Hitung modal per produk dan harga jual yang aman. Gratis untuk semua toko.</p>
      </div>
      <section>
        <p className="mb-2 font-semibold text-zinc-900">Usahamu jenis apa?</p>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          {data.options.business_types.map((b) => {
            const Icon = BUSINESS_ICONS[b.key] ?? Store;
            return (
              <button key={b.key} type="button" onClick={() => setType(b.key)} aria-pressed={type === b.key} data-business={b.key}
                className={cn('flex min-h-20 flex-col items-start justify-center gap-1 rounded-2xl border p-3 text-left text-sm font-semibold', type === b.key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-800')}>
                <Icon className="h-5 w-5" /> {b.label}
              </button>
            );
          })}
        </div>
      </section>
      <section>
        <p className="mb-2 font-semibold text-zinc-900">Mulai dengan cara apa?</p>
        <div className="grid gap-2 sm:grid-cols-2">
          {([['quick', 'Cepat (1 menit)', 'Modal Rp200.000 jadi 50 porsi → HPP Rp4.000. Cocok untuk warung & pemula.'],
            ['recipe', 'Resep', 'Bahan dan takaran per menu, kemasan, tenaga kerja. Lebih akurat.']] as const).map(([key, label, hint]) => (
            <button key={key} type="button" onClick={() => setMode(key)} aria-pressed={mode === key} data-start-mode={key}
              className={cn('rounded-2xl border p-4 text-left', mode === key ? 'border-zinc-900 ring-2 ring-zinc-900' : 'border-zinc-200 bg-white')}>
              <p className="font-semibold text-zinc-900">{label}</p>
              <p className="mt-1 text-sm text-zinc-600">{hint}</p>
            </button>
          ))}
        </div>
        <p className="mt-2 text-xs text-zinc-500">Bisa pindah mode kapan saja tanpa kehilangan data.</p>
      </section>
      <label className="flex items-start gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm">
        <input type="checkbox" checked={template} onChange={(e) => setTemplate(e.target.checked)} className="mt-1 h-5 w-5" name="use_template" />
        <span><strong className="text-zinc-900">Isi daftar bahan umum</strong><span className="block text-zinc-600">Contoh harga bahan & kemasan untuk usahamu, tinggal disesuaikan dengan harga belanjamu.</span></span>
      </label>
      {error && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
      <PrimaryButton type="button" busy={busy} onClick={() => void start()} className="w-full" data-hpp-start>Mulai hitung</PrimaryButton>
    </div>
  );
}

function OverviewTab({ data, goTo, onToast, onChanged }: { data: HppOverview; goTo: (t: Tab) => void; onToast: (m: string) => void; onChanged: () => void }) {
  const c = data.counts;
  const cards: Array<[string, number, string, Tab]> = [
    ['Produk dihitung', c.products, 'text-zinc-900', 'products'],
    ['Bahan & kemasan', c.ingredients, 'text-zinc-900', 'ingredients'],
    ['Resep belum diisi', c.incomplete, c.incomplete ? 'text-amber-700' : 'text-zinc-900', 'products'],
    ['Margin di bawah target', c.below_target, c.below_target ? 'text-amber-700' : 'text-zinc-900', 'products'],
    ['Food cost merah', c.red, c.red ? 'text-red-700' : 'text-zinc-900', 'products'],
  ];
  return (
    <section className="space-y-4" data-hpp-overview>
      {data.alerts.length > 0 && <AlertsPanel alerts={data.alerts} onToast={onToast} onChanged={onChanged} />}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {cards.map(([label, value, color, target]) => (
          <button key={label} type="button" onClick={() => goTo(target)} className="rounded-2xl border border-zinc-200 bg-white p-4 text-left hover:border-zinc-400">
            <p className="text-xs text-zinc-500">{label}</p>
            <p className={cn('mt-1 text-2xl font-bold', color)}>{value}</p>
          </button>
        ))}
      </div>
      {c.products === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 text-center">
          <p className="font-semibold text-zinc-900">Mulai dari produk terlarismu</p>
          <p className="mt-1 text-sm text-zinc-600">Tambahkan satu produk dulu, lihat HPP dan saran harganya.</p>
          <PrimaryButton type="button" onClick={() => goTo('products')} className="mx-auto mt-3">Tambah produk</PrimaryButton>
        </div>
      ) : (
        <div className="rounded-2xl border border-zinc-200 bg-white">
          <p className="border-b border-zinc-100 px-4 py-3 font-semibold text-zinc-900">Margin paling tipis</p>
          <ul className="divide-y divide-zinc-100">
            {data.products.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                <span className="flex items-center gap-2 font-medium text-zinc-900">
                  {p.incomplete || (p.margin_pct !== null && p.margin_pct < p.target_margin_pct) ? <AlertTriangle className="h-4 w-4 text-amber-600" /> : <CheckCircle2 className="h-4 w-4 text-emerald-600" />}
                  {p.name}
                </span>
                {p.incomplete ? <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Lengkapi resep</span> : (
                <span className="flex items-center gap-3 text-zinc-600">
                  HPP {rupiah(p.cost.full)} · jual {rupiah(p.selling_price)} · <MarginText value={p.margin_pct} target={p.target_margin_pct} />
                  <FoodCostBadge status={p.food_cost_status} value={p.food_cost_pct} />
                </span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

function AlertsPanel({ alerts, onToast, onChanged }: { alerts: HppAlert[]; onToast: (m: string) => void; onChanged: () => void }) {
  const [busy, setBusy] = useState<number | null>(null);
  const apply = async (alert: HppAlert) => {
    if (!alert.suggested_price) return;
    setBusy(alert.product_id);
    try {
      await applyHppPrice(alert.product_id, alert.suggested_price);
      onToast(`Harga ${alert.name} jadi ${rupiah(alert.suggested_price)}${alert.pos_product_id ? ' (juga di POS)' : ''}.`);
      onChanged();
    } catch (err) {
      onToast(errorsFrom(err).form ?? 'Harga belum diubah.');
    } finally {
      setBusy(null);
    }
  };
  return (
    <div className="rounded-2xl border border-amber-200 bg-amber-50/60" data-hpp-alerts>
      <p className="flex items-center gap-2 border-b border-amber-200 px-4 py-3 font-semibold text-amber-900"><AlertTriangle className="h-5 w-5" /> Peringatan margin ({alerts.length})</p>
      <ul className="divide-y divide-amber-100">
        {alerts.map((a) => (
          <li key={a.product_id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" data-alert={a.name}>
            <span className={cn('min-w-0 flex-1', a.severity === 'critical' ? 'text-red-800' : 'text-amber-900')}>{a.message}</span>
            {a.suggested_price && a.suggested_price > a.selling_price && (
              <button type="button" disabled={busy === a.product_id} onClick={() => void apply(a)} className="min-h-10 rounded-xl bg-zinc-900 px-3 text-xs font-bold text-white disabled:opacity-50" data-apply-alert>
                Pakai {rupiah(a.suggested_price)}{a.pos_product_id ? ' di POS' : ''}
              </button>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

function SettingsTab({ settings, data, onSaved }: { settings: HppSettings; data: HppOverview; onSaved: (m: string) => void }) {
  const [margin, setMargin] = useState(String(settings.target_margin_pct));
  const [rounding, setRounding] = useState<HppRounding>(settings.rounding);
  const [labor, setLabor] = useState(settings.labor_rate_per_hour);
  const [type, setType] = useState(settings.business_type);
  const [backflush, setBackflush] = useState(settings.backflush_enabled);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await updateHppSettings({ target_margin_pct: Number(margin.replace(',', '.')) || 0, rounding, labor_rate_per_hour: labor, business_type: type, backflush_enabled: backflush });
      onSaved('Pengaturan disimpan. Saran harga ikut diperbarui.');
    } catch (err) {
      setErrors(errorsFrom(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void save(e)} noValidate className="max-w-xl space-y-4 rounded-2xl border border-zinc-200 bg-white p-4" data-hpp-settings>
      <Field label="Target margin (%)" error={errors.target_margin_pct} hint="Dipakai untuk saran harga jual semua produk (bisa diubah per produk).">
        <input name="target_margin_pct" inputMode="decimal" value={margin} onChange={(e) => setMargin(e.target.value)} className={inputClass(errors.target_margin_pct)} />
      </Field>
      <Field label="Pembulatan harga" error={errors.rounding}>
        <select name="rounding" value={rounding} onChange={(e) => setRounding(e.target.value as HppRounding)} className={inputClass(errors.rounding)}>
          {(Object.keys(ROUNDING_LABELS) as HppRounding[]).map((r) => <option key={r} value={r}>{ROUNDING_LABELS[r]}</option>)}
        </select>
      </Field>
      <Field label="Tarif tenaga kerja per jam" error={errors.labor_rate_per_hour} hint="Untuk jasa & produksi: jam kerja × tarif masuk ke HPP.">
        <MoneyInput name="labor_rate_per_hour" value={labor} onChange={setLabor} error={errors.labor_rate_per_hour} />
      </Field>
      <Field label="Jenis usaha" error={errors.business_type}>
        <select name="business_type" value={type} onChange={(e) => setType(e.target.value)} className={inputClass(errors.business_type)}>
          {data.options.business_types.map((b) => <option key={b.key} value={b.key}>{b.label}</option>)}
        </select>
      </Field>
      <label className="flex items-start gap-3 rounded-xl border border-zinc-200 p-3 text-sm">
        <input type="checkbox" name="backflush_enabled" checked={backflush} onChange={(e) => setBackflush(e.target.checked)} className="mt-0.5 h-5 w-5" />
        <span><strong className="text-zinc-900">Potong stok bahan otomatis dari POS</strong><span className="block text-zinc-500">Setiap pesanan POS yang lunas mengurangi stok bahan sesuai resep produk yang ditautkan (termasuk modifier).</span></span>
      </label>
      {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
      <PrimaryButton type="submit" busy={busy}>Simpan pengaturan</PrimaryButton>
    </form>
  );
}
