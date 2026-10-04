import { useState } from 'react';
import { CalendarDays, Clock, Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { BookingSettings } from '@/lib/hellomApi';

/**
 * Seller settings of a "Sewa / booking jadwal" product: per day/night (rental car, camera, villa) or
 * per session within opening hours (studio, court, salon); units out at once; closed dates.
 * Values are clamped again on the server (BookingService::normalize).
 */
export const DEFAULT_BOOKING: BookingSettings = {
  mode: 'daily',
  units: 1,
  unit_label: 'hari',
  min_days: 1,
  max_days: 14,
  slot_minutes: 60,
  max_slots: 4,
  hours: { '1': ['09:00', '17:00'], '2': ['09:00', '17:00'], '3': ['09:00', '17:00'], '4': ['09:00', '17:00'], '5': ['09:00', '17:00'], '6': ['09:00', '17:00'], '7': null },
  lead_hours: 0,
  max_days_ahead: 90,
  blocked_dates: [],
};

const DAYS: Array<[string, string]> = [['1', 'Senin'], ['2', 'Selasa'], ['3', 'Rabu'], ['4', 'Kamis'], ['5', 'Jumat'], ['6', 'Sabtu'], ['7', 'Minggu']];
const input = 'mt-1 min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none focus:border-zinc-900';
const clamp = (v: string, min: number, max: number) => Math.max(min, Math.min(max, Number(v.replace(/\D/g, '')) || min));
const longDate = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

export default function RentalSettings({ value, onChange, error }: { value: BookingSettings; onChange: (next: BookingSettings) => void; error?: string | null }) {
  const set = (patch: Partial<BookingSettings>) => onChange({ ...value, ...patch });
  const [blockDate, setBlockDate] = useState('');
  const seg = (active: boolean) => cn('flex min-h-14 flex-1 items-center gap-2 rounded-xl border px-3 text-left text-sm', active ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-800');

  return (
    <section className="space-y-4 rounded-2xl bg-zinc-50 p-4" data-rental-settings>
      <p className="text-sm font-semibold">Pengaturan sewa / booking</p>

      <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Cara booking">
        <button type="button" role="radio" aria-checked={value.mode === 'daily'} onClick={() => set({ mode: 'daily', lead_hours: value.mode === 'daily' ? value.lead_hours : 0 })} className={seg(value.mode === 'daily')}>
          <CalendarDays className="h-5 w-5 shrink-0" />
          <span><span className="block font-semibold">Per hari / malam</span><span className={cn('block text-xs', value.mode === 'daily' ? 'text-white/70' : 'text-zinc-500')}>Rental mobil, kamera, alat camping, villa</span></span>
        </button>
        <button type="button" role="radio" aria-checked={value.mode === 'slot'} onClick={() => set({ mode: 'slot', lead_hours: value.mode === 'slot' ? value.lead_hours : 2 })} className={seg(value.mode === 'slot')}>
          <Clock className="h-5 w-5 shrink-0" />
          <span><span className="block font-semibold">Per jam / sesi</span><span className={cn('block text-xs', value.mode === 'slot' ? 'text-white/70' : 'text-zinc-500')}>Studio foto, lapangan, salon, konsultasi</span></span>
        </button>
      </div>

      <label className="block text-sm font-medium">Jumlah unit yang bisa disewa bersamaan
        <input inputMode="numeric" value={value.units} onChange={(e) => set({ units: clamp(e.target.value, 1, 100) })} className={input} />
        <span className="mt-1 block text-xs text-zinc-500">Misalnya punya 3 kamera yang sama → isi 3. Jadwal penuh otomatis tidak bisa dipilih pembeli.</span>
      </label>

      {value.mode === 'daily' ? (
        <>
          <div className="space-y-1.5">
            <p className="text-sm font-medium">Harga dihitung per</p>
            <div className="flex gap-2" role="radiogroup" aria-label="Satuan harga">
              {(['hari', 'malam'] as const).map((label) => (
                <button key={label} type="button" role="radio" aria-checked={value.unit_label === label} onClick={() => set({ unit_label: label })}
                  className={cn('min-h-11 flex-1 rounded-xl border text-sm font-semibold', value.unit_label === label ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white')}>{label === 'hari' ? 'Hari (sewa barang)' : 'Malam (penginapan)'}</button>
              ))}
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm font-medium">Minimal sewa ({value.unit_label})<input inputMode="numeric" value={value.min_days} onChange={(e) => set({ min_days: clamp(e.target.value, 1, 60) })} className={input} /></label>
            <label className="block text-sm font-medium">Maksimal sewa ({value.unit_label})<input inputMode="numeric" value={value.max_days} onChange={(e) => set({ max_days: clamp(e.target.value, 1, 90) })} className={input} /></label>
          </div>
        </>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm font-medium">Durasi 1 sesi
              <select value={value.slot_minutes} onChange={(e) => set({ slot_minutes: Number(e.target.value) })} className={input}>
                {[30, 45, 60, 90, 120, 180, 240].map((m) => <option key={m} value={m}>{m < 60 ? `${m} menit` : `${m / 60} jam`.replace('.5', ',5')}</option>)}
              </select>
            </label>
            <label className="block text-sm font-medium">Maks sesi sekali booking<input inputMode="numeric" value={value.max_slots} onChange={(e) => set({ max_slots: clamp(e.target.value, 1, 12) })} className={input} /></label>
          </div>
          <div className="space-y-2">
            <p className="text-sm font-medium">Jam buka</p>
            {DAYS.map(([key, label]) => {
              const hours = value.hours[key];
              return (
                <div key={key} className="flex flex-wrap items-center gap-2" data-day={key}>
                  <label className="flex min-h-11 w-28 items-center gap-2 text-sm">
                    <input type="checkbox" className="h-5 w-5" checked={!!hours} onChange={(e) => set({ hours: { ...value.hours, [key]: e.target.checked ? ['09:00', '17:00'] : null } })} />
                    {label}
                  </label>
                  {hours ? (
                    <span className="flex items-center gap-1.5 text-sm">
                      <input type="time" value={hours[0]} onChange={(e) => set({ hours: { ...value.hours, [key]: [e.target.value, hours[1]] } })} aria-label={`Buka ${label}`} className="min-h-11 rounded-lg border border-zinc-300 px-2 text-base" />
                      –
                      <input type="time" value={hours[1]} onChange={(e) => set({ hours: { ...value.hours, [key]: [hours[0], e.target.value] } })} aria-label={`Tutup ${label}`} className="min-h-11 rounded-lg border border-zinc-300 px-2 text-base" />
                    </span>
                  ) : <span className="text-sm text-zinc-500">Tutup</span>}
                </div>
              );
            })}
          </div>
        </>
      )}

      <div className="grid grid-cols-2 gap-3">
        <label className="block text-sm font-medium">Booking paling cepat (jam sebelumnya)<input inputMode="numeric" value={value.lead_hours} onChange={(e) => set({ lead_hours: Math.max(0, Math.min(168, Number(e.target.value.replace(/\D/g, '')) || 0)) })} className={input} /></label>
        <label className="block text-sm font-medium">Bisa dibooking sampai (hari ke depan)<input inputMode="numeric" value={value.max_days_ahead} onChange={(e) => set({ max_days_ahead: clamp(e.target.value, 1, 365) })} className={input} /></label>
      </div>

      <div className="space-y-2">
        <p className="text-sm font-medium">Tanggal tutup / tidak tersedia <span className="font-normal text-zinc-500">(libur, servis unit)</span></p>
        <div className="flex gap-2">
          <input type="date" value={blockDate} onChange={(e) => setBlockDate(e.target.value)} aria-label="Tanggal tutup" className="min-h-12 flex-1 rounded-xl border border-zinc-300 bg-white px-3 text-base" />
          <button type="button" disabled={!blockDate} onClick={() => { set({ blocked_dates: [...new Set([...value.blocked_dates, blockDate])].sort() }); setBlockDate(''); }}
            className="inline-flex min-h-12 items-center gap-1.5 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-semibold disabled:opacity-40"><Plus className="h-4 w-4" /> Tambah</button>
        </div>
        {value.blocked_dates.length > 0 && (
          <ul className="flex flex-wrap gap-1.5">
            {value.blocked_dates.map((date) => (
              <li key={date} className="inline-flex items-center gap-1 rounded-full bg-white py-1 pl-3 pr-1 text-sm ring-1 ring-zinc-200">
                {longDate(date)}
                <button type="button" onClick={() => set({ blocked_dates: value.blocked_dates.filter((d) => d !== date) })} aria-label={`Buka lagi ${longDate(date)}`} className="flex h-8 w-8 items-center justify-center rounded-full hover:bg-zinc-100"><X className="h-3.5 w-3.5" /></button>
              </li>
            ))}
          </ul>
        )}
      </div>
      {error && <p className="text-sm text-rose-600">{error}</p>}
      <p className="text-xs text-zinc-500">Pembeli memilih jadwal yang masih kosong lalu bayar penuh. Jadwal ditahan selama pembeli membayar dan langsung terkunci setelah lunas — kamu dan pembeli dapat email konfirmasi serta pengingat H-1.</p>
    </section>
  );
}
