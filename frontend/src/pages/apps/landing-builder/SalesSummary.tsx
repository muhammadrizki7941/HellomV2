import { useEffect, useState } from 'react';
import { AlertCircle, ArrowRight, Package, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getSellerSalesSummary } from '@/lib/hellomApi';
import type { SellerSalesSummary } from '@/lib/hellomApi';

// Overview numbers for the seller: real sales data only (no made-up trends).
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const shortRupiah = (value: number) =>
  value >= 1_000_000 ? `Rp ${(value / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt` : value >= 1000 ? `Rp ${Math.round(value / 1000)} rb` : rupiah(value);
const dayLabel = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });

export default function SalesSummary({ visitors, onOpenOrders, onOpenProducts }: { visitors: number; onOpenOrders: () => void; onOpenProducts: () => void }) {
  const [data, setData] = useState<SellerSalesSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [hover, setHover] = useState<number | null>(null);

  useEffect(() => {
    getSellerSalesSummary().then(setData).catch((err) => setError(err instanceof Error ? err.message : 'Ringkasan penjualan belum bisa dimuat'));
  }, []);

  if (error) {
    return <p className="flex items-center gap-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-500"><AlertCircle className="h-4 w-4" /> {error}</p>;
  }
  if (!data) {
    return (
      <div className="space-y-4" aria-busy="true">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[0, 1, 2, 3].map((i) => <div key={i} className="h-24 animate-pulse rounded-xl bg-zinc-100" />)}</div>
        <div className="h-64 animate-pulse rounded-xl bg-zinc-100" />
      </div>
    );
  }

  const max = Math.max(1, ...data.chart.map((d) => d.revenue));
  const hasSales = data.chart.some((d) => d.revenue > 0);
  const tiles = [
    { label: 'Penjualan hari ini', value: rupiah(data.today.revenue), hint: `${data.today.orders} pesanan` },
    { label: 'Penjualan bulan ini', value: rupiah(data.month.revenue), hint: `${data.month.orders} pesanan · bersih ${rupiah(data.month.net)}` },
    { label: 'Saldo tersedia', value: rupiah(data.balance_available), hint: 'bisa ditarik' },
    { label: 'Total pengunjung', value: visitors.toLocaleString('id-ID'), hint: 'semua halaman' },
  ];

  return (
    <div className="space-y-4">
      {data.products === 0 && (
        <button type="button" onClick={onOpenProducts} className="flex min-h-14 w-full items-center gap-3 rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-left text-sm">
          <Package className="h-5 w-5 shrink-0 text-yellow-700" />
          <span className="flex-1"><span className="font-semibold">Tambahkan produk pertama kamu</span> — e-book, kelas, file, barang, atau jasa. Link checkout-nya langsung bisa dibagikan.</span>
          <ArrowRight className="h-4 w-4" />
        </button>
      )}
      {data.to_process > 0 && (
        <button type="button" onClick={onOpenOrders} className="flex min-h-14 w-full items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-left text-sm">
          <Package className="h-5 w-5 shrink-0 text-amber-700" />
          <span className="flex-1"><span className="font-semibold">{data.to_process} pesanan perlu diproses</span> (kirim barang / hubungi pembeli)</span>
          <ArrowRight className="h-4 w-4" />
        </button>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {tiles.map((t) => (
          <div key={t.label} className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
            <p className="text-xs font-medium text-zinc-500">{t.label}</p>
            <p className="mt-1 text-xl font-bold text-zinc-900 md:text-2xl">{t.value}</p>
            <p className="mt-0.5 text-xs text-zinc-400">{t.hint}</p>
          </div>
        ))}
      </div>

      <div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm md:p-6">
        <div className="flex items-baseline justify-between">
          <h3 className="text-base font-bold text-zinc-900">Penjualan 30 hari terakhir</h3>
          <span className="text-xs text-zinc-500">{rupiah(data.chart.reduce((s, d) => s + d.revenue, 0))}</span>
        </div>
        {hasSales ? (
          <>
            <div className="relative mt-6 flex h-48 items-end gap-[2px] border-b border-zinc-200" role="img" aria-label="Grafik penjualan harian 30 hari terakhir">
              {data.chart.map((d, i) => (
                <div
                  key={d.date}
                  className="relative flex h-full flex-1 items-end"
                  onMouseEnter={() => setHover(i)}
                  onMouseLeave={() => setHover(null)}
                  onClick={() => setHover(hover === i ? null : i)}
                >
                  <div
                    className={cn('w-full rounded-t-[4px] transition-colors', hover === i ? 'bg-yellow-500' : 'bg-yellow-400')}
                    style={{ height: d.revenue > 0 ? `${Math.max(3, (d.revenue / max) * 100)}%` : '0%' }}
                  />
                  {hover === i && (
                    <div className={cn('pointer-events-none absolute bottom-full z-10 mb-2 whitespace-nowrap rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs text-white shadow', i > 20 ? 'right-0' : i < 8 ? 'left-0' : 'left-1/2 -translate-x-1/2')}>
                      <p className="font-semibold">{dayLabel(d.date)}</p>
                      <p>{rupiah(d.revenue)} · {d.orders} pesanan</p>
                    </div>
                  )}
                </div>
              ))}
            </div>
            <div className="mt-2 flex justify-between text-xs text-zinc-400">
              <span>{dayLabel(data.chart[0].date)}</span>
              <span>{dayLabel(data.chart[14].date)}</span>
              <span>{dayLabel(data.chart[data.chart.length - 1].date)}</span>
            </div>
            <p className="mt-1 text-right text-xs text-zinc-400">tertinggi {shortRupiah(max)}/hari</p>
            <table className="sr-only">
              <caption>Penjualan harian</caption>
              <thead><tr><th>Tanggal</th><th>Pesanan</th><th>Penjualan</th></tr></thead>
              <tbody>{data.chart.map((d) => <tr key={d.date}><td>{dayLabel(d.date)}</td><td>{d.orders}</td><td>{rupiah(d.revenue)}</td></tr>)}</tbody>
            </table>
          </>
        ) : (
          <p className="mt-6 rounded-lg bg-zinc-50 p-6 text-center text-sm text-zinc-500">Belum ada penjualan 30 hari terakhir. Bagikan link halaman atau link checkout produk kamu.</p>
        )}
      </div>

      {data.recent.length > 0 && (
        <div className="rounded-xl border border-zinc-200 bg-white shadow-sm">
          <div className="flex items-center justify-between p-4">
            <h3 className="text-base font-bold text-zinc-900">Pesanan terbaru</h3>
            <button type="button" onClick={onOpenOrders} className="flex min-h-11 items-center gap-1 text-sm font-semibold text-zinc-700">Semua <ArrowRight className="h-4 w-4" /></button>
          </div>
          <ul className="divide-y divide-zinc-100">
            {data.recent.map((o) => (
              <li key={o.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <div className="min-w-0">
                  <p className="truncate font-medium text-zinc-900">{o.product_name}</p>
                  <p className="flex items-center gap-1 truncate text-xs text-zinc-500"><Users className="h-3 w-3" /> {o.buyer_name || o.buyer_email}</p>
                </div>
                <div className="shrink-0 text-right">
                  <p className="font-semibold">{rupiah(o.amount)}</p>
                  <p className={cn('text-xs', o.needs_action ? 'text-amber-700' : 'text-zinc-400')}>{o.needs_action ? 'Perlu diproses' : o.status_label}</p>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
