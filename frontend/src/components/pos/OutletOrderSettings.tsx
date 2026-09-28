import { useEffect, useState } from 'react';
import { getPosOutletSettings, updatePosOutletSettings } from '@/lib/hellomApi';
import type { PosOpeningSlot, PosOutletSettings, PosOutletStatus } from '@/lib/hellomApi';

type Day = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
const DAYS: Array<[Day, string]> = [
  ['mon', 'Senin'], ['tue', 'Selasa'], ['wed', 'Rabu'], ['thu', 'Kamis'], ['fri', 'Jumat'], ['sat', 'Sabtu'], ['sun', 'Minggu'],
];
const emptyWeek = (): Record<Day, PosOpeningSlot[]> =>
  ({ mon: [], tue: [], wed: [], thu: [], fri: [], sat: [], sun: [] });

/**
 * Settings of the active outlet: tax, service charge, rounding, self-order behaviour and
 * opening hours. Owner/admin only on save (the server enforces it).
 */
export default function OutletOrderSettings() {
  const [settings, setSettings] = useState<PosOutletSettings | null>(null);
  const [status, setStatus] = useState<PosOutletStatus | null>(null);
  const [useHours, setUseHours] = useState(false);
  const [hours, setHours] = useState<Record<Day, PosOpeningSlot[]>>(emptyWeek());
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        const res = await getPosOutletSettings();
        setSettings(res.settings);
        setStatus(res.status);
        setUseHours(res.settings.opening_hours !== null);
        setHours({ ...emptyWeek(), ...(res.settings.opening_hours ?? {}) });
      } catch (err) {
        setMessage({ ok: false, text: err instanceof Error ? err.message : 'Gagal memuat pengaturan' });
      }
    })();
  }, []);

  if (!settings) {
    return <div className="rounded-2xl bg-white p-6 text-sm text-gray-500 shadow-sm">{message?.text ?? 'Memuat pengaturan outlet…'}</div>;
  }

  const setPricing = (patch: Partial<PosOutletSettings['pricing']>) =>
    setSettings({ ...settings, pricing: { ...settings.pricing, ...patch } });
  const setSelfOrder = (patch: Partial<PosOutletSettings['self_order']>) =>
    setSettings({ ...settings, self_order: { ...settings.self_order, ...patch } });
  const setSlot = (day: Day, index: number, patch: Partial<PosOpeningSlot>) =>
    setHours({ ...hours, [day]: hours[day].map((slot, i) => (i === index ? { ...slot, ...patch } : slot)) });

  const save = async () => {
    setSaving(true);
    setMessage(null);
    try {
      const res = await updatePosOutletSettings({
        pricing: settings.pricing,
        self_order: settings.self_order,
        opening_hours: useHours ? hours : null,
      });
      setSettings(res.settings);
      setStatus(res.status);
      setMessage({ ok: true, text: 'Pengaturan outlet disimpan.' });
    } catch (err) {
      setMessage({ ok: false, text: err instanceof Error ? err.message : 'Gagal menyimpan' });
    } finally {
      setSaving(false);
    }
  };

  const input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300';

  return (
    <div className="space-y-4">
      <section className="rounded-2xl bg-white p-6 shadow-sm">
        <h2 className="text-lg font-bold text-gray-900">Harga di kasir & self-order</h2>
        <p className="mt-1 text-sm text-gray-500">Berlaku sama untuk kasir dan pesanan dari QR. Total dihitung server.</p>
        <div className="mt-4 grid gap-4 sm:grid-cols-3">
          <label className="text-sm text-gray-700">
            Pajak (%)
            <input type="number" min={0} max={100} step="0.01" className={input} value={settings.pricing.tax_percent}
              onChange={(e) => setPricing({ tax_percent: Number(e.target.value) })} />
          </label>
          <label className="text-sm text-gray-700">
            Service charge (%)
            <input type="number" min={0} max={100} step="0.01" className={input} value={settings.pricing.service_percent}
              onChange={(e) => setPricing({ service_percent: Number(e.target.value) })} />
          </label>
          <label className="text-sm text-gray-700">
            Pembulatan total
            <select className={input} value={settings.pricing.rounding}
              onChange={(e) => setPricing({ rounding: Number(e.target.value) as PosOutletSettings['pricing']['rounding'] })}>
              <option value={0}>Tanpa pembulatan</option>
              <option value={100}>Rp 100</option>
              <option value={500}>Rp 500</option>
              <option value={1000}>Rp 1.000</option>
            </select>
          </label>
        </div>
        <p className="mt-2 text-xs text-gray-500">Urutan: subtotal − diskon → + service → + pajak (dari subtotal + service) → pembulatan.</p>
      </section>

      <section className="rounded-2xl bg-white p-6 shadow-sm">
        <h2 className="text-lg font-bold text-gray-900">Self-order (QR meja)</h2>
        <div className="mt-4 space-y-3 text-sm text-gray-700">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={settings.self_order.accept_orders} onChange={(e) => setSelfOrder({ accept_orders: e.target.checked })} />
            Terima pesanan dari QR
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={settings.self_order.require_confirmation} onChange={(e) => setSelfOrder({ require_confirmation: e.target.checked })} />
            Kasir harus konfirmasi dulu (jika mati, pesanan langsung masuk dapur)
          </label>
          <label className="flex items-center gap-2">
            Maks. pesanan menunggu konfirmasi per meja
            <input type="number" min={1} max={20} className="w-20 rounded-lg border border-gray-300 px-2 py-1" value={settings.self_order.max_pending_per_table}
              onChange={(e) => setSelfOrder({ max_pending_per_table: Number(e.target.value) })} />
          </label>
        </div>
      </section>

      <section className="rounded-2xl bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-bold text-gray-900">Jam buka</h2>
          {status && (
            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${status.can_order ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
              {status.can_order ? 'Sekarang menerima pesanan' : 'Sekarang tutup untuk self-order'}
            </span>
          )}
        </div>
        <label className="mt-3 flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" checked={useHours} onChange={(e) => setUseHours(e.target.checked)} />
          Atur jam buka (jika mati: self-order selalu buka)
        </label>
        {useHours && (
          <div className="mt-4 space-y-2">
            {DAYS.map(([day, label]) => (
              <div key={day} className="flex flex-wrap items-center gap-2 text-sm">
                <span className="w-16 font-medium text-gray-800">{label}</span>
                {hours[day].length === 0 && <span className="text-gray-400">Tutup</span>}
                {hours[day].map((slot, index) => (
                  <span key={index} className="flex items-center gap-1">
                    <input type="time" value={slot.open} onChange={(e) => setSlot(day, index, { open: e.target.value })} className="rounded border border-gray-300 px-2 py-1" />
                    –
                    <input type="time" value={slot.close} onChange={(e) => setSlot(day, index, { close: e.target.value })} className="rounded border border-gray-300 px-2 py-1" />
                    <button type="button" className="px-1 text-red-500" onClick={() => setHours({ ...hours, [day]: hours[day].filter((_, i) => i !== index) })}>✕</button>
                  </span>
                ))}
                <button type="button" className="rounded border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50"
                  onClick={() => setHours({ ...hours, [day]: [...hours[day], { open: '08:00', close: '22:00' }] })}>
                  + Jam
                </button>
              </div>
            ))}
            <p className="text-xs text-gray-500">Jam lewat tengah malam boleh (mis. 18:00–02:00). Zona waktu: {settings.timezone}.</p>
          </div>
        )}
      </section>

      {message && (
        <p className={`rounded-lg px-4 py-3 text-sm ${message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>
      )}
      <button onClick={() => void save()} disabled={saving}
        className="rounded-lg bg-amber-400 px-5 py-2 text-sm font-semibold text-[#111111] hover:bg-amber-500 disabled:opacity-50">
        {saving ? 'Menyimpan…' : 'Simpan pengaturan outlet'}
      </button>
    </div>
  );
}
