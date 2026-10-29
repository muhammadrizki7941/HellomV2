import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertCircle, CheckCircle, Clock3, ExternalLink, Loader2, RefreshCw, X } from 'lucide-react';

// Gateways that refuse to be embedded in an iframe (X-Frame-Options / CSP frame-ancestors).
// For these we skip the iframe and send the user straight to the hosted payment page.
const NON_EMBEDDABLE_HOSTS = ['ipaymu.com'];

interface GatewayPaymentFrameProps {
  paymentUrl: string;
  title?: string;
  onClose: () => void;
  onCancel?: () => void;
  expiresAt?: string | null;
  onPaid?: () => void;
  /** Return true when payment is confirmed. Called every pollIntervalMs. */
  pollFn?: () => Promise<boolean>;
  pollIntervalMs?: number;
}

export default function GatewayPaymentFrame({
  paymentUrl,
  title = 'Pembayaran',
  onClose,
  onCancel,
  expiresAt,
  onPaid,
  pollFn,
  pollIntervalMs = 5000,
}: GatewayPaymentFrameProps) {
  const [iframeReady, setIframeReady] = useState(false);
  const [timedOut, setTimedOut] = useState(false);
  const [isPaid, setIsPaid] = useState(false);
  const [pollCount, setPollCount] = useState(0);
  const [secondsRemaining, setSecondsRemaining] = useState<number | null>(null);
  const [isExpired, setIsExpired] = useState(false);
  const [isCancelling, setIsCancelling] = useState(false);
  const [cancelError, setCancelError] = useState<string | null>(null);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Some gateways (e.g. iPaymu) block iframe embedding — open them in a new tab instead.
  const isBlockedGateway = useMemo(() => {
    try {
      const host = new URL(paymentUrl).hostname.toLowerCase();
      return NON_EMBEDDABLE_HOSTS.some((domain) => host === domain || host.endsWith(`.${domain}`) || host.includes(domain));
    } catch {
      return false;
    }
  }, [paymentUrl]);

  useEffect(() => {
    if (!expiresAt) return undefined;
    const expiresAtMs = new Date(expiresAt).getTime();
    if (!Number.isFinite(expiresAtMs)) return undefined;

    const updateCountdown = () => {
      const remaining = Math.max(0, Math.ceil((expiresAtMs - Date.now()) / 1000));
      setSecondsRemaining(remaining);
      if (remaining === 0) setIsExpired(true);
    };

    updateCountdown();
    const timer = window.setInterval(updateCountdown, 1000);
    return () => window.clearInterval(timer);
  }, [expiresAt]);

  const countdownLabel = secondsRemaining === null
    ? null
    : [Math.floor(secondsRemaining / 3600), Math.floor((secondsRemaining % 3600) / 60), secondsRemaining % 60]
      .map((part) => String(part).padStart(2, '0'))
      .join(':');

  const cancelPayment = async () => {
    if (!onCancel || isCancelling) return;
    if (!window.confirm('Batalkan pembayaran ini? Checkout ini tidak dapat dilanjutkan setelah dibatalkan.')) return;
    setIsCancelling(true);
    setCancelError(null);
    try {
      await onCancel();
      onClose();
    } catch (error) {
      setCancelError(error instanceof Error ? error.message : 'Pembayaran gagal dibatalkan. Silakan coba lagi.');
    } finally {
      setIsCancelling(false);
    }
  };

  // ── Polling ──────────────────────────────────────────────────────────────
  const runPoll = useCallback(async () => {
    if (!pollFn || isPaid) return;
    try {
      const paid = await pollFn();
      if (paid) {
        setIsPaid(true);
        if (intervalRef.current) clearInterval(intervalRef.current);
        setTimeout(() => onPaid?.(), 2000);
      }
    } catch {
      // swallow — network hiccup during poll
    }
    setPollCount((n) => n + 1);
  }, [pollFn, isPaid, onPaid]);

  useEffect(() => {
    if (!pollFn) return;
    intervalRef.current = setInterval(() => void runPoll(), pollIntervalMs);
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
    };
  }, [runPoll, pollFn, pollIntervalMs]);

  // ── Loading timeout (6 s) ─────────────────────────────────────────────────
  useEffect(() => {
    timeoutRef.current = setTimeout(() => {
      if (!iframeReady) setTimedOut(true);
    }, 6000);
    return () => {
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
  }, [iframeReady]);

  const handleIframeLoad = () => {
    setIframeReady(true);
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
  };

  // ── Render ────────────────────────────────────────────────────────────────
  const content = (
    <div className="fixed inset-0 z-[200] flex flex-col bg-white">
      {/* ── Top bar ── */}
      <div className="flex h-12 shrink-0 items-center justify-between border-b border-zinc-200 bg-white px-4">
        <div className="flex min-w-0 items-center gap-2">
          {isPaid ? (
            <CheckCircle className="h-4 w-4 shrink-0 text-green-600" />
          ) : isExpired ? (
            <AlertCircle className="h-4 w-4 shrink-0 text-red-600" />
          ) : (
            <span className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-yellow-400" />
          )}
          <span className="truncate text-sm font-semibold text-zinc-900">
            {isPaid ? 'Pembayaran Berhasil!' : isExpired ? 'Waktu pembayaran berakhir' : title}
          </span>
        </div>

        <div className="flex items-center gap-1">
          {countdownLabel && !isPaid && !isExpired && (
            <span className="mr-2 inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-bold tabular-nums text-amber-800">
              <Clock3 className="h-3.5 w-3.5" />
              {countdownLabel}
            </span>
          )}
          {/* Always-visible "open in browser" escape hatch */}
          <a
            href={paymentUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
            title="Buka di browser baru"
          >
            <ExternalLink className="h-3.5 w-3.5" />
            <span className="hidden sm:inline">Buka di browser</span>
          </a>
          <button
            onClick={onClose}
            className="rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-900"
            title="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </div>

      {/* ── Main area ── */}
      <div className="relative flex-1 overflow-hidden bg-zinc-100">
        {isExpired && !isPaid && (
          <div className="absolute inset-0 z-30 flex flex-col items-center justify-center gap-4 bg-white/95 p-8 text-center">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-red-50 text-red-600">
              <AlertCircle className="h-8 w-8" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-zinc-900">Waktu pembayaran berakhir</h2>
              <p className="mt-2 max-w-md text-sm leading-6 text-zinc-600">
                Batas pembayaran adalah 1 jam. Checkout ini sudah ditutup; silakan mulai pembayaran baru.
              </p>
            </div>
            <button type="button" onClick={onClose} className="rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white hover:bg-zinc-800">
              Tutup
            </button>
          </div>
        )}
        {/* Success overlay */}
        {isPaid && (
          <div className="absolute inset-0 z-20 flex flex-col items-center justify-center gap-5 bg-green-50 p-8 text-center">
            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-green-100">
              <CheckCircle className="h-10 w-10 text-green-600" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-zinc-900">Pembayaran Dikonfirmasi</h2>
              <p className="mt-2 text-sm text-zinc-500">
                Akses kamu sudah aktif. Halaman ini akan ditutup otomatis.
              </p>
            </div>
          </div>
        )}

        {/* Loading overlay */}
        {!iframeReady && !timedOut && !isPaid && !isBlockedGateway && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-zinc-100">
            <Loader2 className="h-8 w-8 animate-spin text-zinc-400" />
            <p className="text-sm text-zinc-500">Memuat halaman pembayaran...</p>
          </div>
        )}

        {/* Timeout / blocked fallback */}
        {(timedOut || isBlockedGateway) && !iframeReady && !isPaid && (
          <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-5 bg-zinc-50 p-8 text-center">
            <div className="text-5xl">{isBlockedGateway ? '💳' : '🔒'}</div>
            <div>
              <h3 className="text-lg font-bold text-zinc-900">
                {isBlockedGateway ? 'Lanjutkan pembayaran di halaman gateway' : 'Halaman pembayaran tidak bisa dimuat di sini'}
              </h3>
              <p className="mt-2 text-sm text-zinc-600">
                {isBlockedGateway
                  ? 'Demi keamanan, gateway ini membuka pembayaran di tab terpisah. Klik tombol di bawah untuk membayar — status pembayaran tetap kami pantau otomatis di sini.'
                  : 'Gateway pembayaran ini memblokir embedding. Buka di browser untuk menyelesaikan pembayaran — kami tetap memantau statusnya di latar belakang.'}
              </p>
            </div>
            <a
              href={paymentUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-6 py-3 text-sm font-bold text-white hover:bg-zinc-800"
            >
              <ExternalLink className="h-4 w-4" />
              Buka Halaman Pembayaran
            </a>
            {pollFn && (
              <p className="text-xs text-zinc-400">
                Status pembayaran dipantau otomatis. Setelah berhasil, halaman ini akan memperbarui diri.
              </p>
            )}
          </div>
        )}

        {/* The iframe (skipped for gateways that block embedding) */}
        {!isPaid && !isBlockedGateway && (
          <iframe
            src={paymentUrl}
            className="h-full w-full border-0"
            title="Halaman Pembayaran"
            allow="payment"
            onLoad={handleIframeLoad}
          />
        )}
      </div>

      {/* ── Polling status bar ── */}
      {pollFn && !isPaid && !isExpired && (
        <div className="flex h-8 shrink-0 items-center gap-2 border-t border-zinc-100 bg-zinc-50 px-4">
          <RefreshCw className="h-3 w-3 animate-spin text-zinc-400" style={{ animationDuration: '4s' }} />
          <span className="text-xs text-zinc-400">
            Memantau status pembayaran
            {pollCount > 0 ? ` · cek ke-${pollCount}` : ''}
          </span>
        </div>
      )}
      {onCancel && !isPaid && !isExpired && (
        <div className="shrink-0 border-t border-zinc-200 bg-white px-4 py-2">
          {cancelError && <p role="alert" className="mb-1 text-center text-xs text-red-700">{cancelError}</p>}
          <div className="flex justify-center">
            <button
              type="button"
              onClick={() => void cancelPayment()}
              disabled={isCancelling}
              className="rounded-lg px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:opacity-60"
            >
              {isCancelling ? 'Membatalkan pembayaran...' : 'Batalkan pembayaran'}
            </button>
          </div>
        </div>
      )}
    </div>
  );

  return createPortal(content, document.body);
}
