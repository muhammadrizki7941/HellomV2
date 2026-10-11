import { useEffect, useState } from 'react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { getHppProductReport } from '@/lib/hellomApi';
import type { HppProductReport, HppProductReportRow } from '@/lib/hellomApi';
import { FoodCostBadge, MarginText, rupiah } from './shared';

// Laporan: HPP & margin per product, the most profitable and the ones that "makan modal",
// and the break-even point (portions per month / per day to cover fixed costs).

export default function ReportsTab() {
  const [report, setReport] = useState<HppProductReport | null>(null);
  useEffect(() => { getHppProductReport().then(setReport).catch(() => undefined); }, []);
  if (!report) return <p className="p-6 text-center text-sm text-zinc-500">Memuat…</p>;

  const list = (title: string, icon: React.ReactNode, rows: HppProductReportRow[], value: (r: HppProductReportRow) => React.ReactNode) => (
    <div className="rounded-2xl border border-zinc-200 bg-white">
      <p className="flex items-center gap-2 border-b border-zinc-100 px-4 py-3 font-semibold text-zinc-900">{icon} {title}</p>
      {rows.length === 0 ? <p className="p-4 text-sm text-zinc-500">Isi harga jual produk dulu.</p> : (
        <ol className="divide-y divide-zinc-100">
          {rows.map((r, i) => <li key={r.id} className="flex justify-between gap-2 px-4 py-2.5 text-sm"><span>{i + 1}. {r.name}</span>{value(r)}</li>)}
        </ol>
      )}
    </div>
  );

  return (
    <section className="space-y-4" data-hpp-reports>
      <div className="grid gap-4 md:grid-cols-2">
        {list('Paling untung', <TrendingUp className="h-5 w-5 text-emerald-600" />, report.most_profitable, (r) => <MarginText value={r.margin_pct} target={r.target_margin_pct} />)}
        {list('Paling "makan modal"', <TrendingDown className="h-5 w-5 text-red-600" />, report.most_costly, (r) => <FoodCostBadge status={r.food_cost_status} value={r.food_cost_pct} />)}
      </div>
      <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
        <table className="w-full min-w-[820px] text-left text-sm text-zinc-800" data-report-table>
          <thead className="bg-zinc-50 text-xs text-zinc-500">
            <tr>
              <th className="px-4 py-2 font-medium">Produk</th><th className="px-3 py-2 font-medium">HPP bahan</th><th className="px-3 py-2 font-medium">Overhead</th>
              <th className="px-3 py-2 font-medium">HPP penuh</th><th className="px-3 py-2 font-medium">Harga</th><th className="px-3 py-2 font-medium">Untung/unit</th>
              <th className="px-3 py-2 font-medium">Margin</th><th className="px-3 py-2 font-medium">BEP</th>
            </tr>
          </thead>
          <tbody>
            {report.products.map((r) => (
              <tr key={r.id} className="border-t border-zinc-100">
                <td className="px-4 py-2.5 font-medium text-zinc-900">{r.name}</td>
                <td className="px-3 py-2.5">{r.incomplete ? <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Lengkapi resep</span> : rupiah(r.cost.direct)}</td>
                <td className="px-3 py-2.5">{rupiah(r.cost.overhead)}</td>
                <td className="px-3 py-2.5 font-semibold">{rupiah(r.cost.full)}</td>
                <td className="px-3 py-2.5">{r.selling_price ? rupiah(r.selling_price) : '–'}</td>
                <td className="px-3 py-2.5">{rupiah(r.profit_per_unit)}</td>
                <td className="px-3 py-2.5"><MarginText value={r.margin_pct} target={r.target_margin_pct} /></td>
                <td className="px-3 py-2.5 text-zinc-600">{r.bep ? <>{r.bep.units_per_month.toLocaleString('id-ID')}/bln<span className="block text-xs">±{r.bep.units_per_day.toLocaleString('id-ID')}/hari</span></> : '–'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-zinc-500">
        BEP = biaya tetap {rupiah(report.overhead_total)}/bulan ÷ (harga − biaya bahan − fee channel utama): berapa porsi produk itu harus terjual sebulan supaya balik modal,
        kalau hanya produk itu yang dijual. Margin dihitung setelah fee channel utama. Biaya tetap dibagi {report.settings.overhead_method === 'per_unit' ? `rata per ${report.settings.monthly_units.toLocaleString('id-ID')} produk terjual/bulan` : report.settings.overhead_method === 'revenue_pct' ? 'sesuai persentase omzet' : 'per jam kerja'}.
      </p>
    </section>
  );
}
