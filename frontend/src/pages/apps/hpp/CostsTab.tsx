import { useCallback, useEffect, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { deleteHppChannel, deleteHppOverhead, getHppCosts, loadHppOverheadTemplate, saveHppChannel, saveHppOverhead, updateHppSettings } from '@/lib/hellomApi';
import type { HppChannel, HppCosts, HppOverhead, HppSettings } from '@/lib/hellomApi';
import { Dialog, Field, MoneyInput, PrimaryButton, errorsFrom, inputClass, rupiah, toDecimal } from './shared';
import type { FieldErrors } from './shared';

// Biaya tetap (sewa, listrik, gaji, …) + how it is spread over products, and sales channels with
// their fees (QRIS MDR, payment gateway, Hellom, ojol commission).

const CATEGORY_LABELS: Record<string, string> = {
  rent: 'Sewa', utility: 'Listrik/air/gas/internet', salary: 'Gaji', subscription: 'Langganan aplikasi', depreciation: 'Penyusutan alat', other: 'Lainnya',
};
const KIND_LABELS: Record<string, string> = {
  cash: 'Kasir tunai', qris: 'QRIS', hellom: 'Hellom Page / online', gofood: 'GoFood', grabfood: 'GrabFood', shopeefood: 'ShopeeFood', custom: 'Lainnya',
};
const METHODS: Array<{ key: HppSettings['overhead_method']; label: string; hint: string }> = [
  { key: 'per_unit', label: 'Per estimasi penjualan', hint: 'Paling mudah: total biaya ÷ jumlah terjual per bulan.' },
  { key: 'revenue_pct', label: 'Persentase omzet', hint: 'Biaya tetap ÷ omzet bulanan, dibebankan sesuai harga.' },
  { key: 'labor_hour', label: 'Per jam kerja', hint: 'Untuk jasa & produksi: dibagi jam kerja per bulan.' },
];

export default function CostsTab({ settings, onToast, onChanged }: { settings: HppSettings; onToast: (m: string) => void; onChanged: () => void }) {
  const [data, setData] = useState<HppCosts | null>(null);
  const [overhead, setOverhead] = useState<HppOverhead | 'new' | null>(null);
  const [channel, setChannel] = useState<HppChannel | 'new' | null>(null);

  const load = useCallback(() => {
    getHppCosts().then(setData).catch(() => setData({ overheads: [], overhead_total: 0, channels: [], outlets: [] }));
  }, []);
  useEffect(load, [load]);
  const changed = (next: HppCosts, message: string) => { setData(next); onToast(message); onChanged(); };

  if (!data) return <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p>;

  return (
    <section className="grid gap-4 lg:grid-cols-2" data-hpp-costs>
      <div className="space-y-4">
        <div className="rounded-2xl border border-zinc-200 bg-white">
          <div className="flex items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3">
            <div>
              <p className="font-semibold text-zinc-900">Biaya tetap per bulan</p>
              <p className="text-xs text-zinc-500">Total <strong data-overhead-total>{rupiah(data.overhead_total)}</strong></p>
            </div>
            <button type="button" onClick={() => setOverhead('new')} className="flex min-h-10 items-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-semibold" data-add-overhead>
              <Plus className="h-4 w-4" /> Biaya
            </button>
          </div>
          {data.overheads.length === 0 ? (
            <div className="p-4 text-sm text-zinc-600">
              Belum ada biaya tetap. <button type="button" className="font-semibold underline" data-overhead-template
                onClick={() => void loadHppOverheadTemplate().then((d) => changed(d, 'Contoh biaya dimuat. Sesuaikan nominalnya.'))}>Isi contoh biaya usahamu</button>
            </div>
          ) : (
            <ul className="divide-y divide-zinc-100">
              {data.overheads.map((o) => (
                <li key={o.id} className={cn('flex items-center gap-3 px-4 py-2.5 text-sm', !o.is_active && 'opacity-50')}>
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-zinc-900">{o.name}</p>
                    <p className="text-xs text-zinc-500">{CATEGORY_LABELS[o.category] ?? o.category}{o.outlet_id ? ` · ${data.outlets.find((x) => x.id === o.outlet_id)?.name ?? 'outlet'}` : ''}</p>
                  </div>
                  <span className="font-semibold text-zinc-900">{rupiah(o.amount)}</span>
                  <button type="button" aria-label={`Ubah ${o.name}`} onClick={() => setOverhead(o)} className="flex h-10 w-10 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100"><Pencil className="h-4 w-4" /></button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <AllocationForm settings={settings} overheadTotal={data.overhead_total} onSaved={(m) => { onToast(m); onChanged(); }} channels={data.channels} />
      </div>

      <div className="rounded-2xl border border-zinc-200 bg-white">
        <div className="flex items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3">
          <div>
            <p className="font-semibold text-zinc-900">Channel penjualan</p>
            <p className="text-xs text-zinc-500">Harga aman di kasir bisa rugi di ojol — fee dihitung per channel.</p>
          </div>
          <button type="button" onClick={() => setChannel('new')} className="flex min-h-10 items-center gap-1 rounded-xl border border-zinc-300 px-3 text-sm font-semibold"><Plus className="h-4 w-4" /> Channel</button>
        </div>
        <ul className="divide-y divide-zinc-100">
          {data.channels.map((c) => (
            <li key={c.id} className={cn('flex items-center gap-3 px-4 py-2.5 text-sm', !c.is_active && 'opacity-50')} data-channel-row={c.name}>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-zinc-900">{c.name}{settings.main_channel_id === c.id && <span className="ml-2 rounded-full bg-zinc-900 px-2 py-0.5 text-[10px] text-white">utama</span>}</p>
                <p className="text-xs text-zinc-500">{KIND_LABELS[c.kind] ?? c.kind}</p>
              </div>
              <span className="font-semibold text-zinc-900">{c.fee_pct.toLocaleString('id-ID')}%{c.fee_flat ? ` + ${rupiah(c.fee_flat)}` : ''}</span>
              <button type="button" aria-label={`Ubah ${c.name}`} onClick={() => setChannel(c)} className="flex h-10 w-10 items-center justify-center rounded-full text-zinc-500 hover:bg-zinc-100"><Pencil className="h-4 w-4" /></button>
            </li>
          ))}
        </ul>
      </div>

      {overhead && <OverheadDialog overhead={overhead === 'new' ? null : overhead} outlets={data.outlets} onClose={() => setOverhead(null)} onSaved={(d, m) => { setOverhead(null); changed(d, m); }} />}
      {channel && <ChannelDialog channel={channel === 'new' ? null : channel} onClose={() => setChannel(null)} onSaved={(d, m) => { setChannel(null); changed(d, m); }} />}
    </section>
  );
}

function AllocationForm({ settings, overheadTotal, channels, onSaved }: { settings: HppSettings; overheadTotal: number; channels: HppChannel[]; onSaved: (m: string) => void }) {
  const [method, setMethod] = useState(settings.overhead_method);
  const [units, setUnits] = useState(String(settings.monthly_units || ''));
  const [revenue, setRevenue] = useState(settings.monthly_revenue);
  const [hours, setHours] = useState(String(settings.monthly_labor_hours || ''));
  const [main, setMain] = useState(settings.main_channel_id ? String(settings.main_channel_id) : '');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);
  const perUnit = method === 'per_unit' && toDecimal(units) > 0 ? overheadTotal / toDecimal(units) : null;

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await updateHppSettings({ overhead_method: method, monthly_units: Math.round(toDecimal(units)), monthly_revenue: revenue, monthly_labor_hours: Math.round(toDecimal(hours)), main_channel_id: main ? Number(main) : null });
      onSaved('Cara pembagian biaya disimpan. HPP semua produk dihitung ulang.');
    } catch (err) {
      setErrors(errorsFrom(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => void save(e)} noValidate className="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4" data-allocation-form>
      <p className="font-semibold text-zinc-900">Dibagi ke produk dengan cara</p>
      <div className="space-y-2">
        {METHODS.map((m) => (
          <label key={m.key} className={cn('flex cursor-pointer gap-3 rounded-xl border p-3 text-sm', method === m.key ? 'border-zinc-900' : 'border-zinc-200')}>
            <input type="radio" name="overhead_method" checked={method === m.key} onChange={() => setMethod(m.key)} className="mt-0.5" />
            <span><strong className="text-zinc-900">{m.label}</strong><span className="block text-zinc-500">{m.hint}</span></span>
          </label>
        ))}
      </div>
      {method === 'per_unit' && (
        <Field label="Perkiraan total terjual per bulan (semua produk)" error={errors.monthly_units} hint={perUnit !== null ? <>Overhead per produk terjual: <strong>{rupiah(perUnit)}</strong></> : 'Mis. 1.500 cup per bulan.'}>
          <input name="monthly_units" inputMode="numeric" value={units} onChange={(e) => setUnits(e.target.value)} className={inputClass(errors.monthly_units)} />
        </Field>
      )}
      {method === 'revenue_pct' && (
        <Field label="Perkiraan omzet per bulan" error={errors.monthly_revenue} hint={revenue > 0 ? `Biaya tetap = ${((overheadTotal / revenue) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}% dari omzet.` : undefined}>
          <MoneyInput name="monthly_revenue" value={revenue} onChange={setRevenue} error={errors.monthly_revenue} />
        </Field>
      )}
      {method === 'labor_hour' && (
        <Field label="Total jam kerja per bulan" error={errors.monthly_labor_hours} hint="Isi waktu kerja per produk di form produk.">
          <input name="monthly_labor_hours" inputMode="numeric" value={hours} onChange={(e) => setHours(e.target.value)} className={inputClass(errors.monthly_labor_hours)} />
        </Field>
      )}
      <Field label="Channel utama (untuk margin & BEP)" error={errors.main_channel_id} hint="Kosong = QRIS, atau channel pertama.">
        <select name="main_channel_id" value={main} onChange={(e) => setMain(e.target.value)} className={inputClass(errors.main_channel_id)}>
          <option value="">Otomatis</option>
          {channels.filter((c) => c.is_active).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </Field>
      {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
      <PrimaryButton type="submit" busy={busy}>Simpan</PrimaryButton>
    </form>
  );
}

function OverheadDialog({ overhead, outlets, onClose, onSaved }: { overhead: HppOverhead | null; outlets: Array<{ id: number; name: string }>; onClose: () => void; onSaved: (d: HppCosts, m: string) => void }) {
  const [name, setName] = useState(overhead?.name ?? '');
  const [category, setCategory] = useState(overhead?.category ?? 'rent');
  const [amount, setAmount] = useState(overhead?.amount ?? 0);
  const [outlet, setOutlet] = useState(overhead?.outlet_id ? String(overhead.outlet_id) : '');
  const [active, setActive] = useState(overhead?.is_active ?? true);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      onSaved(await saveHppOverhead({ name, category, amount, outlet_id: outlet ? Number(outlet) : null, is_active: active }, overhead?.id), `"${name}" disimpan.`);
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };
  const remove = async () => {
    if (!overhead) return;
    setBusy(true);
    try {
      onSaved(await deleteHppOverhead(overhead.id), `"${overhead.name}" dihapus.`);
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };

  return (
    <Dialog title={overhead ? 'Ubah biaya' : 'Tambah biaya tetap'} onClose={onClose} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate className="space-y-3" data-overhead-form>
        <Field label="Nama biaya" error={errors.name}><input name="name" value={name} onChange={(e) => setName(e.target.value)} className={inputClass(errors.name)} placeholder="mis. Sewa ruko" autoFocus /></Field>
        <Field label="Jenis" error={errors.category}>
          <select name="category" value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass(errors.category)}>
            {Object.entries(CATEGORY_LABELS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
        </Field>
        <Field label="Nominal per bulan" error={errors.amount}><MoneyInput name="amount" value={amount} onChange={setAmount} error={errors.amount} /></Field>
        {outlets.length > 1 && (
          <Field label="Outlet" error={errors.outlet_id}>
            <select name="outlet_id" value={outlet} onChange={(e) => setOutlet(e.target.value)} className={inputClass(errors.outlet_id)}>
              <option value="">Semua outlet</option>
              {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
          </Field>
        )}
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="h-5 w-5" /> Dihitung</label>
        {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
        <div className="flex gap-2">
          {overhead && <button type="button" onClick={() => void remove()} disabled={busy} aria-label="Hapus biaya" className="flex min-h-11 items-center rounded-xl border border-zinc-300 px-3"><Trash2 className="h-4 w-4" /></button>}
          <PrimaryButton type="submit" busy={busy} className="flex-1">Simpan</PrimaryButton>
        </div>
      </form>
    </Dialog>
  );
}

function ChannelDialog({ channel, onClose, onSaved }: { channel: HppChannel | null; onClose: () => void; onSaved: (d: HppCosts, m: string) => void }) {
  const [name, setName] = useState(channel?.name ?? '');
  const [kind, setKind] = useState(channel?.kind ?? 'custom');
  const [fee, setFee] = useState(String(channel?.fee_pct ?? 0).replace('.', ','));
  const [flat, setFlat] = useState(channel?.fee_flat ?? 0);
  const [active, setActive] = useState(channel?.is_active ?? true);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      onSaved(await saveHppChannel({ name, kind, fee_pct: toDecimal(fee), fee_flat: flat, is_active: active }, channel?.id), `Channel "${name}" disimpan.`);
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };
  const remove = async () => {
    if (!channel) return;
    setBusy(true);
    try {
      onSaved(await deleteHppChannel(channel.id), `Channel "${channel.name}" dihapus.`);
    } catch (err) {
      setErrors(errorsFrom(err));
      setBusy(false);
    }
  };

  return (
    <Dialog title={channel ? 'Ubah channel' : 'Tambah channel'} onClose={onClose} busy={busy}>
      <form onSubmit={(e) => void submit(e)} noValidate className="space-y-3" data-channel-form>
        <Field label="Nama" error={errors.name}><input name="name" value={name} onChange={(e) => setName(e.target.value)} className={inputClass(errors.name)} autoFocus /></Field>
        <Field label="Jenis" error={errors.kind}>
          <select name="kind" value={kind} onChange={(e) => setKind(e.target.value)} className={inputClass(errors.kind)}>
            {Object.entries(KIND_LABELS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Fee / komisi (%)" error={errors.fee_pct}><input name="fee_pct" inputMode="decimal" value={fee} onChange={(e) => setFee(e.target.value)} className={inputClass(errors.fee_pct)} /></Field>
          <Field label="Fee tetap per transaksi" error={errors.fee_flat}><MoneyInput name="fee_flat" value={flat} onChange={setFlat} error={errors.fee_flat} /></Field>
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="h-5 w-5" /> Aktif</label>
        {errors.form && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{errors.form}</p>}
        <div className="flex gap-2">
          {channel && <button type="button" onClick={() => void remove()} disabled={busy} aria-label="Hapus channel" className="flex min-h-11 items-center rounded-xl border border-zinc-300 px-3"><Trash2 className="h-4 w-4" /></button>}
          <PrimaryButton type="submit" busy={busy} className="flex-1">Simpan</PrimaryButton>
        </div>
      </form>
    </Dialog>
  );
}
