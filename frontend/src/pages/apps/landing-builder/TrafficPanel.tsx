import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { getLandingTraffic } from '@/lib/hellomApi';
import type { LandingTrafficReport } from '@/lib/hellomApi';

// Statistik tab (Fase 4): visits, sources, button clicks, product conversion, sales by source.
// Light numbers from daily counters (no cookies); one visit per visitor per page per day.
const rupiah = (v: number) => `Rp ${Math.round(v || 0).toLocaleString('id-ID')}`;
const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });

export default function TrafficPanel() {
  const [days, setDays] = useState<7 | 30 | 90>(30);
  const [data, setData] = useState<LandingTrafficReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [hover, setHover] = useState<number | null>(null);

  useEffect(() => {
    setData(null);
    getLandingTraffic(days).then(setData).catch((e) => setError(e instanceof Error ? e.message : 'Statistik belum bisa dimuat'));
  }, [days]);

  const max = Math.max(1, ...(data?.daily ?? []).map((d) => d.visits));

  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Statistik</h1>
          <p className="text-sm text-zinc-600">Kunjungan, sumber trafik, klik tombol, dan konversi produk.</p>
        </div>
        <div className="inline-flex rounded-xl bg-zinc-100 p-1">
          {([7, 30, 90] as const).map((d) => (
            <button key={d} type="button" onClick={() => setDays(d)} className={cn('min-h-11 rounded-lg px-3 text-sm font-semibold', days === d ? 'bg-white shadow-sm' : 'text-zinc-500')}>{d} hari</button>
          ))}
        </div>
      </div>
      {error && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {!data && !error && <div className="h-64 animate-pulse rounded-2xl bg-zinc-100" aria-busy="true" />}
      {data && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[['Kunjungan', data.totals.visits], ['Lihat produk', data.totals.product_views], ['Klik tombol', data.totals.clicks], ['Mulai checkout', data.totals.checkout_starts]].map(([label, value]) => (
              <div key={String(label)} className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-medium text-zinc-500">{label}</p>
                <p className="mt-1 text-2xl font-bold text-zinc-900">{Number(value).toLocaleString('id-ID')}</p>
              </div>
            ))}
          </div>

          <section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
            <h2 className="text-base font-bold">Kunjungan harian</h2>
            {data.totals.visits === 0 ? (
              <p className="mt-4 rounded-lg bg-zinc-50 p-6 text-center text-sm text-zinc-500">Belum ada kunjungan. Bagikan link halaman kamu di bio & iklan.</p>
            ) : (
              <>
                <div className="relative mt-5 flex h-40 items-end gap-[2px] border-b border-zinc-200" role="img" aria-label="Grafik kunjungan harian">
                  {data.daily.map((d, i) => (
                    <div key={d.date} className="relative flex h-full flex-1 items-end" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} onClick={() => setHover(hover === i ? null : i)}>
                      <div className={cn('w-full rounded-t-[4px]', hover === i ? 'bg-sky-600' : 'bg-sky-500')} style={{ height: d.visits ? `${Math.max(3, (d.visits / max) * 100)}%` : '0%' }} />
                      {hover === i && <div className={cn('pointer-events-none absolute bottom-full z-10 mb-2 whitespace-nowrap rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs text-white', i > data.daily.length * 0.7 ? 'right-0' : i < data.daily.length * 0.3 ? 'left-0' : 'left-1/2 -translate-x-1/2')}>{day(d.date)} · {d.visits} kunjungan</div>}
                    </div>
                  ))}
                </div>
                <div className="mt-2 flex justify-between text-xs text-zinc-400"><span>{day(data.daily[0].date)}</span><span>{day(data.daily[data.daily.length - 1].date)}</span></div>
                <table className="sr-only"><caption>Kunjungan harian</caption><tbody>{data.daily.map((d) => <tr key={d.date}><td>{day(d.date)}</td><td>{d.visits}</td></tr>)}</tbody></table>
              </>
            )}
          </section>

          <div className="grid gap-4 lg:grid-cols-2">
            <ListCard title="Sumber kunjungan" empty="Belum ada data" rows={data.sources.map((s) => [s.source, `${s.visits.toLocaleString('id-ID')} kunjungan`])} />
            <ListCard title="Tombol paling sering diklik" empty="Belum ada klik" rows={data.clicks.map((c) => [c.label, `${c.clicks.toLocaleString('id-ID')} klik`])} />
            <ListCard title="Penjualan per sumber" empty="Belum ada penjualan" rows={data.sales_by_source.map((s) => [s.source, `${s.orders} pesanan · ${rupiah(s.revenue)}`])} />
            <section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
              <h2 className="text-base font-bold">Konversi per produk</h2>
              {data.products.length === 0 ? <p className="mt-3 text-sm text-zinc-500">Belum ada data.</p> : (
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="text-left text-xs text-zinc-500"><tr><th className="py-1 pr-2">Produk</th><th className="py-1 pr-2 text-right">Dilihat</th><th className="py-1 pr-2 text-right">Checkout</th><th className="py-1 pr-2 text-right">Terjual</th><th className="py-1 text-right">Konversi</th></tr></thead>
                    <tbody className="divide-y divide-zinc-100">
                      {data.products.map((p) => (
                        <tr key={p.product_id}><td className="max-w-[10rem] truncate py-2 pr-2">{p.name}</td><td className="py-2 pr-2 text-right">{p.views}</td><td className="py-2 pr-2 text-right">{p.checkout_starts}</td><td className="py-2 pr-2 text-right">{p.orders}</td><td className="py-2 text-right">{p.conversion === null ? '–' : `${p.conversion}%`}</td></tr>
                      ))}
                    </tbody>
                  </table>
                  <p className="mt-2 text-xs text-zinc-400">Konversi = terjual ÷ mulai checkout.</p>
                </div>
              )}
            </section>
          </div>
        </>
      )}
    </div>
  );
}

function ListCard({ title, rows, empty }: { title: string; rows: Array<[string, string]>; empty: string }) {
  return (
    <section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
      <h2 className="text-base font-bold">{title}</h2>
      {rows.length === 0 ? <p className="mt-3 text-sm text-zinc-500">{empty}</p> : (
        <ul className="mt-3 divide-y divide-zinc-100 text-sm">
          {rows.map(([label, value]) => <li key={label} className="flex justify-between gap-3 py-2"><span className="truncate capitalize">{label}</span><span className="shrink-0 text-zinc-600">{value}</span></li>)}
        </ul>
      )}
    </section>
  );
}
