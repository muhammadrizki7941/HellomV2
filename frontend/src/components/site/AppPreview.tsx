import type { HellomApp } from '@/data/apps';

/** Lightweight UI mock used until real screenshots are added to data/apps.ts. */
export default function AppPreview({ kind }: { kind: HellomApp['preview'] }) {
  if (kind === 'pos') {
    const orders = [
      { no: 'A-102', table: 'Meja 4', total: 'Rp 86.000', status: 'Diproses' },
      { no: 'A-103', table: 'Bawa pulang', total: 'Rp 42.500', status: 'Baru' },
      { no: 'A-104', table: 'Meja 1', total: 'Rp 128.000', status: 'Selesai' },
    ];
    return (
      <div className="rounded-2xl border border-white/[0.10] bg-[#0E0E11] p-5" aria-label="Contoh tampilan daftar pesanan">
        <div className="grid grid-cols-3 gap-3">
          {[['Omzet hari ini', 'Rp 2,4 jt'], ['Pesanan', '38'], ['Rata-rata', 'Rp 63 rb']].map(([label, value]) => (
            <div key={label} className="rounded-xl bg-white/[0.04] p-3">
              <p className="text-[11px] text-[#A1A1A6]">{label}</p>
              <p className="mt-1 text-sm font-bold">{value}</p>
            </div>
          ))}
        </div>
        <ul className="mt-4 space-y-2">
          {orders.map((order) => (
            <li key={order.no} className="flex items-center justify-between rounded-xl border border-white/[0.06] px-4 py-3 text-sm">
              <span className="font-semibold">{order.no}</span>
              <span className="text-[#A1A1A6]">{order.table}</span>
              <span>{order.total}</span>
              <span className="rounded-full bg-[#F6B400]/15 px-2 py-0.5 text-xs text-[#F6B400]">{order.status}</span>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  return (
    <div className="rounded-2xl border border-white/[0.10] bg-[#0E0E11] p-5" aria-label="Contoh tampilan editor halaman">
      <div className="grid grid-cols-[120px_1fr] gap-4">
        <ul className="space-y-2">
          {['Judul', 'Gambar', 'Testimoni', 'FAQ', 'Form pesan'].map((block) => (
            <li key={block} className="rounded-lg bg-white/[0.04] px-3 py-2 text-xs text-[#D6D6D8]">{block}</li>
          ))}
        </ul>
        <div className="space-y-3 rounded-xl bg-white/[0.03] p-4">
          <div className="h-4 w-3/4 rounded bg-white/[0.14]" />
          <div className="h-3 w-1/2 rounded bg-white/[0.08]" />
          <div className="aspect-[16/7] rounded-lg bg-[radial-gradient(circle_at_40%_30%,rgba(246,180,0,.25),transparent_45%),#15151a]" />
          <div className="h-9 w-32 rounded-lg bg-[#F6B400]" />
        </div>
      </div>
    </div>
  );
}
