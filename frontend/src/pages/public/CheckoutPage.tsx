import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { BadgeCheck, Building2, CheckCircle2, Loader2, Lock, Minus, Plus, QrCode, ShieldCheck, Store, Tag, Wallet, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { safeHtml } from '@/lib/safeHtml';
import { EMAIL_PATTERN, suggestEmail } from '@/lib/emailTypo';
import { captureAttributionFromUrl, firePurchase, getPixelConsent, loadSellerPixels, readAttribution, setPixelConsent, trackSellerEvent } from '@/lib/sellerPixels';
import BookingPicker from './BookingPicker';
import type { BookingChoice } from '@/lib/hellomApi';
import { ApiError, checkoutLandingProduct, getLandingOrderPublicStatus, getPublicLandingProduct, getShippingRates, quoteLandingProduct } from '@/lib/hellomApi';
import type { ShippingDestination, ShippingRate } from '@/lib/hellomApi';
import DestinationSearch from '@/components/checkout/DestinationSearch';
import type { CheckoutQuote, CheckoutResult, PaymentChannel, PaymentOption, PublicProductPage } from '@/lib/hellomApi';
import TurnstileWidget from '@/components/checkout/TurnstileWidget';
import OnPagePayment from '@/components/checkout/OnPagePayment';

// Buyer checkout for a Hellom Page product (/beli/:productId), no login. Mobile-first:
// one page, big sticky pay button. Prices come from the server (quote); the order is
// only marked paid by the gateway, never by this page.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const inputClass = 'min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none transition focus:border-zinc-900';
// Channels grouped like Hellom's own checkout; the buyer pays on this page (QR / VA / retail code).
const CHANNEL_GROUPS: Array<{ group: string; title: string; hint: string; icon: typeof QrCode }> = [
  { group: 'qris', title: 'QRIS', hint: 'GoPay, OVO, DANA, ShopeePay, m-banking', icon: QrCode },
  { group: 'va', title: 'Virtual Account', hint: 'm-banking, internet banking, ATM', icon: Building2 },
  { group: 'cstore', title: 'Gerai retail', hint: 'bayar tunai di kasir', icon: Store },
  { group: 'other', title: 'Metode lain', hint: 'dipilih di halaman pembayaran', icon: Wallet },
];
const KNOWN_GROUPS = ['qris', 'va', 'cstore'];
const FALLBACK_CHANNELS: Record<string, PaymentChannel> = {
  qris: { key: 'qris', label: 'QRIS', group: 'qris' },
  other: { key: 'other', label: 'Virtual Account & lainnya', group: 'other' },
};

type Shipping = { recipient_name: string; phone: string; address: string; city: string; province: string; postal_code: string; notes: string };

/** "2-3 day" (RajaOngkir) → "2-3 hari". */
const etdText = (etd: string | null) => {
  if (!etd) return null;
  const clean = etd.replace(/days?|hari/gi, '').trim();
  return clean ? `${clean} hari` : null;
};

export default function CheckoutPage() {
  const { productId = '' } = useParams();
  const [page, setPage] = useState<PublicProductPage | null>(null);
  const [loadError, setLoadError] = useState<{ message: string; suspended: boolean } | null>(null);
  const [quantity, setQuantity] = useState(1);
  // Rental: the chosen dates/time (BookingPicker); complete = ready to price and pay.
  const [bookingChoice, setBookingChoice] = useState<BookingChoice | null>(null);
  const isRental = page?.product.type === 'rental' && !!page.product.booking;
  const bookingReady = !!bookingChoice && (page?.product.booking?.mode === 'daily' ? !!bookingChoice.start_date && !!bookingChoice.days : !!bookingChoice.date && !!bookingChoice.start_time && !!bookingChoice.slots);
  const [buyer, setBuyer] = useState({ name: '', email: '', phone: '' });
  const [fields, setFields] = useState<Record<string, string>>({});
  const [shipping, setShipping] = useState<Shipping>({ recipient_name: '', phone: '', address: '', city: '', province: '', postal_code: '', notes: '' });
  const [couponInput, setCouponInput] = useState('');
  const [couponCode, setCouponCode] = useState('');
  const [quote, setQuote] = useState<CheckoutQuote | null>(null);
  // Courier shipping (ongkir otomatis): buyer's place → real rates → chosen courier.
  const [destination, setDestination] = useState<ShippingDestination | null>(null);
  const [rates, setRates] = useState<ShippingRate[]>([]);
  const [ratesState, setRatesState] = useState<'idle' | 'loading' | 'ready' | 'error'>('idle');
  const [ratesMessage, setRatesMessage] = useState<string | null>(null);
  const [courier, setCourier] = useState<string | null>(null);
  const [method, setMethod] = useState<PaymentOption | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Turnstile, only after the server asks for it (repeated checkouts). `round` remounts it after a failed try.
  const [captcha, setCaptcha] = useState<{ siteKey: string; token: string | null; round: number } | null>(null);
  // Order waiting for payment on this page (QRIS or VA/retail code).
  const [pending, setPending] = useState<CheckoutResult | null>(null);
  const [paid, setPaid] = useState(false);
  // Pixel consent (only when the shop uses ad pixels).
  const [consent, setConsent] = useState<'granted' | 'denied' | 'ask' | 'none'>('none');
  const formRef = useRef<HTMLFormElement>(null);

  useEffect(() => {
    const meta = document.createElement('meta');
    meta.name = 'robots';
    meta.content = 'noindex';
    document.head.appendChild(meta);
    return () => { document.head.removeChild(meta); };
  }, []);

  useEffect(() => {
    captureAttributionFromUrl();
    getPublicLandingProduct(productId)
      .then((data) => {
        setPage(data);
        setMethod(data.payment_options[0] ?? null);
        document.title = `${data.product.name} · Checkout`;
        const username = data.seller.username ?? data.seller.slug ?? '';
        const hasPixels = Object.keys(data.tracking ?? {}).length > 0;
        setConsent(hasPixels ? getPixelConsent(username) ?? 'ask' : 'none');
        if (getPixelConsent(username) === 'granted') {
          loadSellerPixels(data.tracking, username);
          trackSellerEvent('InitiateCheckout', { value: data.product.price, content_ids: [data.product.id], content_name: data.product.name });
        }
      })
      .catch((err) => setLoadError({
        message: err instanceof Error ? err.message : 'Produk tidak ditemukan',
        suspended: err instanceof ApiError && err.status === 410,
      }));
  }, [productId]);

  // Server-side price breakdown (coupon, quantity, shipping).
  useEffect(() => {
    if (!page) return undefined;
    let cancelled = false;
    const timer = window.setTimeout(() => {
      quoteLandingProduct(productId, { quantity, coupon_code: couponCode || undefined, destination_id: destination?.id, courier: courier ?? undefined, booking: isRental && bookingReady ? bookingChoice ?? undefined : undefined })
        .then((data) => { if (!cancelled) setQuote(data); })
        .catch(() => undefined);
    }, 150);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [page, productId, quantity, couponCode, destination, courier, isRental, bookingReady, bookingChoice]);

  // Real courier rates for the buyer's place (again when the quantity changes the weight).
  const courierMode = page?.product.type === 'physical' && page.product.shipping?.mode === 'courier';
  useEffect(() => {
    if (!courierMode || !destination) {
      setRates([]);
      setRatesState('idle');
      return undefined;
    }
    let cancelled = false;
    setRatesState('loading');
    setRatesMessage(null);
    const timer = window.setTimeout(() => {
      getShippingRates(productId, { destination_id: destination.id, quantity })
        .then((data) => {
          if (cancelled) return;
          setRates(data.items);
          setRatesState('ready');
          setRatesMessage(data.empty_message);
          // Keep the chosen courier when still offered, otherwise the cheapest.
          setCourier((current) => (current && data.items.some((r) => r.key === current) ? current : data.items[0]?.key ?? null));
        })
        .catch((err: unknown) => {
          if (cancelled) return;
          setRates([]);
          setCourier(null);
          setRatesState('error');
          setRatesMessage(err instanceof Error ? err.message : 'Ongkir belum bisa dihitung. Coba lagi.');
        });
    }, 200);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [courierMode, destination, productId, quantity]);

  // Watch the order until the gateway confirms the payment, then open the product.
  useEffect(() => {
    if (!pending || paid) return undefined;
    const timer = window.setInterval(async () => {
      try {
        const status = await getLandingOrderPublicStatus(pending.reference_id);
        if (status.status === 'paid' || status.status === 'fulfilled') {
          setPaid(true);
          window.clearInterval(timer);
          await firePurchase(pending.reference_id);
          if (status.access_path) window.location.href = status.access_path;
        } else if (status.status === 'expired' || status.status === 'failed') {
          window.clearInterval(timer);
          window.location.href = `/pesanan/${pending.reference_id}`;
        }
      } catch {
        /* keep polling */
      }
    }, 4000);
    return () => window.clearInterval(timer);
  }, [pending, paid]);

  const channels: PaymentChannel[] = page
    ? (page.payment_channels?.length
      ? page.payment_channels
      : page.payment_options.map((key: PaymentOption) => FALLBACK_CHANNELS[key] ?? { key, label: key.toUpperCase(), group: 'other' }))
    : [];

  const product = page?.product;
  const emailSuggestion = useMemo(() => suggestEmail(buyer.email), [buyer.email]);
  const phoneRequired = Boolean(product && (product.require_phone || product.type === 'physical'));
  const total = quote?.total ?? (product ? product.price * quantity : 0);

  const validate = (): Record<string, string> => {
    const e: Record<string, string> = {};
    if (isRental && !bookingReady) e.booking = 'Pilih jadwal dulu.';
    if (buyer.name.trim().length < 2) e.buyer_name = 'Isi nama kamu.';
    if (!EMAIL_PATTERN.test(buyer.email.trim())) e.buyer_email = 'Email belum benar. Link produk dikirim ke email ini.';
    if (phoneRequired && buyer.phone.replace(/\D/g, '').length < 8) e.buyer_phone = 'Isi nomor WhatsApp aktif.';
    product?.checkout_fields.forEach((f) => {
      if (f.required && !(fields[f.id] ?? '').trim()) e[`fields.${f.id}`] = `${f.label} wajib diisi.`;
    });
    if (product?.type === 'physical') {
      if (!shipping.recipient_name.trim()) e['shipping.recipient_name'] = 'Isi nama penerima.';
      if (shipping.phone.replace(/\D/g, '').length < 8) e['shipping.phone'] = 'Isi nomor HP penerima.';
      if (shipping.address.trim().length < 10) e['shipping.address'] = 'Tulis alamat lengkap (jalan, nomor, RT/RW).';
      if (courierMode) {
        if (!destination) e['shipping.destination_id'] = 'Pilih kecamatan tujuan dari daftar.';
        else if (!courier) e['shipping.courier'] = ratesState === 'loading' ? 'Tunggu ongkir selesai dihitung.' : 'Pilih kurir pengiriman.';
      } else {
        if (!shipping.city.trim()) e['shipping.city'] = 'Isi kota/kabupaten.';
        if (!/^\d{5}$/.test(shipping.postal_code.trim())) e['shipping.postal_code'] = 'Kode pos 5 angka.';
      }
    }
    if (!method) e.payment_method = 'Pembayaran sedang tidak tersedia.';
    return e;
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!product || !method) return;
    const found = validate();
    setErrors(found);
    setFormError(null);
    if (Object.keys(found).length > 0) {
      window.setTimeout(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus(), 0);
      return;
    }
    setSubmitting(true);
    trackSellerEvent('AddPaymentInfo', { value: total, content_ids: [product.id], content_name: product.name });
    try {
      const result = await checkoutLandingProduct(productId, {
        attribution: { ...(readAttribution() ?? {}), consent: consent === 'granted' ? 'granted' : 'denied' },
        quantity,
        booking: isRental && bookingReady ? bookingChoice ?? undefined : undefined,
        coupon_code: couponCode || undefined,
        payment_method: method,
        buyer_name: buyer.name.trim(),
        buyer_email: buyer.email.trim(),
        buyer_phone: buyer.phone.trim() || undefined,
        fields: product.checkout_fields.length ? fields : undefined,
        shipping: product.type === 'physical'
          ? courierMode && destination
            ? {
              ...shipping,
              city: destination.city ?? undefined,
              province: destination.province ?? undefined,
              postal_code: destination.postal_code && /^\d{5}$/.test(destination.postal_code) ? destination.postal_code : undefined,
              notes: shipping.notes || undefined,
              destination_id: destination.id,
              destination_label: destination.label,
              courier: courier ?? undefined,
            }
            : { ...shipping, province: shipping.province || undefined, notes: shipping.notes || undefined }
          : undefined,
        captcha_token: captcha?.token ?? undefined,
      });
      if (result.mode === 'qris' || result.mode === 'va') {
        setPending(result);
        window.scrollTo({ top: 0 });
      } else if (result.payment_url) {
        window.location.href = result.payment_url;
        return;
      }
    } catch (err) {
      if (err instanceof ApiError && err.code === 'CAPTCHA_REQUIRED' && typeof err.details.site_key === 'string') {
        const siteKey = err.details.site_key;
        setCaptcha((c) => ({ siteKey, token: null, round: (c?.round ?? 0) + 1 }));
      }
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) {
        setErrors(Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k, v[0]])));
      }
      setFormError(err instanceof Error ? err.message : 'Checkout gagal. Coba lagi.');
    }
    setSubmitting(false);
  };

  if (loadError) {
    return (
      <main className="flex min-h-[100svh] items-center justify-center bg-zinc-50 px-6 text-center text-zinc-900">
        <div>
          <XCircle className="mx-auto h-12 w-12 text-zinc-300" />
          <h1 className="mt-4 text-xl font-bold">{loadError.suspended ? 'Toko ini sedang nonaktif' : 'Produk tidak ditemukan'}</h1>
          <p className="mt-2 text-sm text-zinc-500">{loadError.suspended ? 'Produk dari toko ini sedang tidak bisa dibeli.' : 'Cek lagi tautan yang kamu buka.'}</p>
        </div>
      </main>
    );
  }

  if (!page || !product) {
    return (
      <main className="min-h-[100svh] bg-zinc-50 px-4 py-6 text-zinc-900" aria-busy="true">
        <div className="mx-auto max-w-lg space-y-4">
          <div className="h-28 animate-pulse rounded-3xl bg-zinc-200" />
          <div className="h-64 animate-pulse rounded-3xl bg-zinc-100" />
          <div className="h-40 animate-pulse rounded-3xl bg-zinc-100" />
        </div>
      </main>
    );
  }

  if (pending) {
    return (
      <main className="min-h-[100svh] bg-zinc-50 px-4 py-8 text-zinc-900">
        <div className="mx-auto max-w-md rounded-3xl bg-white p-6 shadow-sm ring-1 ring-zinc-100">
          {paid ? (
            <div className="text-center">
              <CheckCircle2 className="mx-auto h-14 w-14 text-emerald-500" />
              <h1 className="mt-3 text-xl font-bold text-zinc-900">Pembayaran berhasil 🎉</h1>
              <p className="mt-1 text-sm text-zinc-500">Membuka halaman produk kamu…</p>
            </div>
          ) : (
            <>
              <h1 className="text-center text-xl font-bold text-zinc-900">{pending.mode === 'qris' ? 'Scan QRIS untuk bayar' : 'Selesaikan pembayaran'}</h1>
              <p className="mt-1 text-center text-sm text-zinc-500">{pending.product_name}</p>
              <div className="mt-5">
                <OnPagePayment payment={pending} amount={pending.amount} expiresAt={pending.expires_at} reference={pending.reference_id} />
              </div>
              <Link to={`/pesanan/${pending.reference_id}`} className="mt-4 block text-center text-sm text-zinc-500 underline">Buka halaman status pesanan</Link>
              <p className="mt-3 text-center text-xs text-zinc-400">No. pesanan {pending.reference_id}. Link produk juga dikirim ke {buyer.email}.</p>
            </>
          )}
        </div>
      </main>
    );
  }

  const fieldError = (key: string) => errors[key] ? <p className="mt-1 text-sm text-rose-600">{errors[key]}</p> : null;
  const input = (key: string) => ({ 'aria-invalid': errors[key] ? true : undefined, className: cn(inputClass, errors[key] && 'border-rose-400') });

  return (
    <main className="min-h-[100svh] bg-zinc-50 pb-32 text-zinc-900">
      <header className="border-b border-zinc-200 bg-white">
        <div className="mx-auto flex max-w-lg items-center justify-between px-4 py-3">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold">{page.seller.name}</p>
            {page.seller.verified && <p className="flex items-center gap-1 text-xs text-emerald-700"><BadgeCheck className="h-3.5 w-3.5" /> Penjual Terverifikasi</p>}
          </div>
          <span className="flex items-center gap-1 text-xs text-zinc-500"><Lock className="h-3.5 w-3.5" /> Checkout aman</span>
        </div>
      </header>

      <form ref={formRef} onSubmit={submit} noValidate className="mx-auto max-w-lg space-y-4 px-4 pt-4">
        {/* Product */}
        <section className="flex gap-3 rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
          {product.image_url ? (
            <img src={product.image_url} alt="" className="h-20 w-20 shrink-0 rounded-2xl object-cover" />
          ) : (
            <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-zinc-100 text-2xl">🛍️</div>
          )}
          <div className="min-w-0 flex-1">
            <p className="text-xs font-medium text-zinc-500">{product.type_label}</p>
            <h1 className="text-base font-bold leading-snug">{product.name}</h1>
            <p className="mt-1 text-lg font-bold">
              {rupiah(product.price)}
              {product.type === 'rental' && product.booking && <span className="text-sm font-normal text-zinc-500"> / {product.booking.mode === 'daily' ? product.booking.unit_label : 'sesi'}</span>}
              {product.compare_at_price && <span className="ml-2 text-sm font-normal text-zinc-400 line-through">{rupiah(product.compare_at_price)}</span>}
            </p>
            {product.stock_left !== null && product.stock_left > 0 && <p className="text-xs text-amber-700">Sisa {product.stock_left}</p>}
          </div>
        </section>
        {product.description && (
          <details className="rounded-3xl bg-white p-4 text-sm ring-1 ring-zinc-100">
            <summary className="cursor-pointer font-semibold">Detail produk</summary>
            <div className="[&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1 [&_li]:my-0.5 mt-2 max-w-none text-zinc-600" dangerouslySetInnerHTML={{ __html: safeHtml(product.description) }} />
          </details>
        )}
        {!product.available && (
          <p className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{product.in_stock ? 'Produk ini sedang tidak dijual.' : 'Stok habis.'}</p>
        )}

        {isRental && product.booking && (
          <BookingPicker productId={productId} booking={product.booking} units={quantity} value={bookingChoice}
            onChange={(choice) => { setBookingChoice(choice); setErrors((e) => ({ ...e, booking: '' })); }}
            error={errors.booking || (bookingReady ? quote?.booking_error : null) || null} />
        )}

        {(product.type === 'physical' || product.type === 'rental') && product.max_quantity > 1 && (
          <section className="flex items-center justify-between rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
            <span className="text-sm font-semibold">{product.type === 'rental' ? 'Jumlah unit' : 'Jumlah'}</span>
            <div className="flex items-center gap-2">
              <button type="button" aria-label="Kurangi" onClick={() => setQuantity((q) => Math.max(1, q - 1))} className="flex h-11 w-11 items-center justify-center rounded-full border border-zinc-200"><Minus className="h-4 w-4" /></button>
              <span className="w-8 text-center text-base font-semibold">{quantity}</span>
              <button type="button" aria-label="Tambah" onClick={() => setQuantity((q) => Math.min(product.max_quantity, q + 1))} className="flex h-11 w-11 items-center justify-center rounded-full border border-zinc-200"><Plus className="h-4 w-4" /></button>
            </div>
          </section>
        )}

        {/* Buyer */}
        <section className="space-y-3 rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
          <h2 className="text-sm font-bold">Data pembeli</h2>
          <label className="block text-sm font-medium">
            Nama lengkap
            <input autoComplete="name" value={buyer.name} onChange={(e) => setBuyer((b) => ({ ...b, name: e.target.value }))} {...input('buyer_name')} />
            {fieldError('buyer_name')}
          </label>
          <label className="block text-sm font-medium">
            Email <span className="font-normal text-zinc-500">{product.type === 'rental' ? '(konfirmasi jadwal dikirim ke sini)' : product.type === 'physical' || product.type === 'service' ? '(bukti pembayaran dikirim ke sini)' : '(link produk dikirim ke sini)'}</span>
            <input type="email" inputMode="email" autoComplete="email" value={buyer.email} onChange={(e) => setBuyer((b) => ({ ...b, email: e.target.value }))} {...input('buyer_email')} />
            {emailSuggestion && (
              <button type="button" onClick={() => setBuyer((b) => ({ ...b, email: emailSuggestion }))} className="mt-1 min-h-11 text-left text-sm text-amber-700">
                Maksudnya <span className="font-semibold underline">{emailSuggestion}</span>?
              </button>
            )}
            {fieldError('buyer_email')}
          </label>
          <label className="block text-sm font-medium">
            Nomor WhatsApp {!phoneRequired && <span className="font-normal text-zinc-500">(opsional)</span>}
            <input type="tel" inputMode="tel" autoComplete="tel" placeholder="08xxxxxxxxxx" value={buyer.phone} onChange={(e) => setBuyer((b) => ({ ...b, phone: e.target.value }))} {...input('buyer_phone')} />
            {fieldError('buyer_phone')}
          </label>
        </section>

        {/* Seller's extra questions */}
        {product.checkout_fields.length > 0 && (
          <section className="space-y-3 rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
            <h2 className="text-sm font-bold">{product.type === 'service' ? 'Kebutuhan kamu' : 'Info tambahan'}</h2>
            {product.checkout_fields.map((f) => {
              const key = `fields.${f.id}`;
              const value = fields[f.id] ?? '';
              const set = (v: string) => setFields((prev) => ({ ...prev, [f.id]: v }));
              return (
                <label key={f.id} className="block text-sm font-medium">
                  {f.label} {!f.required && <span className="font-normal text-zinc-500">(opsional)</span>}
                  {f.type === 'textarea' ? (
                    <textarea rows={4} value={value} onChange={(e) => set(e.target.value)} aria-invalid={errors[key] ? true : undefined} className={cn(inputClass, 'py-3', errors[key] && 'border-rose-400')} />
                  ) : f.type === 'select' ? (
                    <select value={value} onChange={(e) => set(e.target.value)} {...input(key)}>
                      <option value="">Pilih…</option>
                      {f.options.map((o) => <option key={o} value={o}>{o}</option>)}
                    </select>
                  ) : (
                    <input inputMode={f.type === 'number' ? 'decimal' : undefined} value={value} onChange={(e) => set(e.target.value)} {...input(key)} />
                  )}
                  {fieldError(key)}
                </label>
              );
            })}
          </section>
        )}

        {/* Shipping */}
        {product.type === 'physical' && (
          <section className="space-y-3 rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
            <h2 className="text-sm font-bold">Alamat pengiriman</h2>
            <button type="button" onClick={() => setShipping((s) => ({ ...s, recipient_name: s.recipient_name || buyer.name, phone: s.phone || buyer.phone }))} className="min-h-11 text-sm font-medium text-zinc-600 underline">
              Pakai data pembeli
            </button>
            <label className="block text-sm font-medium">Nama penerima<input autoComplete="shipping name" value={shipping.recipient_name} onChange={(e) => setShipping((s) => ({ ...s, recipient_name: e.target.value }))} {...input('shipping.recipient_name')} />{fieldError('shipping.recipient_name')}</label>
            <label className="block text-sm font-medium">Nomor HP penerima<input type="tel" inputMode="tel" value={shipping.phone} onChange={(e) => setShipping((s) => ({ ...s, phone: e.target.value }))} {...input('shipping.phone')} />{fieldError('shipping.phone')}</label>
            <label className="block text-sm font-medium">Alamat lengkap
              <textarea rows={3} autoComplete="shipping street-address" value={shipping.address} onChange={(e) => setShipping((s) => ({ ...s, address: e.target.value }))} aria-invalid={errors['shipping.address'] ? true : undefined} className={cn(inputClass, 'py-3', errors['shipping.address'] && 'border-rose-400')} />
              {fieldError('shipping.address')}
            </label>
            {courierMode ? (
              <>
                <DestinationSearch label="Kecamatan tujuan" value={destination} onChange={(d) => { setDestination(d); setCourier(null); }} error={errors['shipping.destination_id'] ?? null} inputClassName="text-base" />
                {destination && (
                  <fieldset className="space-y-2" aria-busy={ratesState === 'loading'}>
                    <legend className="text-sm font-medium">Kurir</legend>
                    {ratesState === 'loading' && <p className="flex items-center gap-2 text-sm text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" /> Menghitung ongkir…</p>}
                    {ratesState !== 'loading' && ratesMessage && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{ratesMessage}</p>}
                    {ratesState === 'ready' && rates.map((rate) => (
                      <label key={rate.key} className={cn('flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl border p-3', courier === rate.key ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200')}>
                        <input type="radio" name="courier" className="h-4 w-4" checked={courier === rate.key} onChange={() => setCourier(rate.key)} />
                        <span className="min-w-0 flex-1">
                          <span className="block text-sm font-semibold text-zinc-900">{rate.label}</span>
                          <span className="block text-xs text-zinc-500">{[rate.courier_name, etdText(rate.etd) && `estimasi ${etdText(rate.etd)}`].filter(Boolean).join(' · ')}</span>
                        </span>
                        <span className="shrink-0 text-sm font-semibold text-zinc-900">{rupiah(rate.cost)}</span>
                      </label>
                    ))}
                    {fieldError('shipping.courier')}
                  </fieldset>
                )}
              </>
            ) : (
            <>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm font-medium">Kota/Kab.<input autoComplete="shipping address-level2" value={shipping.city} onChange={(e) => setShipping((s) => ({ ...s, city: e.target.value }))} {...input('shipping.city')} />{fieldError('shipping.city')}</label>
              <label className="block text-sm font-medium">Kode pos<input inputMode="numeric" autoComplete="shipping postal-code" maxLength={5} value={shipping.postal_code} onChange={(e) => setShipping((s) => ({ ...s, postal_code: e.target.value.replace(/\D/g, '') }))} {...input('shipping.postal_code')} />{fieldError('shipping.postal_code')}</label>
            </div>
            <label className="block text-sm font-medium">Provinsi <span className="font-normal text-zinc-500">(opsional)</span><input value={shipping.province} onChange={(e) => setShipping((s) => ({ ...s, province: e.target.value }))} className={inputClass} /></label>
            </>
            )}
            <label className="block text-sm font-medium">Catatan untuk kurir <span className="font-normal text-zinc-500">(opsional)</span><input value={shipping.notes} onChange={(e) => setShipping((s) => ({ ...s, notes: e.target.value }))} className={inputClass} /></label>
            {product.shipping?.mode === 'manual' && <p className="text-xs text-zinc-500">Ongkir dikonfirmasi penjual setelah pesanan masuk.</p>}
          </section>
        )}

        {/* Coupon */}
        <section className="rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
          <label className="block text-sm font-bold">Kode kupon</label>
          <div className="mt-2 flex gap-2">
            <div className="relative flex-1">
              <Tag className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
              <input value={couponInput} onChange={(e) => setCouponInput(e.target.value.toUpperCase())} placeholder="Opsional" className={cn(inputClass, 'pl-9 uppercase')} aria-invalid={errors.coupon_code ? true : undefined} />
            </div>
            <button type="button" onClick={() => setCouponCode(couponInput.trim())} className="min-h-12 rounded-xl border border-zinc-300 px-4 text-sm font-semibold">Pakai</button>
          </div>
          {couponCode && quote?.coupon && <p className="mt-2 text-sm text-emerald-700">Kupon {quote.coupon.code} dipakai: hemat {rupiah(quote.discount)}</p>}
          {couponCode && quote?.coupon_error && <p className="mt-2 text-sm text-rose-600">{quote.coupon_error}</p>}
          {fieldError('coupon_code')}
        </section>

        {/* Payment method */}
        <section className="space-y-2 rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
          <h2 className="text-sm font-bold">Metode pembayaran</h2>
          {channels.length === 0 && <p className="text-sm text-rose-600">Pembayaran sedang tidak tersedia. Coba lagi nanti.</p>}
          <div role="radiogroup" className="space-y-4">
            {CHANNEL_GROUPS.map(({ group, title, hint, icon: Icon }) => {
              const options = channels.filter((channel) => (group === 'other' ? !KNOWN_GROUPS.includes(channel.group) : channel.group === group));
              if (options.length === 0) return null;
              return (
                <div key={group}>
                  <p className="flex items-center gap-1.5 text-xs font-semibold text-zinc-500"><Icon className="h-4 w-4" /> {title} <span className="font-normal">· {hint}</span></p>
                  <div className={cn('mt-2 grid gap-2', options.length > 1 && 'grid-cols-2')}>
                    {options.map((channel) => (
                      <button
                        key={channel.key}
                        type="button"
                        role="radio"
                        aria-checked={method === channel.key}
                        onClick={() => setMethod(channel.key)}
                        className={cn('flex min-h-12 items-center gap-2 rounded-2xl border px-3 text-left text-sm font-semibold transition', method === channel.key ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200')}
                      >
                        <span className={cn('h-4 w-4 shrink-0 rounded-full border-2', method === channel.key ? 'border-[5px] border-zinc-900' : 'border-zinc-300')} />
                        <span className="min-w-0 truncate">{channel.label.replace(' Virtual Account', '')}</span>
                      </button>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </section>

        {captcha && (
          <section className="rounded-3xl bg-white p-4 ring-1 ring-zinc-100" aria-label="Verifikasi">
            <p className="mb-2 text-sm text-zinc-600">Demi keamanan, pastikan kamu bukan robot.</p>
            <TurnstileWidget key={captcha.round} siteKey={captcha.siteKey} onToken={(token) => setCaptcha((c) => (c ? { ...c, token } : c))} />
          </section>
        )}

        {/* Summary */}
        <section className="rounded-3xl bg-white p-4 text-sm ring-1 ring-zinc-100">
          <dl className="space-y-2">
            {isRental && quote?.booking && <div className="flex justify-between gap-3" data-booking-summary><dt className="text-zinc-500">Jadwal</dt><dd className="text-right font-medium">{quote.booking.label}</dd></div>}
            <div className="flex justify-between"><dt className="text-zinc-500">Harga{isRental && quote?.booking ? ` ${rupiah(product.price)} × ${quote.booking.duration} ${quote.booking.unit}${quantity > 1 ? ` × ${quantity} unit` : ''}` : quantity > 1 ? ` × ${quantity}` : ''}</dt><dd>{rupiah(quote?.subtotal ?? product.price * quantity)}</dd></div>
            {(quote?.discount ?? 0) > 0 && <div className="flex justify-between text-emerald-700"><dt>Diskon</dt><dd>−{rupiah(quote?.discount ?? 0)}</dd></div>}
            {product.type === 'physical' && (
              <div className="flex justify-between"><dt className="text-zinc-500">Ongkir</dt><dd>{product.shipping?.mode === 'free' ? 'Gratis' : product.shipping?.mode === 'manual' ? 'Dikonfirmasi penjual'
                : courierMode ? (quote?.shipping_rate ? `${quote.shipping_rate.label} · ${rupiah(quote.shipping)}` : <span className="text-zinc-500">{destination ? 'Pilih kurir' : 'Isi alamat dulu'}</span>)
                  : rupiah(quote?.shipping ?? 0)}</dd></div>
            )}
            <div className="flex justify-between border-t border-zinc-100 pt-2 text-base font-bold"><dt>Total bayar</dt><dd>{rupiah(total)}</dd></div>
          </dl>
          <p className="mt-3 flex items-start gap-2 text-xs leading-5 text-zinc-500">
            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0" />
            <span>Pembayaran diproses Hellom. Dengan membayar kamu setuju dengan <Link to="/kebijakan/syarat" className="underline">syarat</Link> dan <Link to="/kebijakan/refund" className="underline">kebijakan refund</Link>.</span>
          </p>
        </section>

        {formError && <p role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{formError}</p>}

        {consent === 'ask' && (
          <div role="dialog" aria-label="Persetujuan cookie" className="rounded-3xl border border-zinc-200 bg-white p-4 text-sm ring-1 ring-zinc-100">
            <p>Toko ini memakai cookie & piksel iklan (Meta, Google, TikTok) untuk mengukur iklan. Boleh?</p>
            <div className="mt-3 grid grid-cols-2 gap-2">
              <button type="button" onClick={() => { const u = page.seller.username ?? page.seller.slug ?? ''; setPixelConsent(u, 'denied'); setConsent('denied'); }} className="min-h-11 rounded-xl border border-zinc-300 font-semibold">Tolak</button>
              <button type="button" onClick={() => {
                const u = page.seller.username ?? page.seller.slug ?? '';
                setPixelConsent(u, 'granted');
                setConsent('granted');
                loadSellerPixels(page.tracking, u);
                trackSellerEvent('InitiateCheckout', { value: product.price, content_ids: [product.id], content_name: product.name });
              }} className="min-h-11 rounded-xl bg-zinc-900 font-semibold text-white">Terima</button>
            </div>
            <Link to="/kebijakan/privasi" className="mt-2 inline-block text-xs text-zinc-500 underline">Kebijakan privasi</Link>
          </div>
        )}

        {/* Sticky pay button */}
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 backdrop-blur" style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
          <div className="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
            <div className="min-w-0">
              <p className="text-xs text-zinc-500">Total</p>
              <p className="text-lg font-bold">{rupiah(total)}</p>
            </div>
            <button
              type="submit"
              disabled={submitting || !product.available || !method || (captcha !== null && !captcha.token)}
              className="ml-auto flex min-h-12 flex-1 items-center justify-center gap-2 rounded-2xl bg-zinc-900 px-4 text-base font-bold text-white disabled:opacity-40"
            >
              {submitting ? <Loader2 className="h-5 w-5 animate-spin" /> : <Lock className="h-4 w-4" />}
              Bayar sekarang
            </button>
          </div>
        </div>
      </form>
    </main>
  );
}
