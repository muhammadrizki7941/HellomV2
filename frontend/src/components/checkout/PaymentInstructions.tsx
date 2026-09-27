import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Check, Copy, ExternalLink } from 'lucide-react';
import { getImageUrl, type GatewayPaymentInstructions, type ManualPaymentMethodOption } from '@/lib/hellomApi';
import { formatRupiah } from './CheckoutShell';

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
      className="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-white/[0.12] px-3 py-2 text-xs font-semibold text-white/80 hover:border-[#F6B400] hover:text-white"
    >
      {copied ? <Check className="h-3.5 w-3.5 text-emerald-400" /> : <Copy className="h-3.5 w-3.5" />}
      {copied ? 'Tersalin' : 'Salin'}
    </button>
  );
}

// VA number / QRIS rendered on our page (iPaymu direct charge).
export function GatewayInstructions({ instructions, fallbackAmount }: { instructions: GatewayPaymentInstructions; fallbackAmount: number }) {
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null);
  const qrString = instructions.qr_string || '';

  useEffect(() => {
    if (!qrString) {
      setQrDataUrl(null);
      return;
    }
    let cancelled = false;
    QRCode.toDataURL(qrString, { width: 280, margin: 1 })
      .then((url) => { if (!cancelled) setQrDataUrl(url); })
      .catch(() => { if (!cancelled) setQrDataUrl(null); });
    return () => { cancelled = true; };
  }, [qrString]);

  const qrSrc = qrDataUrl || instructions.qr_image_url || null;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <p className="text-xs uppercase tracking-[0.2em] text-[#8B8B90]">{instructions.channel_label || 'Pembayaran'}</p>
          <p className="mt-1 text-2xl font-bold">{formatRupiah(Number(instructions.amount ?? fallbackAmount))}</p>
        </div>
        {instructions.expires_at ? (
          <p className="text-xs text-[#8B8B90]">
            Bayar sebelum {new Date(instructions.expires_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}
          </p>
        ) : null}
      </div>

      {instructions.va_number ? (
        <div className="flex items-center justify-between gap-3 rounded-2xl border border-white/[0.10] bg-black/35 px-4 py-3">
          <div className="min-w-0">
            <p className="text-xs text-[#8B8B90]">Nomor pembayaran</p>
            <p className="break-all text-xl font-bold tracking-wider">{instructions.va_number}</p>
          </div>
          <CopyButton value={instructions.va_number} />
        </div>
      ) : null}

      {!instructions.va_number && qrSrc ? (
        <div className="flex flex-col items-center gap-3 rounded-2xl bg-white p-5">
          <img src={qrSrc} alt="QRIS" className="h-60 w-60 object-contain" />
          <p className="text-center text-xs text-zinc-600">Scan dengan aplikasi e-wallet atau m-banking apa pun.</p>
        </div>
      ) : null}
    </div>
  );
}

// Bank transfer / e-wallet details configured by the super admin.
export function ManualInstructions({ method, amount }: { method: ManualPaymentMethodOption; amount: number }) {
  return (
    <div className="space-y-4">
      <div>
        <p className="text-xs uppercase tracking-[0.2em] text-[#8B8B90]">Transfer ke</p>
        <p className="mt-1 text-2xl font-bold">{formatRupiah(amount)}</p>
      </div>
      <div className="space-y-1 rounded-2xl border border-white/[0.10] bg-black/35 px-4 py-3 text-sm">
        <p className="font-semibold text-white">{method.label}</p>
        {method.bank_name ? <p className="text-[#B5B5BA]">Bank: {method.bank_name}</p> : null}
        {method.account_name ? <p className="text-[#B5B5BA]">Atas nama: {method.account_name}</p> : null}
        {method.account_number ? (
          <div className="flex items-center justify-between gap-3 pt-1">
            <p className="break-all text-lg font-bold tracking-wider text-white">{method.account_number}</p>
            <CopyButton value={method.account_number} />
          </div>
        ) : null}
      </div>
      {method.instructions ? <p className="text-sm leading-relaxed text-[#B5B5BA]">{method.instructions}</p> : null}
      {method.image_url ? (
        <div className="flex justify-center rounded-2xl bg-white p-4">
          <img src={getImageUrl(method.image_url)} alt={method.label} className="max-h-64 object-contain" />
        </div>
      ) : null}
    </div>
  );
}

// Payment page hosted by the gateway (Xendit / DOKU / iPaymu redirect). Opens in a
// new tab so this status page keeps polling and shows the result.
export function HostedCheckoutButton({ url }: { url: string }) {
  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      className="flex h-[52px] w-full items-center justify-center gap-2 rounded-2xl bg-[#F6B400] text-sm font-bold text-[#050505] transition hover:bg-[#FFCC47]"
    >
      Buka halaman pembayaran <ExternalLink className="h-4 w-4" />
    </a>
  );
}
