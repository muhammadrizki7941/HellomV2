import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { CheckCircle2, Clock, Loader2, MailCheck, MessageCircle, XCircle } from 'lucide-react';
import { getGuestCheckoutStatus, resendGuestAccessEmail, type GuestCheckoutStatus } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';
import CheckoutShell, { formatRupiah, panelClass } from '@/components/checkout/CheckoutShell';
import { GatewayInstructions, HostedCheckoutButton, ManualInstructions } from '@/components/checkout/PaymentInstructions';

const POLL_MS = 5000;
const RESEND_COOLDOWN_S = 60;

const whatsappNumber = (value?: string | null) => {
  const digits = String(value || '').replace(/\D/g, '');
  if (!digits) return '';
  return digits.startsWith('0') ? `62${digits.slice(1)}` : digits;
};

export default function GuestCheckoutStatusPage() {
  const { token = '' } = useParams();
  const { brand } = useBrand();
  const [status, setStatus] = useState<GuestCheckoutStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [resending, setResending] = useState(false);
  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [cooldown, setCooldown] = useState(0);

  const load = useCallback(async () => {
    try {
      setStatus(await getGuestCheckoutStatus(token));
      setNotFound(false);
    } catch {
      setNotFound(true);
    } finally {
      setLoading(false);
    }
  }, [token]);

  useEffect(() => {
    void load();
  }, [load]);

  // Poll while waiting for payment; the webhook (or admin approval) flips it to paid.
  const isPending = status?.status === 'pending';
  useEffect(() => {
    if (!isPending) return;
    const id = window.setInterval(() => void load(), POLL_MS);
    return () => window.clearInterval(id);
  }, [isPending, load]);

  useEffect(() => {
    if (cooldown <= 0) return;
    const id = window.setTimeout(() => setCooldown((s) => s - 1), 1000);
    return () => window.clearTimeout(id);
  }, [cooldown]);

  useEffect(() => {
    document.title = status?.is_paid ? 'Pembayaran berhasil' : 'Status pembayaran';
  }, [status?.is_paid]);

  const whatsappUrl = useMemo(() => {
    const phone = whatsappNumber(brand.support_phone);
    if (!phone || !status) return '';
    const text = [
      `Halo ${brand.business_name || brand.app_name}, saya sudah transfer untuk ${status.product?.name ?? 'produk digital'}.`,
      `No. transaksi: ${status.transaction_code ?? '-'}`,
      `Nominal: ${formatRupiah(status.amount)}`,
    ].join('\n');
    return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
  }, [brand.app_name, brand.business_name, brand.support_phone, status]);

  const handleResend = async () => {
    setResending(true);
    setResendMessage(null);
    try {
      const res = await resendGuestAccessEmail(token);
      setResendMessage(`Email akses dikirim ulang ke ${res.email}.`);
      setCooldown(RESEND_COOLDOWN_S);
    } catch (err) {
      setResendMessage(err instanceof Error ? err.message : 'Gagal mengirim ulang email.');
    } finally {
      setResending(false);
    }
  };

  if (loading) {
    return (
      <CheckoutShell>
        <div className="flex justify-center py-24"><Loader2 className="h-6 w-6 animate-spin text-[#F6B400]" /></div>
      </CheckoutShell>
    );
  }

  if (notFound || !status) {
    return (
      <CheckoutShell>
        <div className={`${panelClass} mx-auto max-w-lg text-center`}>
          <p className="text-lg font-semibold">Transaksi tidak ditemukan</p>
          <p className="mt-2 text-sm text-[#8B8B90]">Link ini tidak valid atau sudah diganti oleh checkout yang lebih baru.</p>
          <Link to="/produk" className="mt-6 inline-block text-sm font-semibold text-[#F6B400]">Lihat produk</Link>
        </div>
      </CheckoutShell>
    );
  }

  const productName = status.product?.name ?? 'Produk digital';

  if (status.is_paid) {
    return (
      <CheckoutShell>
        <div className={`${panelClass} mx-auto max-w-xl text-center`}>
          <CheckCircle2 className="mx-auto h-14 w-14 text-emerald-400" />
          <h1 className="mt-4 text-2xl font-semibold">Pembayaran berhasil</h1>
          <p className="mt-2 text-sm text-[#B5B5BA]">
            <strong className="text-white">{productName}</strong> sudah aktif selamanya di akun Anda.
          </p>
          <div className="mt-6 rounded-2xl border border-white/[0.10] bg-black/30 px-5 py-4 text-left text-sm">
            <p className="flex items-center gap-2 font-semibold text-white"><MailCheck className="h-4 w-4 text-[#F6B400]" /> Cek email {status.email}</p>
            <p className="mt-2 text-[#B5B5BA]">
              Kami mengirim link untuk langsung membuka produk di dashboard. Untuk akun baru, password juga ada di email tersebut.
              Tidak ada di inbox? Periksa folder spam/promosi.
            </p>
          </div>
          <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <button
              type="button"
              onClick={() => void handleResend()}
              disabled={resending || cooldown > 0}
              className="inline-flex h-11 items-center justify-center gap-2 rounded-2xl border border-white/[0.14] px-5 text-sm font-semibold hover:border-[#F6B400] disabled:opacity-50"
            >
              {resending ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
              {cooldown > 0 ? `Kirim ulang (${cooldown}s)` : 'Kirim ulang email'}
            </button>
            <Link to="/login" className="inline-flex h-11 items-center justify-center rounded-2xl bg-[#F6B400] px-5 text-sm font-bold text-[#050505] hover:bg-[#FFCC47]">
              Masuk ke dashboard
            </Link>
          </div>
          {resendMessage ? <p className="mt-4 text-sm text-[#B5B5BA]">{resendMessage}</p> : null}
          <p className="mt-6 text-xs text-[#8B8B90]">No. transaksi {status.transaction_code}</p>
        </div>
      </CheckoutShell>
    );
  }

  if (!isPending) {
    return (
      <CheckoutShell>
        <div className={`${panelClass} mx-auto max-w-lg text-center`}>
          <XCircle className="mx-auto h-12 w-12 text-rose-400" />
          <p className="mt-4 text-lg font-semibold">Pembayaran tidak berhasil</p>
          <p className="mt-2 text-sm text-[#8B8B90]">Transaksi {status.transaction_code} {status.status === 'cancelled' ? 'dibatalkan' : 'gagal atau kedaluwarsa'}. Silakan checkout ulang.</p>
          {status.product ? (
            <Link to={`/produk/${status.product.slug}/checkout`} className="mt-6 inline-block text-sm font-semibold text-[#F6B400]">Checkout ulang</Link>
          ) : null}
        </div>
      </CheckoutShell>
    );
  }

  const isManual = status.payment_gateway === 'manual';

  return (
    <CheckoutShell>
      <div className="mx-auto grid max-w-4xl gap-6 lg:grid-cols-[1fr_320px]">
        <section className={`${panelClass} space-y-6`}>
          <div className="flex items-center gap-3">
            <Clock className="h-5 w-5 text-[#F6B400]" />
            <div>
              <h1 className="text-xl font-semibold">Selesaikan pembayaran</h1>
              <p className="text-sm text-[#8B8B90]">
                {isManual
                  ? 'Akses dikirim ke email Anda setelah admin mengonfirmasi transfer.'
                  : 'Halaman ini otomatis berubah begitu pembayaran diterima.'}
              </p>
            </div>
          </div>

          {status.payment_instructions ? <GatewayInstructions instructions={status.payment_instructions} fallbackAmount={status.amount} /> : null}
          {isManual && status.manual_payment ? <ManualInstructions method={status.manual_payment} amount={status.amount} /> : null}
          {!status.payment_instructions && !isManual && status.checkout_url ? (
            <div className="space-y-3">
              <p className="text-sm text-[#B5B5BA]">Pembayaran diproses di halaman aman penyedia pembayaran. Setelah membayar, kembali ke halaman ini.</p>
              <HostedCheckoutButton url={status.checkout_url} />
            </div>
          ) : null}

          {isManual && whatsappUrl ? (
            <a
              href={whatsappUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="flex h-[52px] w-full items-center justify-center gap-2 rounded-2xl border border-white/[0.14] text-sm font-semibold hover:border-[#F6B400]"
            >
              <MessageCircle className="h-4 w-4" /> Konfirmasi transfer via WhatsApp
            </a>
          ) : null}

          <p className="flex items-center gap-2 text-xs text-[#8B8B90]">
            <Loader2 className="h-3.5 w-3.5 animate-spin" /> Menunggu pembayaran... Anda boleh menutup halaman ini; akses tetap dikirim ke {status.email}.
          </p>
        </section>

        <aside className={`${panelClass} h-fit space-y-3 text-sm`}>
          <p className="font-semibold">{productName}</p>
          <div className="flex justify-between"><span className="text-[#8B8B90]">No. transaksi</span><span>{status.transaction_code}</span></div>
          <div className="flex justify-between"><span className="text-[#8B8B90]">Email</span><span>{status.email}</span></div>
          <div className="flex justify-between border-t border-white/[0.08] pt-3 font-bold"><span>Total</span><span className="text-[#F6B400]">{formatRupiah(status.amount)}</span></div>
          <p className="pt-2 text-xs text-[#8B8B90]">Simpan link halaman ini untuk mengecek status pembayaran.</p>
        </aside>
      </div>
    </CheckoutShell>
  );
}
