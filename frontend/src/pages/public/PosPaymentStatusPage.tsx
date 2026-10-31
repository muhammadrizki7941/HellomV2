import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import QRCode from 'qrcode';
import {
  AlertCircle, Check, CheckCircle2, Clock3, Copy, ExternalLink, Loader2, RefreshCw, ShieldCheck, XCircle,
} from 'lucide-react';
import {
  getPosCheckoutOptions,
  getPosCheckoutStatus,
  replacePosCheckoutPayment,
  type PosCheckoutStatus,
  type PosPaymentChannel,
} from '@/services/api/posCheckout';
import { trackPlatformMetaEvent } from '@/lib/platformMarketing';

const money = (amount: number) => `Rp ${Math.max(0, Math.round(amount || 0)).toLocaleString('id-ID')}`;
const spacedNumber = (value: string) => (/^\d{8,}$/.test(value) ? value.replace(/(\d{4})(?=\d)/g, '$1 ') : value);

function CopyValue({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setCopied(false);
    }
  };
  return (
    <button type="button" onClick={() => void copy()} className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl border border-zinc-300 bg-white px-3 text-sm font-bold text-zinc-800 hover:border-zinc-900">
      {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
      {copied ? 'Tersalin' : label}
    </button>
  );
}

export default function PosPaymentStatusPage() {
  const { token = '' } = useParams();
  const [status, setStatus] = useState<PosCheckoutStatus | null>(null);
  const [channels, setChannels] = useState<PosPaymentChannel[]>([]);
  const [selectedChannel, setSelectedChannel] = useState('');
  const [qrDataUrl, setQrDataUrl] = useState('');
  const [remainingSeconds, setRemainingSeconds] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [checking, setChecking] = useState(false);
  const [changingMethod, setChangingMethod] = useState(false);
  const [showMethods, setShowMethods] = useState(false);
  const [error, setError] = useState('');

  const refresh = useCallback(async (manual = false) => {
    if (!token) return;
    if (manual) setChecking(true);
    try {
      const current = await getPosCheckoutStatus(token);
      setStatus(current);
      setError('');
      if (current.status === 'paid' && current.purchase_event_id) {
        const key = `hellom-pos-purchase:${current.purchase_event_id}`;
        if (!sessionStorage.getItem(key)) {
          trackPlatformMetaEvent('Purchase', current.purchase_event_id, current.amount);
          sessionStorage.setItem(key, '1');
        }
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Status pembayaran belum dapat diperiksa.');
    } finally {
      setLoading(false);
      setChecking(false);
    }
  }, [token]);

  useEffect(() => { void refresh(); }, [refresh]);

  useEffect(() => {
    if (status?.status !== 'pending') return undefined;
    const timer = window.setInterval(() => { void refresh(); }, 4000);
    return () => window.clearInterval(timer);
  }, [refresh, status?.status]);

  useEffect(() => {
    if (!status?.expires_at) {
      setRemainingSeconds(null);
      return undefined;
    }
    const update = () => setRemainingSeconds(Math.max(0, Math.floor((new Date(status.expires_at as string).getTime() - Date.now()) / 1000)));
    update();
    const timer = window.setInterval(update, 1000);
    return () => window.clearInterval(timer);
  }, [status?.expires_at]);

  useEffect(() => {
    if (status?.payment?.method !== 'qris' || !status.payment.qr_string) {
      setQrDataUrl('');
      return undefined;
    }
    let active = true;
    QRCode.toDataURL(status.payment.qr_string, { width: 640, margin: 1, errorCorrectionLevel: 'M' })
      .then((url) => { if (active) setQrDataUrl(url); })
      .catch(() => { if (active) setQrDataUrl(''); });
    return () => { active = false; };
  }, [status?.payment?.method, status?.payment?.qr_string]);

  const remainingLabel = useMemo(() => {
    if (remainingSeconds === null) return '';
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }, [remainingSeconds]);

  const changePaymentMethod = async (paymentChannel: string) => {
    setChangingMethod(true);
    setError('');
    try {
      await replacePosCheckoutPayment(token, paymentChannel);
      setShowMethods(false);
      setSelectedChannel('');
      await refresh(true);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Metode pembayaran baru belum dapat dibuat.');
    } finally {
      setChangingMethod(false);
    }
  };

  const openMethodPicker = async () => {
    setShowMethods((visible) => !visible);
    if (channels.length) return;
    try {
      const options = await getPosCheckoutOptions();
      setChannels(options.payment.channels);
      setSelectedChannel(options.payment.channels[0]?.key ?? '');
    } catch {
      setError('Pilihan metode pembayaran belum dapat dimuat.');
    }
  };

  const isQr = status?.payment?.method === 'qris';
  const finalStatus = status?.status !== 'pending';

  return (
    <main className="min-h-screen bg-[radial-gradient(ellipse_at_top,_#fef3c7,_#fafafa_45%,_#f4f4f5)] px-4 py-8 sm:px-6 sm:py-12">
      <div className="mx-auto max-w-3xl">
        <Link to="/" className="mb-6 inline-flex items-center gap-2 text-sm font-bold text-zinc-700 hover:text-zinc-950">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-zinc-950 text-amber-300"><ShieldCheck className="h-5 w-5" /></span>
          Hellom · Pembayaran POS
        </Link>

        <section className="overflow-hidden rounded-[2rem] border border-zinc-200 bg-white shadow-xl shadow-zinc-900/5">
          <div className="border-b border-zinc-100 px-5 py-6 sm:px-8">
            {loading ? (
              <div className="flex items-center gap-3"><Loader2 className="h-6 w-6 animate-spin text-amber-500" /><div><h1 className="text-xl font-black text-zinc-950">Memeriksa pembayaran</h1><p className="mt-1 text-sm text-zinc-500">Mohon tunggu sebentar.</p></div></div>
            ) : status?.status === 'paid' ? (
              <div className="flex items-center gap-3"><CheckCircle2 className="h-10 w-10 shrink-0 text-emerald-600" /><div><h1 className="text-2xl font-black text-zinc-950">Pembayaran Berhasil!</h1><p className="mt-1 text-sm text-zinc-600">Langganan {status.plan.name} kamu telah aktif.</p></div></div>
            ) : status?.status === 'expired' ? (
              <div className="flex items-center gap-3"><Clock3 className="h-10 w-10 shrink-0 text-amber-600" /><div><h1 className="text-2xl font-black text-zinc-950">Pembayaran Kedaluwarsa</h1><p className="mt-1 text-sm text-zinc-600">Buat pembayaran baru untuk melanjutkan aktivasi paket.</p></div></div>
            ) : status?.status === 'failed' ? (
              <div className="flex items-center gap-3"><XCircle className="h-10 w-10 shrink-0 text-red-600" /><div><h1 className="text-2xl font-black text-zinc-950">Pembayaran Gagal</h1><p className="mt-1 text-sm text-zinc-600">Kamu dapat memilih metode lain atau meminta bantuan tim Hellom.</p></div></div>
            ) : (
              <div className="flex items-center gap-3"><Clock3 className="h-10 w-10 shrink-0 text-amber-500" /><div><h1 className="text-2xl font-black text-zinc-950">Menunggu Pembayaran</h1><p className="mt-1 text-sm text-zinc-600">Halaman ini akan memperbarui status secara otomatis.</p></div></div>
            )}
          </div>

          {status && (
            <div className="space-y-5 px-5 py-6 sm:px-8">
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-zinc-50 p-4">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Paket {status.plan.name}</p>
                  <p className="mt-1 text-2xl font-black text-zinc-950">{money(status.amount)}</p>
                  {status.invoice_number && <p className="mt-1 text-xs text-zinc-500">Invoice {status.invoice_number}</p>}
                </div>
                {!finalStatus && remainingLabel && (
                  <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-right">
                    <p className="text-[10px] font-bold uppercase tracking-wide text-amber-800">Sisa waktu</p>
                    <p className="font-mono text-xl font-black tabular-nums text-zinc-900">{remainingLabel}</p>
                  </div>
                )}
              </div>

              {status.status === 'pending' && status.payment && (
                isQr ? (
                  <div className="rounded-3xl border border-zinc-200 p-5 text-center sm:p-7">
                    <p className="text-sm font-bold text-zinc-700">{status.payment.channel_label || 'QRIS'}</p>
                    <div className="mx-auto mt-4 flex h-64 w-64 items-center justify-center rounded-2xl border border-zinc-100 bg-white p-3">
                      {(qrDataUrl || status.payment.qr_image_url) ? <img src={qrDataUrl || status.payment.qr_image_url || ''} alt="Kode QR pembayaran" className="h-full w-full object-contain" /> : <Loader2 className="h-6 w-6 animate-spin text-zinc-400" />}
                    </div>
                    <p className="mt-4 text-sm leading-relaxed text-zinc-600">Scan kode ini menggunakan aplikasi m-banking atau e-wallet yang mendukung QRIS. Pastikan nominalnya sesuai.</p>
                    {(qrDataUrl || status.payment.qr_image_url) && (
                      <a href={qrDataUrl || status.payment.qr_image_url || ''} download={`hellom-qris-${status.invoice_number || 'pos'}.png`} className="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl border border-zinc-300 px-4 text-sm font-bold text-zinc-800 hover:bg-zinc-50">Unduh QR</a>
                    )}
                  </div>
                ) : (
                  <div className="space-y-3 rounded-3xl border border-zinc-200 p-5 sm:p-7">
                    <p className="text-sm font-bold text-zinc-700">{status.payment.channel_label || 'Virtual Account'}</p>
                    <div className="flex flex-col justify-between gap-3 rounded-2xl bg-zinc-50 p-4 sm:flex-row sm:items-center">
                      <div><p className="text-xs text-zinc-500">Nomor Virtual Account</p><p className="mt-1 break-all text-2xl font-black tracking-wide text-zinc-950">{spacedNumber(status.payment.payment_number || '')}</p></div>
                      {status.payment.payment_number && <CopyValue value={status.payment.payment_number} label="Salin" />}
                    </div>
                    <div className="flex items-center justify-between gap-3 rounded-2xl bg-amber-50 p-4">
                      <div><p className="text-xs text-zinc-600">Nominal pembayaran</p><p className="mt-1 text-lg font-black text-zinc-950">{money(status.amount)}</p></div><CopyValue value={String(status.amount)} label="Salin nominal" />
                    </div>
                    <p className="text-sm leading-relaxed text-zinc-600">Bayar melalui ATM, m-banking, atau internet banking. Pilih menu transfer Virtual Account dan masukkan nomor di atas. Pastikan pembayaran sesuai nominal.</p>
                  </div>
                )
              )}

              {status.status === 'pending' && (
                <div className="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-relaxed text-sky-900">
                  <p className="font-bold">Pembayaran belum terdeteksi?</p>
                  <p className="mt-1">Status dapat memerlukan waktu beberapa saat untuk diperbarui. Kamu bisa mengecek ulang secara manual.</p>
                </div>
              )}

              {status.status === 'paid' && (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                  Akses {status.plan.name} sudah aktif. Tautan untuk mengatur kata sandi dan masuk ke akun juga dikirim ke email pendaftaran {status.email}.
                </div>
              )}

              {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

              {showMethods && (
                <div className="rounded-2xl border border-zinc-200 p-4">
                  <p className="mb-3 text-sm font-bold text-zinc-900">Pilih metode pembayaran baru</p>
                  <div className="flex flex-wrap gap-2">
                    {channels.map((item) => <button key={item.key} type="button" disabled={changingMethod} onClick={() => void changePaymentMethod(item.key)} className="rounded-full border border-zinc-300 px-3 py-2 text-xs font-bold hover:border-zinc-900 disabled:opacity-50">{item.label}</button>)}
                    {!channels.length && <span className="text-sm text-zinc-500">Memuat metode pembayaran…</span>}
                  </div>
                </div>
              )}

              <div className="flex flex-col gap-3 sm:flex-row">
                {status.status === 'pending' && (
                  <>
                    <button type="button" onClick={() => void openMethodPicker()} className="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl border border-zinc-300 px-4 text-sm font-bold text-zinc-800 hover:bg-zinc-50">
                      <RefreshCw className="h-4 w-4" /> Ganti metode pembayaran
                    </button>
                    <button type="button" onClick={() => void refresh(true)} disabled={checking} className="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-bold text-amber-300 hover:bg-zinc-800 disabled:opacity-60">
                      {checking ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />} Cek status pembayaran
                    </button>
                  </>
                )}
                {status.status === 'expired' && (
                  <button type="button" onClick={() => void openMethodPicker()} className="inline-flex min-h-12 flex-1 items-center justify-center rounded-xl bg-zinc-950 px-4 text-sm font-bold text-amber-300 hover:bg-zinc-800">Buat pembayaran baru</button>
                )}
                {status.status === 'paid' && (
                  <a href={status.login_url || '/login'} className="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-bold text-amber-300 hover:bg-zinc-800">Masuk ke Dashboard <ExternalLink className="h-4 w-4" /></a>
                )}
                {status.status === 'failed' && (
                  <Link to="/kontak" className="inline-flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-zinc-950 px-4 text-sm font-bold text-amber-300 hover:bg-zinc-800"><AlertCircle className="h-4 w-4" /> Hubungi Pusat Bantuan</Link>
                )}
              </div>
            </div>
          )}
          {error && !status && <p role="alert" className="m-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 sm:m-8">{error}</p>}
          {loading && !status && <div className="flex justify-center p-8"><Loader2 className="h-6 w-6 animate-spin text-zinc-400" /></div>}
        </section>
        <p className="mt-5 flex items-center justify-center gap-2 text-center text-xs text-zinc-500"><ShieldCheck className="h-4 w-4" /> Pembayaran diproses dengan aman. Hellom tidak menyimpan data kartu atau rekening.</p>
      </div>
    </main>
  );
}
