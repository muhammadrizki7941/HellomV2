import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { CheckCircle2, Download, ExternalLink, Loader2, Mail, MessageCircle, Package, Truck, XCircle } from 'lucide-react';
import { getLandingAccess, openLandingAccess, resendLandingAccess } from '@/lib/hellomApi';
import type { AccessPage as AccessData } from '@/lib/hellomApi';

// Buyer's product page (/akses/:token, link from the purchase email). Digital products open
// from here, so the seller's latest link and the open/download limits always apply.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-');
const size = (bytes: number | null) => (bytes ? (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`) : '');

export default function AccessPage() {
  const { token = '' } = useParams();
  const [data, setData] = useState<AccessData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [opening, setOpening] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [resending, setResending] = useState(false);

  useEffect(() => {
    document.title = 'Akses produk · Hellom';
    const meta = document.createElement('meta');
    meta.name = 'robots';
    meta.content = 'noindex, nofollow';
    document.head.appendChild(meta);
    return () => { document.head.removeChild(meta); };
  }, []);

  const load = useCallback(() => {
    getLandingAccess(token).then(setData).catch((err) => setError(err instanceof Error ? err.message : 'Akses tidak ditemukan'));
  }, [token]);

  useEffect(() => { load(); }, [load]);

  const open = async () => {
    setOpening(true);
    setNotice(null);
    // Open the tab first (popup blockers), then point it at the product.
    const isLink = data?.access?.kind === 'link';
    const tab = isLink ? window.open('', '_blank') : null;
    try {
      const { url } = await openLandingAccess(token);
      if (tab) {
        tab.opener = null;
        tab.location.href = url;
      } else {
        window.location.href = url;
      }
      load();
    } catch (err) {
      tab?.close();
      setNotice(err instanceof Error ? err.message : 'Produk belum bisa dibuka');
    } finally {
      setOpening(false);
    }
  };

  const resend = async () => {
    setResending(true);
    try {
      await resendLandingAccess(token);
      setNotice('Email berisi link akses sudah dikirim ulang. Cek juga folder Spam/Promosi.');
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Email belum bisa dikirim ulang');
    } finally {
      setResending(false);
    }
  };

  if (error) {
    return (
      <main className="flex min-h-[100svh] items-center justify-center bg-zinc-50 px-6 text-center text-zinc-900">
        <div className="max-w-sm">
          <XCircle className="mx-auto h-12 w-12 text-zinc-300" />
          <h1 className="mt-4 text-xl font-bold">Akses tidak ditemukan</h1>
          <p className="mt-2 text-sm text-zinc-500">{error}</p>
          <Link to="/cek-pesanan" className="mt-6 inline-flex min-h-12 items-center rounded-2xl bg-zinc-900 px-5 text-sm font-semibold text-white">Cek pesanan saya</Link>
        </div>
      </main>
    );
  }

  if (!data) {
    return (
      <main className="min-h-[100svh] bg-zinc-50 px-4 py-8 text-zinc-900" aria-busy="true">
        <div className="mx-auto max-w-md space-y-4"><div className="h-48 animate-pulse rounded-3xl bg-zinc-200" /><div className="h-32 animate-pulse rounded-3xl bg-zinc-100" /></div>
      </main>
    );
  }

  const access = data.access;
  const whatsapp = data.seller.phone ? `https://wa.me/${data.seller.phone.replace(/\D/g, '').replace(/^0/, '62')}` : null;

  return (
    <main className="min-h-[100svh] bg-zinc-50 px-4 py-8 text-zinc-900">
      <div className="mx-auto max-w-md space-y-4">
        <section className="rounded-3xl bg-white p-6 text-center shadow-sm ring-1 ring-zinc-100">
          {data.product_image_url ? (
            <img src={data.product_image_url} alt="" className="mx-auto h-24 w-24 rounded-2xl object-cover" />
          ) : (
            <CheckCircle2 className="mx-auto h-14 w-14 text-emerald-500" />
          )}
          <p className="mt-3 text-sm text-zinc-500">Halo {data.buyer_name || 'kak'}, ini produk kamu</p>
          <h1 className="mt-1 text-xl font-bold">{data.product_name}{data.quantity > 1 ? ` × ${data.quantity}` : ''}</h1>
          <p className="mt-1 text-sm text-zinc-500">dari {data.seller.name}</p>

          {access && (
            <>
              <button
                type="button"
                onClick={() => void open()}
                disabled={opening || !access.available}
                className="mt-6 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 px-4 text-base font-bold text-white disabled:opacity-40"
              >
                {opening ? <Loader2 className="h-5 w-5 animate-spin" /> : access.kind === 'download' ? <Download className="h-5 w-5" /> : <ExternalLink className="h-5 w-5" />}
                {access.kind === 'download' ? 'Unduh file' : 'Buka produk'}
              </button>
              {access.kind === 'download' && access.file_name && <p className="mt-2 text-xs text-zinc-500">{access.file_name} {size(access.file_size)}</p>}
              {access.blocked_reason && <p className="mt-3 text-sm text-amber-700">{access.blocked_reason}</p>}
              <ul className="mt-4 space-y-1 text-xs text-zinc-500">
                {access.kind === 'link' && access.opens_max !== null && <li>Sudah dibuka {access.opens_used} dari {access.opens_max} kali</li>}
                {access.kind === 'download' && access.downloads_max !== null && <li>Sudah diunduh {access.downloads_used} dari {access.downloads_max} kali</li>}
                {access.expires_at && <li>Akses berlaku sampai {date(access.expires_at)}</li>}
              </ul>
            </>
          )}

          {data.shipping && (
            <div className="mt-6 rounded-2xl bg-zinc-50 p-4 text-left text-sm">
              {data.shipping.shipped_at ? (
                <p className="flex items-start gap-2"><Truck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><span><span className="font-semibold">Sudah dikirim</span> {date(data.shipping.shipped_at)}<br />{data.shipping.courier} · resi <span className="font-mono">{data.shipping.tracking_number}</span></span></p>
              ) : (
                <p className="flex items-start gap-2"><Package className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" /><span>Penjual sedang menyiapkan pesanan kamu. Nomor resi muncul di sini setelah dikirim.</span></p>
              )}
              {data.shipping.address && (
                <p className="mt-3 text-xs text-zinc-500">Dikirim ke {data.shipping.address.recipient_name}, {data.shipping.address.address}, {data.shipping.address.city} {data.shipping.address.postal_code}</p>
              )}
            </div>
          )}

          {!access && !data.shipping && (
            <p className="mt-6 rounded-2xl bg-zinc-50 p-4 text-sm text-zinc-600">Penjual akan menghubungi kamu untuk langkah selanjutnya.</p>
          )}
        </section>

        {data.delivery_note && (
          <section className="rounded-3xl bg-white p-5 ring-1 ring-zinc-100">
            <h2 className="text-sm font-bold">Catatan dari penjual</h2>
            <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-zinc-600">{data.delivery_note}</p>
          </section>
        )}

        {notice && <p role="status" className="rounded-2xl bg-zinc-900 px-4 py-3 text-center text-sm text-white">{notice}</p>}

        <section className="rounded-3xl bg-white p-5 text-sm ring-1 ring-zinc-100">
          <dl className="space-y-2">
            <div className="flex justify-between gap-3"><dt className="text-zinc-500">No. pesanan</dt><dd className="font-mono text-xs">{data.reference}</dd></div>
            <div className="flex justify-between gap-3"><dt className="text-zinc-500">Dibayar</dt><dd>{rupiah(data.amount)}</dd></div>
            <div className="flex justify-between gap-3"><dt className="text-zinc-500">Tanggal</dt><dd>{date(data.paid_at)}</dd></div>
            <div className="flex justify-between gap-3"><dt className="text-zinc-500">Status</dt><dd className="font-medium">{data.status_label}</dd></div>
            {data.custom_fields.map((f) => (
              <div key={f.label} className="flex justify-between gap-3"><dt className="text-zinc-500">{f.label}</dt><dd className="text-right">{f.value}</dd></div>
            ))}
          </dl>
          <div className="mt-4 grid gap-2">
            <button type="button" onClick={() => void resend()} disabled={resending} className="flex min-h-12 items-center justify-center gap-2 rounded-2xl border border-zinc-200 font-semibold disabled:opacity-50">
              {resending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mail className="h-4 w-4" />} Kirim ulang email
            </button>
            {whatsapp && (
              <a href={whatsapp} target="_blank" rel="noopener noreferrer" className="flex min-h-12 items-center justify-center gap-2 rounded-2xl border border-zinc-200 font-semibold">
                <MessageCircle className="h-4 w-4" /> Hubungi penjual
              </a>
            )}
          </div>
        </section>
        <p className="text-center text-xs text-zinc-400">Simpan halaman ini. Jangan bagikan tautannya ke orang lain.</p>
      </div>
    </main>
  );
}
