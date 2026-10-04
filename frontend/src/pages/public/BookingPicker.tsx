import { useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getBookingDay, getBookingMonth } from '@/lib/hellomApi';
import type { BookingChoice, BookingDay, PublicBooking } from '@/lib/hellomApi';

/**
 * Checkout of a rental product: a month calendar with what is still free. Per day: start date +
 * number of days/nights (a range over a full day is refused right away). Per session: date, then
 * a start time chip and the number of sessions. The server checks everything again at checkout.
 */
const WEEK = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (date: string, n: number) => { const d = new Date(`${date}T00:00:00`); d.setDate(d.getDate() + n); return iso(d); };
const monthKey = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
const longDate = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });

export default function BookingPicker({ productId, booking, units, value, onChange, error }: {
  productId: string;
  booking: PublicBooking;
  units: number;
  value: BookingChoice | null;
  onChange: (choice: BookingChoice | null) => void;
  error?: string | null;
}) {
  const [cursor, setCursor] = useState(() => { const d = new Date(); d.setDate(1); return d; });
  const [months, setMonths] = useState<Record<string, Record<string, number>>>({});
  const [day, setDay] = useState<BookingDay | null>(null);
  const [loadingDay, setLoadingDay] = useState(false);
  const month = monthKey(cursor);
  const daily = booking.mode === 'daily';
  const selected = daily ? value?.start_date : value?.date;

  useEffect(() => {
    if (months[month]) return;
    let alive = true;
    getBookingMonth(productId, month).then((data) => alive && setMonths((m) => ({ ...m, [month]: data.days }))).catch(() => undefined);
    return () => { alive = false; };
  }, [productId, month, months]);

  // Session mode: free sessions of the chosen date.
  useEffect(() => {
    if (daily || !value?.date) return;
    let alive = true;
    setLoadingDay(true);
    getBookingDay(productId, value.date).then((data) => alive && setDay(data)).catch(() => undefined).finally(() => alive && setLoadingDay(false));
    return () => { alive = false; };
  }, [daily, productId, value?.date]);

  const free = (date: string) => months[date.slice(0, 7)]?.[date];
  const today = new Date();
  const thisMonth = monthKey(today);
  const last = new Date(today.getFullYear(), today.getMonth(), today.getDate() + booking.max_days_ahead);
  const cells = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7; // Monday first
    const count = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
    return [...Array(offset).fill(null), ...Array.from({ length: count }, (_, i) => iso(new Date(cursor.getFullYear(), cursor.getMonth(), i + 1)))];
  }, [cursor]);

  // Days/nights: the whole range needs a free unit for the chosen quantity.
  const daysFrom = (start: string) => {
    let max = 0;
    for (let n = 0; n < booking.max_days; n++) {
      const f = free(addDays(start, n));
      if (f === undefined || f < units) break;
      max = n + 1;
    }
    return max;
  };
  const maxDays = daily && value?.start_date ? daysFrom(value.start_date) : 0;
  const end = daily && value?.start_date && value.days ? addDays(value.start_date, value.days) : null;
  const inRange = (date: string) => Boolean(daily && value?.start_date && end && date >= value.start_date && date < end);

  const pick = (date: string) => {
    if (daily) {
      const room = daysFrom(date);
      onChange({ start_date: date, days: Math.max(booking.min_days, Math.min(value?.days ?? booking.min_days, room)) });
    } else {
      setDay(null);
      onChange({ date, slots: value?.slots ?? 1 });
    }
  };

  const slotsLeft = (start: string) => {
    if (!day) return 0;
    const index = day.slots.findIndex((s) => s.start === start);
    let n = 0;
    for (let i = index; i >= 0 && i < day.slots.length && n < booking.max_slots; i++) {
      if (day.slots[i].free < units || (i > index && day.slots[i - 1].end !== day.slots[i].start)) break;
      n++;
    }
    return n;
  };

  return (
    <section className="space-y-3 rounded-3xl bg-white p-4 ring-1 ring-zinc-100" data-booking-picker aria-label="Pilih jadwal">
      <h2 className="text-sm font-bold">{daily ? `Pilih tanggal sewa` : 'Pilih tanggal & jam'}</h2>
      <div className="flex items-center justify-between">
        <button type="button" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} disabled={month <= thisMonth}
          aria-label="Bulan sebelumnya" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100 disabled:opacity-30"><ChevronLeft className="h-5 w-5" /></button>
        <p className="text-sm font-semibold capitalize">{cursor.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}</p>
        <button type="button" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} disabled={new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1) > last}
          aria-label="Bulan berikutnya" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100 disabled:opacity-30"><ChevronRight className="h-5 w-5" /></button>
      </div>
      <div className="grid grid-cols-7 gap-1 text-center" role="grid">
        {WEEK.map((w) => <span key={w} className="py-1 text-xs font-medium text-zinc-500">{w}</span>)}
        {cells.map((date, i) => {
          if (!date) return <span key={`e${i}`} />;
          const f = free(date);
          const loading = f === undefined;
          const ok = !loading && f >= units;
          const active = date === selected || inRange(date);
          return (
            <button key={date} type="button" disabled={!ok} onClick={() => pick(date)} data-date={date} aria-pressed={active}
              aria-label={`${longDate(date)}${ok ? '' : ' — penuh/tutup'}`}
              className={cn('flex aspect-square min-h-11 flex-col items-center justify-center rounded-xl text-sm',
                active ? 'bg-zinc-900 font-bold text-white' : ok ? 'font-semibold text-zinc-900 hover:bg-zinc-100' : 'text-zinc-300 line-through',
                date === selected && 'ring-2 ring-amber-400')}>
              {Number(date.slice(8))}
              {ok && booking.units > 1 && !active && <span className="text-[10px] font-normal text-emerald-700">{f}</span>}
            </button>
          );
        })}
      </div>
      {!months[month] && <p className="flex items-center gap-2 text-xs text-zinc-500"><Loader2 className="h-3.5 w-3.5 animate-spin" /> Mengecek jadwal kosong…</p>}
      <p className="text-xs text-zinc-500">Tanggal yang dicoret sudah penuh atau tutup.{booking.units > 1 ? ' Angka hijau = unit yang masih tersedia.' : ''}</p>

      {daily && value?.start_date && (
        <label className="block text-sm font-medium">Lama sewa
          <select value={value.days ?? booking.min_days} onChange={(e) => onChange({ ...value, days: Number(e.target.value) })}
            className="mt-1 min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base">
            {Array.from({ length: Math.max(0, maxDays - booking.min_days + 1) }, (_, i) => booking.min_days + i).map((n) => (
              <option key={n} value={n}>{n} {booking.unit_label} · selesai {longDate(addDays(value.start_date as string, n))}</option>
            ))}
          </select>
          {maxDays < booking.min_days && <span className="mt-1 block text-sm text-rose-600">Tanggal ini tidak cukup untuk minimal {booking.min_days} {booking.unit_label}. Pilih tanggal lain.</span>}
        </label>
      )}

      {!daily && value?.date && (
        <div className="space-y-2">
          <p className="text-sm font-medium">Jam mulai · {longDate(value.date)}</p>
          {loadingDay && <p className="flex items-center gap-2 text-xs text-zinc-500"><Loader2 className="h-3.5 w-3.5 animate-spin" /> Memuat jam…</p>}
          {day && (day.slots.length === 0 ? <p className="text-sm text-zinc-500">Tutup di hari ini.</p> : (
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Jam mulai">
              {day.slots.map((slot) => {
                const ok = slot.free >= units;
                const active = value.start_time === slot.start;
                return (
                  <button key={slot.start} type="button" role="radio" aria-checked={active} disabled={!ok} data-slot={slot.start}
                    onClick={() => onChange({ ...value, start_time: slot.start, slots: Math.min(value.slots ?? 1, slotsLeft(slot.start)) || 1 })}
                    className={cn('min-h-11 rounded-xl border text-sm font-semibold', active ? 'border-zinc-900 bg-zinc-900 text-white' : ok ? 'border-zinc-200 hover:border-zinc-900' : 'border-zinc-100 text-zinc-300 line-through')}>
                    {slot.start.replace(':', '.')}
                  </button>
                );
              })}
            </div>
          ))}
          {value.start_time && booking.max_slots > 1 && (
            <label className="block text-sm font-medium">Jumlah sesi ({booking.slot_minutes} menit per sesi)
              <select value={value.slots ?? 1} onChange={(e) => onChange({ ...value, slots: Number(e.target.value) })} className="mt-1 min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base">
                {Array.from({ length: Math.max(1, slotsLeft(value.start_time)) }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n} sesi ({(n * booking.slot_minutes) / 60} jam)</option>)}
              </select>
            </label>
          )}
        </div>
      )}
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700" data-booking-error>{error}</p>}
    </section>
  );
}
