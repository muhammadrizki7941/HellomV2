import { useEffect, useMemo, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight, MessageCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getSellerBookings } from '@/lib/hellomApi';
import type { SellerBooking } from '@/lib/hellomApi';

/**
 * Jadwal tab: what is booked in the next two weeks (rental products). Paid bookings are locked;
 * "Menunggu bayar" ones hold the time until the buyer's payment window ends.
 */
const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d: Date, n: number) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const dayTitle = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
const waLink = (phone: string) => `https://wa.me/${phone.replace(/\D/g, '').replace(/^0/, '62')}`;
const SPAN = 14;

export default function SchedulePanel() {
  const [start, setStart] = useState(() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); });
  const [items, setItems] = useState<SellerBooking[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const from = iso(start);
  const to = iso(addDays(start, SPAN - 1));

  useEffect(() => {
    let alive = true;
    setItems(null);
    getSellerBookings(from, to).then((r) => alive && setItems(r.items)).catch((e) => alive && setError(e instanceof Error ? e.message : 'Jadwal belum bisa dimuat'));
    return () => { alive = false; };
  }, [from, to]);

  // Grouped by the day each booking starts (multi-day rentals appear on their first day).
  const groups = useMemo(() => {
    const map = new Map<string, SellerBooking[]>();
    for (const b of items ?? []) {
      const day = b.starts_at.slice(0, 10) < from ? from : b.starts_at.slice(0, 10);
      map.set(day, [...(map.get(day) ?? []), b]);
    }
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b));
  }, [items, from]);

  return (
    <div className="mx-auto max-w-3xl space-y-4" data-schedule>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Jadwal</h1>
          <p className="text-sm text-zinc-600">Booking produk sewa. Yang sudah lunas terkunci; yang menunggu bayar ditahan sampai batas waktu bayar.</p>
        </div>
        <div className="flex items-center gap-1">
          <button type="button" onClick={() => setStart(addDays(start, -SPAN))} aria-label="Dua minggu sebelumnya" className="flex h-11 w-11 items-center justify-center rounded-xl border bg-white"><ChevronLeft className="h-5 w-5" /></button>
          <button type="button" onClick={() => { const d = new Date(); setStart(new Date(d.getFullYear(), d.getMonth(), d.getDate())); }} className="min-h-11 rounded-xl border bg-white px-3 text-sm font-semibold">Hari ini</button>
          <button type="button" onClick={() => setStart(addDays(start, SPAN))} aria-label="Dua minggu berikutnya" className="flex h-11 w-11 items-center justify-center rounded-xl border bg-white"><ChevronRight className="h-5 w-5" /></button>
        </div>
      </div>
      <p className="text-sm font-medium text-zinc-700">{dayTitle(from)} – {dayTitle(to)}</p>

      {error && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {!items && !error && <div className="h-40 animate-pulse rounded-2xl bg-zinc-100" aria-busy="true" />}
      {items && items.length === 0 && (
        <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-600">
          <CalendarDays className="mx-auto mb-2 h-8 w-8 text-zinc-400" />
          Belum ada booking di rentang ini. Buat produk jenis <strong>Sewa / booking jadwal</strong> di tab Produk, lalu pasang di halaman kamu.
        </div>
      )}

      {groups.map(([day, list]) => (
        <section key={day} className="space-y-2" data-day={day}>
          <h2 className="text-sm font-bold capitalize text-zinc-900">{dayTitle(day)}</h2>
          <ul className="space-y-2">
            {list.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4" data-booking={b.id}>
                <div className="min-w-0">
                  <p className="font-semibold text-zinc-900">{b.product}{b.units > 1 ? ` · ${b.units} unit` : ''}</p>
                  <p className="text-sm text-zinc-700">{b.label}</p>
                  <p className="text-sm text-zinc-500">{b.buyer_name ?? '-'}{b.order_reference ? ` · ${b.order_reference}` : ''}</p>
                </div>
                <div className="flex items-center gap-2">
                  <span className={cn('rounded-full px-2.5 py-1 text-xs font-semibold', b.status === 'confirmed' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800')}>
                    {b.status === 'confirmed' ? 'Lunas' : 'Menunggu bayar'}
                  </span>
                  {b.buyer_phone && (
                    <a href={waLink(b.buyer_phone)} target="_blank" rel="noopener noreferrer" aria-label={`WhatsApp ${b.buyer_name ?? 'pembeli'}`}
                      className="flex h-11 w-11 items-center justify-center rounded-xl border border-emerald-200 text-emerald-700 hover:bg-emerald-50"><MessageCircle className="h-5 w-5" /></a>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
