import { useEffect, useState } from 'react';
import { CheckCircle2, Loader2, PlugZap, Truck } from 'lucide-react';
import { ApiError, getAdminShippingSettings, testAdminShipping, updateAdminShippingSettings } from '@/lib/hellomApi';
import type { AdminShippingSettings } from '@/lib/hellomApi';

/**
 * Super admin › Pengaturan › Ongkir: real courier rates for sellers' physical products
 * (RajaOngkir by Komerce). The API key is write-only: it is never shown again once saved.
 */
export default function ShippingSettingsCard() {
  const [settings, setSettings] = useState<AdminShippingSettings | null>(null);
  const [provider, setProvider] = useState<'none' | 'rajaongkir'>('none');
  const [apiKey, setApiKey] = useState('');
  const [couriers, setCouriers] = useState<string[]>([]);
  const [cacheHours, setCacheHours] = useState(12);
  const [busy, setBusy] = useState<'save' | 'test' | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const apply = (data: AdminShippingSettings) => {
    setSettings(data);
    setProvider(data.provider);
    setCouriers(data.couriers);
    setCacheHours(data.cache_hours);
  };

  useEffect(() => {
    getAdminShippingSettings().then(apply).catch((err: unknown) => setMessage({ ok: false, text: err instanceof Error ? err.message : 'Pengaturan ongkir belum bisa dimuat.' }));
  }, []);

  const errorText = (err: unknown) => (err instanceof ApiError ? Object.values(err.fieldErrors)[0]?.[0] ?? err.message : err instanceof Error ? err.message : 'Gagal.');

  const save = async () => {
    setBusy('save');
    setMessage(null);
    try {
      apply(await updateAdminShippingSettings({ provider, api_key: apiKey.trim() || undefined, couriers, cache_hours: cacheHours }));
      setApiKey('');
      setMessage({ ok: true, text: 'Pengaturan ongkir disimpan.' });
    } catch (err) {
      setMessage({ ok: false, text: errorText(err) });
    } finally {
      setBusy(null);
    }
  };

  const test = async () => {
    setBusy('test');
    setMessage(null);
    try {
      const result = await testAdminShipping();
      setMessage({ ok: true, text: `Terhubung ke RajaOngkir. Contoh hasil: ${result.sample ?? '-'}` });
    } catch (err) {
      setMessage({ ok: false, text: errorText(err) });
    } finally {
      setBusy(null);
    }
  };

  if (!settings) {
    return <div className="rounded-3xl border border-zinc-200 bg-white p-6 text-sm text-zinc-500 shadow-sm">{message?.text ?? 'Memuat pengaturan ongkir…'}</div>;
  }

  return (
    <div className="space-y-5 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-lg font-bold text-zinc-900"><Truck className="h-5 w-5" /> Ongkir otomatis</h2>
          <p className="mt-1 max-w-2xl text-sm text-zinc-500">
            Tarif kurir asli untuk produk fisik penjual Hellom Page (RajaOngkir by Komerce). Daftar akun di rajaongkir.com, lalu tempel API key di sini.
            Hasil cek ongkir disimpan sementara supaya kuota harian (paket gratis: 100 cek/hari) tidak cepat habis.
          </p>
        </div>
        <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${settings.ready ? 'bg-green-100 text-green-800' : 'bg-zinc-100 text-zinc-600'}`}>
          {settings.ready ? <><CheckCircle2 className="h-3.5 w-3.5" /> Aktif</> : 'Belum aktif'}
        </span>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <label className="space-y-1 text-sm">
          <span className="font-medium text-zinc-800">Layanan</span>
          <select value={provider} onChange={(e) => setProvider(e.target.value as 'none' | 'rajaongkir')} className="min-h-11 w-full rounded-xl border border-zinc-300 bg-white px-3 text-sm">
            <option value="none">Nonaktif</option>
            <option value="rajaongkir">RajaOngkir (Komerce)</option>
          </select>
        </label>
        <label className="space-y-1 text-sm">
          <span className="font-medium text-zinc-800">API key {settings.api_key_set && <span className="font-normal text-green-700">· tersimpan</span>}</span>
          <input
            type="password"
            autoComplete="new-password"
            value={apiKey}
            onChange={(e) => setApiKey(e.target.value)}
            placeholder={settings.api_key_set ? 'Kosongkan bila tidak diganti' : 'Tempel API key RajaOngkir'}
            className="min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-sm"
          />
        </label>
        <label className="space-y-1 text-sm">
          <span className="font-medium text-zinc-800">Simpan hasil cek ongkir (jam)</span>
          <input type="number" min={1} max={72} value={cacheHours} onChange={(e) => setCacheHours(Number(e.target.value) || 12)} className="min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-sm" />
        </label>
      </div>

      <fieldset>
        <legend className="text-sm font-medium text-zinc-800">Kurir yang boleh dipakai penjual</legend>
        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
          {Object.entries(settings.courier_labels).map(([code, label]) => (
            <label key={code} className="flex min-h-11 items-center gap-2 rounded-xl border border-zinc-200 px-3 text-sm">
              <input type="checkbox" className="h-4 w-4" checked={couriers.includes(code)}
                onChange={() => setCouriers((list) => (list.includes(code) ? list.filter((c) => c !== code) : [...list, code]))} />
              {label}
            </label>
          ))}
        </div>
      </fieldset>

      <div className="flex flex-wrap gap-2">
        <button type="button" onClick={() => void save()} disabled={busy !== null || couriers.length === 0} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-zinc-900 px-5 text-sm font-semibold text-white disabled:opacity-50">
          {busy === 'save' && <Loader2 className="h-4 w-4 animate-spin" />} Simpan
        </button>
        <button type="button" onClick={() => void test()} disabled={busy !== null || !settings.api_key_set} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-zinc-300 px-5 text-sm font-semibold text-zinc-800 disabled:opacity-50">
          {busy === 'test' ? <Loader2 className="h-4 w-4 animate-spin" /> : <PlugZap className="h-4 w-4" />} Tes koneksi
        </button>
      </div>
      {message && <p role="status" className={message.ok ? 'text-sm text-green-700' : 'text-sm text-red-600'}>{message.text}</p>}
    </div>
  );
}
