import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { BadgeCheck, CheckCircle2, Loader2, Lock, Minus, Plus, QrCode, ShieldCheck, Tag, Wallet, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { safeHtml } from '@/lib/safeHtml';
import { EMAIL_PATTERN, suggestEmail } from '@/lib/emailTypo';
import { captureAttributionFromUrl, firePurchase, getPixelConsent, loadSellerPixels, readAttribution, setPixelConsent, trackSellerEvent } from '@/lib/sellerPixels';
import { ApiError, checkoutLandingProduct, getLandingOrderPublicStatus, getPublicLandingProduct, quoteLandingProduct } from '@/lib/hellomApi';
import type { CheckoutQuote, CheckoutResult, PaymentOption, PublicProductPage } from '@/lib/hellomApi';
import TurnstileWidget from '@/components/checkout/TurnstileWidget';

// Buyer checkout for a Hellom Page product (/beli/:productId), no login. Mobile-first:
// one page, big sticky pay button. Prices come from the server (quote); the order is
// only marked paid by the gateway, never by this page.
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const inputClass = 'min-h-12 w-full rounded-xl border border-zinc-300 bg-white px-3 text-base outline-none transition focus:border-zinc-900';
const PAYMENT_LABELS: Record<PaymentOption, { title: string; hint: string }> = {
  qris: { title: 'QRIS', hint: 'Scan pakai GoPay, OVO, DANA, ShopeePay, atau m-banking' },
  other: { title: 'Virtual Account & lainnya', hint: 'Transfer bank (VA), e-wallet, atau gerai retail' },
};

type Shipping = { recipient_name: string; phone: string; address: string; city: string; province: string; postal_code: string; notes: string };

export default function CheckoutPage() {
  const { productId = '' } = useParams();
  const [page, setPage] = useState<PublicProductPage | null>(null);
  const [loadError, setLoadError] = useState<{ message: string; suspended: boolean } | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [buyer, setBuyer] = useState({ name: '', email: '', phone: '' });
  const [fields, setFields] = useState<Record<string, string>>({});
  const [shipping, setShipping] = useState<Shipping>({ recipient_name: '', phone: '', address: '', city: '', province: '', postal_code: '', notes: '' });
  const [couponInput, setCouponInput] = useState('');
  const [couponCode, setCouponCode] = useState('');
  const [quote, setQuote] = useState<CheckoutQuote | null>(null);
  const [method, setMethod] = useState<PaymentOption | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Turnstile, only after the server asks for it (repeated checkouts). `round` remounts it after a failed try.
  const [captcha, setCaptcha] = useState<{ siteKey: string; token: string | null; round: number } | null>(null);
  const [qr, setQr] = useState<CheckoutResult | null>(null);
  const [qrPaid, setQrPaid] = useState(false);
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
      quoteLandingProduct(productId, { quantity, coupon_code: couponCode || undefined })
        .then((data) => { if (!cancelled) setQuote(data); })
        .catch(() => undefined);
    }, 150);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [page, productId, quantity, couponCode]);

  // QRIS: watch the order until the gateway confirms it.
  useEffect(() => {
    if (!qr || qrPaid) return undefined;
    const timer = window.setInterval(async () => {
      try {
        const status = await getLandingOrderPublicStatus(qr.reference_id);
        if (status.status === 'paid' || status.status === 'fulfilled') {
          setQrPaid(true);
          window.clearInterval(timer);
          await firePurchase(qr.reference_id);
          if (status.access_path) window.location.href = status.access_path;
        }
      } catch {
        /* keep polling */
      }
    }, 4000);
    return () => window.clearInterval(timer);
  }, [qr, qrPaid]);

  const product = page?.product;
  const emailSuggestion = useMemo(() => suggestEmail(buyer.email), [buyer.email]);
  const phoneRequired = Boolean(product && (product.require_phone || product.type === 'physical'));
  const total = quote?.total ?? (product ? product.price * quantity : 0);

  const validate = (): Record<string, string> => {
    const e: Record<string, string> = {};
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
      if (!shipping.city.trim()) e['shipping.city'] = 'Isi kota/kabupaten.';
      if (!/^\d{5}$/.test(shipping.postal_code.trim())) e['shipping.postal_code'] = 'Kode pos 5 angka.';
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
        coupon_code: couponCode || undefined,
        payment_method: method,
        buyer_name: buyer.name.trim(),
        buyer_email: buyer.email.trim(),
        buyer_phone: buyer.phone.trim() || undefined,
        fields: product.checkout_fields.length ? fields : undefined,
        shipping: product.type === 'physical' ? { ...shipping, province: shipping.province || undefined, notes: shipping.notes || undefined } : undefined,
        captcha_token: captcha?.token ?? undefined,
      });
      if (result.mode === 'qris') {
        setQr(result);
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
      <main className="flex min-h-[100svh] items-center justify-center bg-zinc-50 px-6 text-center">
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
      <main className="min-h-[100svh] bg-zinc-50 px-4 py-6" aria-busy="true">
        <div className="mx-auto max-w-lg space-y-4">
          <div className="h-28 animate-pulse rounded-3xl bg-zinc-200" />
          <div className="h-64 animate-pulse rounded-3xl bg-zinc-100" />
          <div className="h-40 animate-pulse rounded-3xl bg-zinc-100" />
        </div>
      </main>
    );
  }

  if (qr) {
    return (
      <main className="min-h-[100svh] bg-zinc-50 px-4 py-8">
        <div className="mx-auto max-w-md rounded-3xl bg-white p-6 text-center shadow-sm ring-1 ring-zinc-100">
          {qrPaid ? (
            <>
              <CheckCircle2 className="mx-auto h-14 w-14 text-emerald-500" />
              <h1 className="mt-3 text-xl font-bold">Pembayaran berhasil 🎉</h1>
              <p className="mt-1 text-sm text-zinc-500">Membuka halaman produk kamu…</p>
            </>
          ) : (
            <>
              <h1 className="text-xl font-bold">Scan QRIS untuk bayar</h1>
              <p className="mt-1 text-sm text-zinc-500">Total <span className="font-semibold text-zinc-900">{rupiah(qr.amount)}</span></p>
              <div className="mx-auto mt-4 inline-block rounded-2xl border border-zinc-200 bg-white p-3">
                {qr.qr_image_url ? <img src={qr.qr_image_url} alt="Kode QRIS" className="h-60 w-60 object-contain" /> : <div className="flex h-60 w-60 items-center justify-center text-sm text-zinc-400">QR tidak tersedia</div>}
              </div>
              <p className="mt-4 flex items-center justify-center gap-2 text-sm text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" /> Menunggu pembayaran…</p>
              {qr.qr_image_url && (
                <a href={`${qr.qr_image_url}?download=1`} className="mt-4 flex min-h-12 w-full items-center justify-center rounded-2xl border border-zinc-200 text-sm font-semibold">
                  Simpan gambar QR
                </a>
              )}
              <Link to={`/pesanan/${qr.reference_id}`} className="mt-3 block text-sm text-zinc-500 underline">Buka halaman status pesanan</Link>
              <p className="mt-4 text-xs text-zinc-400">No. pesanan {qr.reference_id}. Link produk juga dikirim ke {buyer.email}.</p>
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

        {product.type === 'physical' && product.max_quantity > 1 && (
          <section className="flex items-center justify-between rounded-3xl bg-white p-4 ring-1 ring-zinc-100">
            <span className="text-sm font-semibold">Jumlah</span>
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
            Email <span className="font-normal text-zinc-500">(link produk dikirim ke sini)</span>
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
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm font-medium">Kota/Kab.<input autoComplete="shipping address-level2" value={shipping.city} onChange={(e) => setShipping((s) => ({ ...s, city: e.target.value }))} {...input('shipping.city')} />{fieldError('shipping.city')}</label>
              <label className="block text-sm font-medium">Kode pos<input inputMode="numeric" autoComplete="shipping postal-code" maxLength={5} value={shipping.postal_code} onChange={(e) => setShipping((s) => ({ ...s, postal_code: e.target.value.replace(/\D/g, '') }))} {...input('shipping.postal_code')} />{fieldError('shipping.postal_code')}</label>
            </div>
            <label className="block text-sm font-medium">Provinsi <span className="font-normal text-zinc-500">(opsional)</span><input value={shipping.province} onChange={(e) => setShipping((s) => ({ ...s, province: e.target.value }))} className={inputClass} /></label>
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
          {page.payment_options.length === 0 && <p className="text-sm text-rose-600">Pembayaran sedang tidak tersedia. Coba lagi nanti.</p>}
          <div role="radiogroup" className="space-y-2">
            {page.payment_options.map((option) => (
              <button
                key={option}
                type="button"
                role="radio"
                aria-checked={method === option}
                onClick={() => setMethod(option)}
                className={cn('flex min-h-14 w-full items-center gap-3 rounded-2xl border p-3 text-left transition', method === option ? 'border-zinc-900 bg-zinc-50' : 'border-zinc-200')}
              >
                {option === 'qris' ? <QrCode className="h-6 w-6 shrink-0" /> : <Wallet className="h-6 w-6 shrink-0" />}
                <span className="min-w-0">
                  <span className="block text-sm font-semibold">{PAYMENT_LABELS[option].title}</span>
                  <span className="block text-xs text-zinc-500">{PAYMENT_LABELS[option].hint}</span>
                </span>
                <span className={cn('ml-auto h-5 w-5 shrink-0 rounded-full border-2', method === option ? 'border-[6px] border-zinc-900' : 'border-zinc-300')} />
              </button>
            ))}
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
            <div className="flex justify-between"><dt className="text-zinc-500">Harga{quantity > 1 ? ` × ${quantity}` : ''}</dt><dd>{rupiah(quote?.subtotal ?? product.price * quantity)}</dd></div>
            {(quote?.discount ?? 0) > 0 && <div className="flex justify-between text-emerald-700"><dt>Diskon</dt><dd>−{rupiah(quote?.discount ?? 0)}</dd></div>}
            {product.type === 'physical' && (
              <div className="flex justify-between"><dt className="text-zinc-500">Ongkir</dt><dd>{product.shipping?.mode === 'free' ? 'Gratis' : product.shipping?.mode === 'manual' ? 'Dikonfirmasi penjual' : rupiah(quote?.shipping ?? 0)}</dd></div>
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
