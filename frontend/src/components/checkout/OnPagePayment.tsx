import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Check, Copy, Loader2 } from 'lucide-react';
import type { PaymentInstructions } from '@/lib/hellomApi';

// Hellom Page buyers pay on the shop's own checkout (no redirect to the gateway):
// QRIS drawn here from the code iPaymu returns, or a VA / retail payment code.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
// "8808000000100272" → "8808 0000 0010 0272" (easier to type into m-banking).
const groupDigits = (value: string) => (/^\d{8,}$/.test(value) ? value.replace(/(\d{4})(?=\d)/g, '$1 ') : value);

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);

  return (
    <button
      type="button"
      onClick={() => {
        void navigator.clipboard?.writeText(value).then(() => {
          setCopied(true);
          window.setTimeout(() => setCopied(false), 1500);
        });
      }}
      className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl border border-zinc-300 px-3 text-sm font-semibold text-zinc-700 hover:border-zinc-900"
    >
      {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
      {copied ? 'Tersalin' : 'Salin'}
    </button>
  );
}

export default function OnPagePayment({ payment, amount, expiresAt, reference }: {
  payment: PaymentInstructions;
  amount: number;
  expiresAt: string | null;
  reference: string;
}) {
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);
  const qrString = payment.mode === 'qris' ? payment.qr_string || '' : '';

  useEffect(() => {
    if (!qrString) {
      setQrDataUrl(null);
      return undefined;
    }
    let cancelled = false;
    QRCode.toDataURL(qrString, { width: 600, margin: 1, errorCorrectionLevel: 'M' })
      .then((url) => { if (!cancelled) setQrDataUrl(url); })
      .catch(() => { if (!cancelled) setQrDataUrl(null); });
    return () => { cancelled = true; };
  }, [qrString]);

  const qrSrc = qrDataUrl || payment.qr_image_url;
  const isRetail = /indomaret|alfamart/i.test(payment.channel_label ?? '');

  return (
    <div className="text-left">
      <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">{payment.channel_label || 'Pembayaran'}</p>
      <p className="mt-1 text-2xl font-bold text-zinc-900">{rupiah(amount)}</p>
      {expiresAt && (
        <p className="mt-1 text-sm text-zinc-500">
          Bayar sebelum {new Date(expiresAt).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}
        </p>
      )}

      {payment.mode === 'qris' && (
        <div className="mt-4 flex flex-col items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4">
          {qrSrc ? (
            <img src={qrSrc} alt="Kode QRIS" className="h-64 w-64 object-contain" />
          ) : (
            <div className="flex h-64 w-64 items-center justify-center text-sm text-zinc-400">Menyiapkan QR…</div>
          )}
          <p className="text-center text-sm text-zinc-600">Scan pakai GoPay, OVO, DANA, ShopeePay, atau m-banking apa pun.</p>
          {qrSrc && (
            <a href={qrSrc} download={`qris-${reference}.png`} className="flex min-h-12 w-full items-center justify-center rounded-2xl border border-zinc-200 text-sm font-semibold">
              Simpan gambar QR
            </a>
          )}
        </div>
      )}

      {payment.mode === 'va' && payment.va_number && (
        <div className="mt-4 space-y-3">
          <div className="flex items-center justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4">
            <div className="min-w-0">
              <p className="text-xs text-zinc-500">{isRetail ? 'Kode pembayaran' : 'Nomor Virtual Account'}</p>
              <p className="break-words text-xl font-bold tabular-nums tracking-wide text-zinc-900 sm:text-2xl">{groupDigits(payment.va_number)}</p>
            </div>
            <CopyButton value={payment.va_number} />
          </div>
          <p className="text-sm leading-relaxed text-zinc-600">
            {isRetail
              ? `Tunjukkan kode ini ke kasir ${payment.channel_label} dan bayar tepat ${rupiah(amount)}.`
              : `Transfer tepat ${rupiah(amount)} ke nomor ini lewat m-banking, internet banking, atau ATM.`}
          </p>
        </div>
      )}

      <p className="mt-4 flex items-center justify-center gap-2 text-sm text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" /> Menunggu pembayaran… halaman ini otomatis lanjut setelah kamu bayar.</p>
    </div>
  );
}
