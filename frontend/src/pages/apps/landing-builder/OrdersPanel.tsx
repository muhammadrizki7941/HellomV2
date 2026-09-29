import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Download, Loader2, Mail, RotateCcw, Search, Truck, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  exportSellerBuyers,
  exportSellerOrders,
  fulfillSellerOrder,
  getSellerBuyers,
  getSellerOrder,
  getSellerOrders,
  refundSellerOrder,
  resendSellerOrderEmail,
} from '@/lib/hellomApi';
import type { OrderFilters, SellerBuyer, SellerOrderDetail, SellerOrderRow } from '@/lib/hellomApi';

// Pesanan tab: orders (filter, search, detail, ship, refund, resend) and buyers, with Excel export.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-');
const inputClass = 'min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none focus:border-zinc-900';

const STATUS_FILTERS: Array<[NonNullable<OrderFilters['status']>, string]> = [
  ['all', 'Semua'], ['to_process', 'Perlu diproses'], ['paid', 'Lunas'], ['pending', 'Menunggu bayar'], ['refunded', 'Refund'], ['expired', 'Kedaluwarsa'],
];
const STATUS_TONE: Record<string, string> = {
  pending: 'bg-zinc-100 text-zinc-600',
  paid: 'bg-sky-100 text-sky-800',
  fulfilled: 'bg-emerald-100 text-emerald-800',
  refunded: 'bg-violet-100 text-violet-800',
  expired: 'bg-zinc-100 text-zinc-500',
  failed: 'bg-rose-100 text-rose-800',
};

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export default function OrdersPanel() {
  const [view, setView] = useState<'orders' | 'buyers'>('orders');
  return (
    <div className="mx-auto max-w-5xl space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Pesanan</h1>
        <p className="text-sm text-zinc-600">Semua pembelian dari halaman Hellom kamu.</p>
      </div>
      <div className="inline-flex rounded-xl bg-zinc-100 p-1">
        {([['orders', 'Pesanan'], ['buyers', 'Pembeli']] as const).map(([key, label]) => (
          <button key={key} type="button" onClick={() => setView(key)} className={cn('min-h-11 rounded-lg px-4 text-sm font-semibold', view === key ? 'bg-white shadow-sm' : 'text-zinc-500')}>{label}</button>
        ))}
      </div>
      {view === 'orders' ? <OrdersList /> : <BuyersList />}
    </div>
  );
}

function OrdersList() {
  const [filters, setFilters] = useState<OrderFilters>({ status: 'all' });
  const [search, setSearch] = useState('');
  const [rows, setRows] = useState<SellerOrderRow[] | null>(null);
  const [page, setPage] = useState({ current: 1, last: 1, total: 0 });
  const [error, setError] = useState<string | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const [exporting, setExporting] = useState(false);

  const load = useCallback((p = 1) => {
    getSellerOrders({ ...filters, page: p })
      .then((r) => { setRows(r.data); setPage({ current: r.current_page, last: r.last_page, total: r.total }); setError(null); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Pesanan belum bisa dimuat'));
  }, [filters]);

  useEffect(() => { load(1); }, [load]);

  const doExport = async () => {
    setExporting(true);
    try {
      saveBlob(await exportSellerOrders(filters), 'pesanan.xlsx');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Export gagal');
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="space-y-3">
      <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
        {STATUS_FILTERS.map(([key, label]) => (
          <button key={key} type="button" onClick={() => setFilters((f) => ({ ...f, status: key }))} className={cn('min-h-11 shrink-0 rounded-full px-4 text-sm font-semibold', filters.status === key ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-600 ring-1 ring-zinc-200')}>
            {label}
          </button>
        ))}
      </div>
      <div className="flex gap-2">
        <form className="relative flex-1" onSubmit={(e) => { e.preventDefault(); setFilters((f) => ({ ...f, q: search.trim() || undefined })); }}>
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari no. pesanan, nama, email, WA" className={cn(inputClass, 'pl-9')} />
        </form>
        <button type="button" onClick={() => void doExport()} disabled={exporting} aria-label="Export Excel" className="flex min-h-12 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-semibold disabled:opacity-50">
          {exporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}<span className="hidden sm:inline">Excel</span>
        </button>
      </div>

      {error && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {rows === null && !error && <div className="space-y-2" aria-busy="true">{[0, 1, 2].map((i) => <div key={i} className="h-20 animate-pulse rounded-2xl bg-zinc-100" />)}</div>}
      {rows?.length === 0 && <p className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">Belum ada pesanan di sini.</p>}

      <ul className="space-y-2">
        {rows?.map((o) => (
          <li key={o.id}>
            <button type="button" onClick={() => setOpenId(o.id)} className="flex w-full items-start justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm hover:border-zinc-300">
              <div className="min-w-0">
                <p className="truncate font-semibold text-zinc-900">{o.product_name}{o.quantity > 1 ? ` × ${o.quantity}` : ''}</p>
                <p className="truncate text-sm text-zinc-500">{o.buyer_name || '-'} · {o.buyer_email}</p>
                <p className="text-xs text-zinc-400">{o.reference} · {dateTime(o.paid_at ?? o.created_at)}</p>
              </div>
              <div className="shrink-0 text-right">
                <p className="font-bold">{rupiah(o.amount)}</p>
                <span className={cn('mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold', o.needs_action ? 'bg-amber-100 text-amber-800' : STATUS_TONE[o.status])}>
                  {o.needs_action ? 'Perlu diproses' : o.status_label}
                </span>
              </div>
            </button>
          </li>
        ))}
      </ul>
      {page.last > 1 && (
        <div className="flex items-center justify-center gap-3 text-sm">
          <button type="button" disabled={page.current <= 1} onClick={() => load(page.current - 1)} className="min-h-11 rounded-xl border px-4 disabled:opacity-40">Sebelumnya</button>
          <span>{page.current}/{page.last}</span>
          <button type="button" disabled={page.current >= page.last} onClick={() => load(page.current + 1)} className="min-h-11 rounded-xl border px-4 disabled:opacity-40">Berikutnya</button>
        </div>
      )}

      {openId !== null && <OrderSheet orderId={openId} onClose={() => setOpenId(null)} onChanged={() => load(page.current)} />}
    </div>
  );
}

function OrderSheet({ orderId, onClose, onChanged }: { orderId: number; onClose: () => void; onChanged: () => void }) {
  const [order, setOrder] = useState<SellerOrderDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [ship, setShip] = useState({ courier: '', tracking_number: '' });
  const [refunding, setRefunding] = useState(false);
  const [refund, setRefund] = useState({ amount: '', reason: '', destination_type: 'bank' as 'bank' | 'ewallet', bank_code: '', account_number: '', account_name: '' });

  useEffect(() => {
    getSellerOrder(orderId).then((o) => { setOrder(o); setRefund((r) => ({ ...r, amount: String(o.amount) })); }).catch((err) => setError(err instanceof Error ? err.message : 'Pesanan tidak ditemukan'));
  }, [orderId]);

  const act = async (fn: () => Promise<SellerOrderDetail | { sent: boolean }>, message: string) => {
    setBusy(true);
    setError(null);
    try {
      const result = await fn();
      if ('reference' in result) setOrder(result);
      setNotice(message);
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Aksi gagal');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/40" onClick={onClose}>
      <div className="flex h-full w-full max-w-lg flex-col bg-white" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Detail pesanan">
        <header className="flex items-center justify-between border-b border-zinc-200 px-4 py-2">
          <h2 className="text-lg font-bold">Detail pesanan</h2>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </header>
        <div className="flex-1 space-y-4 overflow-y-auto p-4">
          {!order && !error && <div className="h-64 animate-pulse rounded-2xl bg-zinc-100" />}
          {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
          {notice && <p role="status" className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
          {order && (
            <>
              <section>
                <span className={cn('inline-block rounded-full px-2 py-0.5 text-xs font-semibold', order.needs_action ? 'bg-amber-100 text-amber-800' : STATUS_TONE[order.status])}>{order.needs_action ? 'Perlu diproses' : order.status_label}</span>
                <h3 className="mt-2 text-lg font-bold">{order.product_name}{order.quantity > 1 ? ` × ${order.quantity}` : ''}</h3>
                <p className="font-mono text-xs text-zinc-500">{order.reference}</p>
                {order.oversold && <p className="mt-2 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /> Dibayar setelah stok habis. Hubungi pembeli: kirim barang atau refund.</p>}
              </section>

              <dl className="space-y-1.5 rounded-2xl bg-zinc-50 p-4 text-sm">
                <Row label="Pembeli" value={order.buyer_name ?? '-'} />
                <Row label="Email" value={order.buyer_email ?? '-'} />
                {order.buyer_phone && <Row label="WhatsApp" value={<a className="underline" href={`https://wa.me/${order.buyer_phone.replace(/\D/g, '').replace(/^0/, '62')}`} target="_blank" rel="noopener noreferrer">{order.buyer_phone}</a>} />}
                <Row label="Dipesan" value={dateTime(order.created_at)} />
                <Row label="Dibayar" value={dateTime(order.paid_at)} />
                {order.payment_method && <Row label="Metode" value={[order.payment_method, order.payment_channel].filter(Boolean).join(' · ')} />}
              </dl>

              <dl className="space-y-1.5 rounded-2xl bg-zinc-50 p-4 text-sm">
                <Row label="Harga" value={rupiah(order.subtotal_amount)} />
                {order.discount_amount > 0 && <Row label={`Diskon${order.coupon_code ? ` (${order.coupon_code})` : ''}`} value={`−${rupiah(order.discount_amount)}`} />}
                {order.shipping_amount > 0 && <Row label="Ongkir" value={rupiah(order.shipping_amount)} />}
                <Row label="Dibayar pembeli" value={rupiah(order.amount)} />
                <Row label="Biaya layanan Hellom" value={`−${rupiah(order.commission_amount)}`} />
                <Row label="Masuk saldo kamu" value={<span className="font-bold">{rupiah(order.net_amount)}</span>} />
              </dl>

              {order.shipping_address && (
                <section className="rounded-2xl border border-zinc-200 p-4 text-sm">
                  <p className="font-semibold">Alamat kirim</p>
                  <p className="mt-1 text-zinc-600">{order.shipping_address.recipient_name} ({order.shipping_address.phone})<br />{order.shipping_address.address}<br />{order.shipping_address.city} {order.shipping_address.province} {order.shipping_address.postal_code}</p>
                  {order.shipping_address.notes && <p className="mt-1 text-xs text-zinc-500">Catatan: {order.shipping_address.notes}</p>}
                  {order.tracking_number && <p className="mt-2 flex items-center gap-2 text-emerald-700"><Truck className="h-4 w-4" /> {order.shipping_courier} · {order.tracking_number}</p>}
                </section>
              )}

              {order.custom_fields.length > 0 && (
                <dl className="space-y-1.5 rounded-2xl border border-zinc-200 p-4 text-sm">
                  {order.custom_fields.map((f) => <div key={f.label}><dt className="text-zinc-500">{f.label}</dt><dd className="whitespace-pre-wrap">{f.value}</dd></div>)}
                </dl>
              )}

              {order.access && (
                <p className="text-xs text-zinc-500">
                  Akses: {order.access.downloads_max !== null || order.product_type === 'file' ? `diunduh ${order.access.downloads_used}${order.access.downloads_max ? `/${order.access.downloads_max}` : ''} kali` : `dibuka ${order.access.opens_used}${order.access.opens_max ? `/${order.access.opens_max}` : ''} kali`}
                  {order.access.last_opened_at ? ` · terakhir ${dateTime(order.access.last_opened_at)}` : ' · belum dibuka'}
                </p>
              )}

              {order.refunds.length > 0 && (
                <ul className="space-y-2 text-sm">
                  {order.refunds.map((r) => (
                    <li key={r.reference} className="rounded-2xl border border-violet-200 bg-violet-50 p-3">
                      Refund {rupiah(r.amount)} · <span className="font-semibold">{r.status_label}</span>
                      {r.failure_reason && <span className="block text-xs text-rose-700">{r.failure_reason}</span>}
                    </li>
                  ))}
                </ul>
              )}

              {/* Actions */}
              {order.needs_action && order.product_type === 'physical' && (
                <section className="space-y-2 rounded-2xl border border-zinc-200 p-4">
                  <p className="text-sm font-semibold">Tandai sudah dikirim</p>
                  <input value={ship.courier} onChange={(e) => setShip((s) => ({ ...s, courier: e.target.value }))} placeholder="Kurir (JNE, J&T, SiCepat…)" className={inputClass} />
                  <input value={ship.tracking_number} onChange={(e) => setShip((s) => ({ ...s, tracking_number: e.target.value }))} placeholder="Nomor resi" className={inputClass} />
                  <button type="button" disabled={busy || !ship.courier.trim() || !ship.tracking_number.trim()} onClick={() => void act(() => fulfillSellerOrder(order.id, ship), 'Pesanan ditandai terkirim, pembeli sudah diberi tahu.')} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-zinc-900 font-bold text-white disabled:opacity-40">
                    <Truck className="h-4 w-4" /> Simpan resi
                  </button>
                </section>
              )}
              {order.needs_action && order.product_type === 'service' && (
                <button type="button" disabled={busy} onClick={() => void act(() => fulfillSellerOrder(order.id), 'Pesanan ditandai selesai.')} className="flex min-h-12 w-full items-center justify-center rounded-xl bg-zinc-900 font-bold text-white disabled:opacity-40">
                  Tandai selesai
                </button>
              )}

              {(order.status === 'paid' || order.status === 'fulfilled') && (
                <button type="button" disabled={busy} onClick={() => void act(() => resendSellerOrderEmail(order.id), 'Email dikirim ulang ke pembeli.')} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border border-zinc-300 font-semibold disabled:opacity-40">
                  <Mail className="h-4 w-4" /> Kirim ulang email ke pembeli
                </button>
              )}

              {order.can_refund && !refunding && (
                <button type="button" onClick={() => setRefunding(true)} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border border-rose-200 font-semibold text-rose-700">
                  <RotateCcw className="h-4 w-4" /> Refund ke pembeli
                </button>
              )}
              {refunding && (
                <section className="space-y-2 rounded-2xl border border-rose-200 p-4 text-sm">
                  <p className="font-semibold">Refund ke pembeli</p>
                  <p className="text-xs text-zinc-500">Nominal langsung dipotong dari saldo tersedia kamu. Tim Hellom mentransfer ke rekening pembeli. Biaya layanan tidak dikembalikan.</p>
                  <label className="block font-medium">Nominal (Rp)<input inputMode="numeric" value={refund.amount} onChange={(e) => setRefund((r) => ({ ...r, amount: e.target.value.replace(/\D/g, '') }))} className={inputClass} /></label>
                  <label className="block font-medium">Alasan<input value={refund.reason} onChange={(e) => setRefund((r) => ({ ...r, reason: e.target.value }))} className={inputClass} placeholder="Contoh: stok habis, pembeli salah beli" /></label>
                  <div className="grid grid-cols-2 gap-2">
                    {(['bank', 'ewallet'] as const).map((t) => (
                      <button key={t} type="button" onClick={() => setRefund((r) => ({ ...r, destination_type: t }))} className={cn('min-h-11 rounded-xl border font-semibold', refund.destination_type === t ? 'border-zinc-900' : 'border-zinc-200 text-zinc-500')}>{t === 'bank' ? 'Bank' : 'E-wallet'}</button>
                    ))}
                  </div>
                  <label className="block font-medium">{refund.destination_type === 'bank' ? 'Bank' : 'E-wallet'}<input value={refund.bank_code} onChange={(e) => setRefund((r) => ({ ...r, bank_code: e.target.value.toUpperCase() }))} className={inputClass} placeholder={refund.destination_type === 'bank' ? 'BCA / BRI / Mandiri' : 'DANA / OVO / GOPAY'} /></label>
                  <label className="block font-medium">Nomor rekening / HP<input inputMode="numeric" value={refund.account_number} onChange={(e) => setRefund((r) => ({ ...r, account_number: e.target.value }))} className={inputClass} /></label>
                  <label className="block font-medium">Atas nama<input value={refund.account_name} onChange={(e) => setRefund((r) => ({ ...r, account_name: e.target.value }))} className={inputClass} /></label>
                  <div className="flex gap-2 pt-1">
                    <button type="button" onClick={() => setRefunding(false)} className="min-h-12 flex-1 rounded-xl border font-semibold">Batal</button>
                    <button
                      type="button"
                      disabled={busy || !refund.amount || refund.reason.trim().length < 5 || !refund.bank_code || !refund.account_number || !refund.account_name}
                      onClick={() => {
                        if (!window.confirm(`Refund ${rupiah(Number(refund.amount))}? Saldo kamu langsung dipotong.`)) return;
                        void act(() => refundSellerOrder(order.id, { ...refund, amount: Number(refund.amount) }), 'Refund diajukan. Pembeli diberi tahu lewat email.').then(() => setRefunding(false));
                      }}
                      className="min-h-12 flex-1 rounded-xl bg-rose-600 font-bold text-white disabled:opacity-40"
                    >
                      Ajukan refund
                    </button>
                  </div>
                </section>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return <div className="flex justify-between gap-3"><dt className="text-zinc-500">{label}</dt><dd className="text-right">{value}</dd></div>;
}

function BuyersList() {
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [rows, setRows] = useState<SellerBuyer[] | null>(null);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [error, setError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  const load = useCallback((p = 1) => {
    getSellerBuyers(query || undefined, p)
      .then((r) => { setRows(r.data); setPage({ current: r.current_page, last: r.last_page }); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Pembeli belum bisa dimuat'));
  }, [query]);

  useEffect(() => { load(1); }, [load]);

  return (
    <div className="space-y-3">
      <div className="flex gap-2">
        <form className="relative flex-1" onSubmit={(e) => { e.preventDefault(); setQuery(q.trim()); }}>
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama, email, WA" className={cn(inputClass, 'pl-9')} />
        </form>
        <button type="button" disabled={exporting} onClick={async () => { setExporting(true); try { saveBlob(await exportSellerBuyers(), 'pembeli.xlsx'); } catch (err) { setError(err instanceof Error ? err.message : 'Export gagal'); } setExporting(false); }} className="flex min-h-12 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-semibold disabled:opacity-50" aria-label="Export Excel">
          {exporting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}<span className="hidden sm:inline">Excel</span>
        </button>
      </div>
      {error && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      {rows?.length === 0 && <p className="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">Belum ada pembeli.</p>}
      <ul className="divide-y divide-zinc-100 rounded-2xl border border-zinc-200 bg-white">
        {rows?.map((b) => (
          <li key={b.email} className="flex items-center justify-between gap-3 p-4 text-sm">
            <div className="min-w-0">
              <p className="truncate font-semibold">{b.name || '-'}</p>
              <p className="truncate text-zinc-500">{b.email}{b.phone ? ` · ${b.phone}` : ''}</p>
            </div>
            <div className="shrink-0 text-right">
              <p className="font-semibold">{rupiah(b.total_spent)}</p>
              <p className="text-xs text-zinc-400">{b.orders} pesanan</p>
            </div>
          </li>
        ))}
      </ul>
      {page.last > 1 && (
        <div className="flex items-center justify-center gap-3 text-sm">
          <button type="button" disabled={page.current <= 1} onClick={() => load(page.current - 1)} className="min-h-11 rounded-xl border px-4 disabled:opacity-40">Sebelumnya</button>
          <span>{page.current}/{page.last}</span>
          <button type="button" disabled={page.current >= page.last} onClick={() => load(page.current + 1)} className="min-h-11 rounded-xl border px-4 disabled:opacity-40">Berikutnya</button>
        </div>
      )}
    </div>
  );
}
