import { useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Clock3, Loader2, Mail, XCircle } from 'lucide-react';
import { getLandingOrderPublicStatus, reportLandingOrderReturn } from '@/lib/hellomApi';
import type { LandingOrderStatus } from '@/lib/hellomApi';

// Buyer lands here after the payment page. The page only watches the order: it is marked
// paid by the gateway webhook / server check, never from here.
const POLL_MS = 4000;
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;

export default function OrderStatusPage() {
  const { reference = '' } = useParams();
  const [searchParams] = useSearchParams();
  const [order, setOrder] = useState<LandingOrderStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const reported = useRef(false);

  useEffect(() => {
    document.title = 'Status pesanan · Hellom';
    const meta = document.createElement('meta');
    meta.name = 'robots';
    meta.content = 'noindex';
    document.head.appendChild(meta);
    return () => { document.head.removeChild(meta); };
  }, []);

  useEffect(() => {
    if (!reference || reported.current) return;
    reported.current = true;
    // iPaymu adds trx_id (or sid) to the return URL: a hint for the server-side check.
    const trx = searchParams.get('trx_id') || searchParams.get('transaction_id');
    reportLandingOrderReturn(reference, trx).catch(() => undefined);
  }, [reference, searchParams]);

  useEffect(() => {
    if (!reference) return undefined;
    let stopped = false;
    let timer: number | undefined;
    const load = async () => {
      try {
        const data = await getLandingOrderPublicStatus(reference);
        if (stopped) return;
        setOrder(data);
        setError(null);
        if (data.status === 'pending') timer = window.setTimeout(load, POLL_MS);
      } catch (err) {
        if (stopped) return;
        setError(err instanceof Error ? err.message : 'Pesanan tidak ditemukan');
        timer = window.setTimeout(load, POLL_MS * 2);
      }
    };
    void load();
    return () => {
      stopped = true;
      if (timer) window.clearTimeout(timer);
    };
  }, [reference]);

  const paid = order?.status === 'paid' || order?.status === 'fulfilled';
  const closed = order?.status === 'expired' || order?.status === 'failed';

  return (
    <main className="min-h-[100svh] bg-zinc-50 px-4 py-10 text-zinc-900">
      <div className="mx-auto max-w-md">
        <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-zinc-100">
          {!order && !error && (
            <div className="flex flex-col items-center py-10 text-center" aria-busy="true">
              <Loader2 className="h-8 w-8 animate-spin text-zinc-400" />
              <p className="mt-4 text-sm text-zinc-500">Mengecek status pembayaran…</p>
            </div>
          )}

          {!order && error && (
            <div className="py-8 text-center">
              <XCircle className="mx-auto h-10 w-10 text-zinc-400" />
              <p className="mt-3 font-semibold">Pesanan tidak ditemukan</p>
              <p className="mt-1 text-sm text-zinc-500">Cek lagi tautan dari email atau halaman pembayaran kamu.</p>
            </div>
          )}

          {order && (
            <>
              <div className="flex flex-col items-center text-center">
                {paid ? (
                  <CheckCircle2 className="h-14 w-14 text-emerald-500" />
                ) : closed ? (
                  <XCircle className="h-14 w-14 text-rose-500" />
                ) : (
                  <span className="relative flex h-14 w-14 items-center justify-center">
                    <span className="absolute inset-0 animate-ping rounded-full bg-amber-200 opacity-60" />
                    <Clock3 className="relative h-10 w-10 text-amber-500" />
                  </span>
                )}
                <h1 className="mt-4 text-xl font-bold">
                  {paid ? 'Pembayaran berhasil 🎉' : closed ? (order.status === 'expired' ? 'Waktu pembayaran habis' : 'Pembayaran gagal') : 'Menunggu pembayaran'}
                </h1>
                <p className="mt-2 text-sm leading-6 text-zinc-500">
                  {paid
                    ? `Bukti pembelian dan akses produk sudah dikirim ke ${order.buyer_email_masked ?? 'email kamu'}.`
                    : closed
                      ? 'Pesanan ini tidak bisa dibayar lagi. Silakan pesan ulang dari halaman penjual.'
                      : 'Selesaikan pembayaran di halaman pembayaran. Halaman ini akan berubah otomatis begitu pembayaran masuk.'}
                </p>
              </div>

              <dl className="mt-6 space-y-3 rounded-2xl bg-zinc-50 p-4 text-sm">
                <div className="flex justify-between gap-3"><dt className="text-zinc-500">Produk</dt><dd className="text-right font-medium">{order.product_name}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-zinc-500">Total</dt><dd className="font-semibold">{rupiah(order.amount)}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-zinc-500">No. pesanan</dt><dd className="font-mono text-xs">{reference}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-zinc-500">Status</dt><dd className="font-medium">{order.status_label}</dd></div>
              </dl>

              {paid && order.access_path && (
                <Link
                  to={order.access_path}
                  className="mt-6 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 px-4 text-base font-semibold text-white"
                >
                  {order.has_file ? 'Buka produk' : 'Lihat detail pesanan'} <ArrowRight className="h-5 w-5" />
                </Link>
              )}
              {paid && (
                <p className="mt-4 flex items-start gap-2 text-xs leading-5 text-zinc-500">
                  <Mail className="mt-0.5 h-4 w-4 shrink-0" />
                  Tidak ada email masuk? Cek folder Spam/Promosi. Simpan nomor pesanan di atas sebagai bukti.
                </p>
              )}
              {error && <p className="mt-4 text-center text-xs text-rose-600">{error}</p>}
            </>
          )}
        </div>
        <p className="mt-6 text-center text-xs text-zinc-400">Pembayaran diproses aman oleh Hellom</p>
      </div>
    </main>
  );
}
