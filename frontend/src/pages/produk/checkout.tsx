import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Loader2, Lock, Mail, Phone } from 'lucide-react';
import { getGuestCheckoutOptions, getImageUrl, getToken, startGuestCheckout, type GuestCheckoutOptions } from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';
import CheckoutShell, { formatRupiah, inputClass, panelClass, primaryButtonClass } from '@/components/checkout/CheckoutShell';

// A selectable way to pay: an iPaymu channel (VA/QRIS on our page), the gateway's
// hosted page, or a manual transfer method.
type PaymentChoice = { id: string; label: string; hint: string; flow: 'gateway' | 'manual'; channel?: string; manualKey?: string };

export default function GuestProductCheckoutPage() {
  const { slug = '' } = useParams();
  const navigate = useNavigate();
  const [options, setOptions] = useState<GuestCheckoutOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [choiceId, setChoiceId] = useState('');
  const [agreed, setAgreed] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Logged-in buyers use the dashboard checkout (purchase goes straight to their account).
  useEffect(() => {
    if (getToken()) navigate(`/dashboard/products/${slug}/checkout`, { replace: true });
  }, [navigate, slug]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getGuestCheckoutOptions(slug)
      .then((data) => { if (!cancelled) setOptions(data); })
      .catch((err) => { if (!cancelled) setLoadError(err instanceof Error ? err.message : 'Produk tidak ditemukan'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [slug]);

  useEffect(() => {
    if (options) document.title = `Checkout ${options.product.name}`;
  }, [options]);

  const choices = useMemo<PaymentChoice[]>(() => {
    if (!options) return [];
    const { gateway, manual } = options.payment;
    const list: PaymentChoice[] = [];
    if (gateway.ready) {
      if (gateway.channels.length > 0) {
        gateway.channels.forEach((c) => list.push({
          id: `gw:${c.key}`,
          label: c.label,
          hint: c.type === 'qris' ? 'Scan QR dari e-wallet / m-banking' : c.type === 'cstore' ? 'Bayar di kasir' : 'Virtual account, terverifikasi otomatis',
          flow: 'gateway',
          channel: c.key,
        }));
      } else {
        list.push({ id: 'gw:hosted', label: 'Bayar online', hint: 'QRIS, virtual account, e-wallet — terverifikasi otomatis', flow: 'gateway' });
      }
    }
    if (manual.enabled) {
      manual.methods.forEach((m) => list.push({
        id: `manual:${m.key}`,
        label: m.label,
        hint: 'Transfer manual, dikonfirmasi admin',
        flow: 'manual',
        manualKey: m.key,
      }));
    }
    return list;
  }, [options]);

  useEffect(() => {
    if (!choiceId && choices[0]) setChoiceId(choices[0].id);
  }, [choiceId, choices]);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    const choice = choices.find((c) => c.id === choiceId);
    if (!choice) {
      setError('Pilih metode pembayaran.');
      return;
    }
    if (!agreed) {
      setError('Mohon setujui bahwa pembelian bersifat final dan tidak dapat di-refund.');
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      const result = await startGuestCheckout(slug, {
        email: email.trim(),
        phone: phone.trim() || undefined,
        payment_flow: choice.flow,
        gateway_channel: choice.channel,
        manual_payment_method: choice.manualKey,
      });
      navigate(`/produk/checkout/${result.checkout_token}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Checkout gagal. Coba lagi.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <CheckoutShell>
        <div className="flex justify-center py-24"><Loader2 className="h-6 w-6 animate-spin text-[#F6B400]" /></div>
      </CheckoutShell>
    );
  }

  if (!options) {
    return (
      <CheckoutShell>
        <div className={`${panelClass} mx-auto max-w-lg text-center`}>
          <p className="text-lg font-semibold">Produk tidak ditemukan</p>
          <p className="mt-2 text-sm text-[#8B8B90]">{loadError}</p>
          <Link to="/produk" className="mt-6 inline-block text-sm font-semibold text-[#F6B400]">Lihat semua produk</Link>
        </div>
      </CheckoutShell>
    );
  }

  const { product } = options;

  if (!options.guest_checkout_available) {
    const detailUrl = `/dashboard/products/${product.slug}/checkout`;
    return (
      <CheckoutShell>
        <div className={`${panelClass} mx-auto max-w-lg text-center`}>
          <p className="text-lg font-semibold">{product.name}</p>
          <p className="mt-2 text-sm text-[#8B8B90]">Produk ini diaktifkan dari akun Hellom. Masuk atau daftar gratis untuk melanjutkan.</p>
          <Link
            to="/login"
            onClick={() => savePendingCheckoutIntent({ kind: 'digital_product', product_id: product.id, product_slug: product.slug, return_to: detailUrl })}
            className={`${primaryButtonClass} mt-6`}
          >
            Masuk untuk melanjutkan
          </Link>
        </div>
      </CheckoutShell>
    );
  }

  return (
    <CheckoutShell>
      <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
        <form onSubmit={handleSubmit} className={`${panelClass} space-y-7`}>
          <div>
            <p className="text-xs uppercase tracking-[0.3em] text-[#F6B400]">Checkout</p>
            <h1 className="mt-2 text-2xl font-semibold">Data pembeli</h1>
            <p className="mt-1 text-sm text-[#8B8B90]">Tanpa perlu daftar. Akses produk dikirim ke email Anda setelah pembayaran berhasil.</p>
          </div>

          <div className="space-y-4">
            <label className="block">
              <span className="mb-2 flex items-center gap-2 text-sm font-medium text-white/80"><Mail className="h-4 w-4" /> Email <span className="text-[#F6B400]">*</span></span>
              <input type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className={inputClass} placeholder="nama@email.com" />
            </label>
            <label className="block">
              <span className="mb-2 flex items-center gap-2 text-sm font-medium text-white/80"><Phone className="h-4 w-4" /> No. HP / WhatsApp <span className="text-xs text-[#8B8B90]">(opsional)</span></span>
              <input type="tel" autoComplete="tel" value={phone} onChange={(e) => setPhone(e.target.value)} className={inputClass} placeholder="08xxxxxxxxxx" />
            </label>
          </div>

          <div>
            <p className="mb-3 text-sm font-medium text-white/80">Metode pembayaran</p>
            {choices.length === 0 ? (
              <p className="rounded-2xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
                Pembayaran sedang tidak tersedia. Silakan coba beberapa saat lagi.
              </p>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2">
                {choices.map((c) => (
                  <label
                    key={c.id}
                    className={`flex cursor-pointer flex-col rounded-2xl border px-4 py-3 transition ${
                      choiceId === c.id ? 'border-[#F6B400] bg-[#F6B400]/10' : 'border-white/[0.10] bg-black/25 hover:border-white/25'
                    }`}
                  >
                    <input type="radio" name="payment" value={c.id} checked={choiceId === c.id} onChange={() => setChoiceId(c.id)} className="sr-only" />
                    <span className="text-sm font-semibold">{c.label}</span>
                    <span className="mt-0.5 text-xs text-[#8B8B90]">{c.hint}</span>
                  </label>
                ))}
              </div>
            )}
          </div>

          <label className="flex items-start gap-3 text-sm text-[#B5B5BA]">
            <input type="checkbox" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} className="mt-1 h-4 w-4 accent-[#F6B400]" />
            <span>Saya mengerti produk digital bersifat final: setelah dibayar tidak dapat dibatalkan atau di-refund.</span>
          </label>

          {error ? <div className="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{error}</div> : null}

          <button type="submit" disabled={submitting || choices.length === 0} className={primaryButtonClass}>
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Lock className="h-4 w-4" />}
            {submitting ? 'Memproses...' : `Bayar ${formatRupiah(product.price)}`}
          </button>
          <p className="text-center text-xs text-[#8B8B90]">
            Sudah punya akun? <Link to="/login" className="font-semibold text-[#F6B400]">Masuk</Link>
          </p>
        </form>

        <aside className={`${panelClass} h-fit space-y-4`}>
          <div className="aspect-[16/10] overflow-hidden rounded-2xl bg-[#0E0E11]">
            {product.thumbnail_url ? <img src={getImageUrl(product.thumbnail_url)} alt={product.name} className="h-full w-full object-cover" /> : null}
          </div>
          <div>
            {product.category ? <p className="text-[10px] uppercase tracking-[0.28em] text-[#F6B400]">{product.category}</p> : null}
            <p className="mt-2 text-lg font-semibold">{product.name}</p>
            {product.tagline ? <p className="mt-1 text-sm text-[#8B8B90]">{product.tagline}</p> : null}
          </div>
          <div className="space-y-2 border-t border-white/[0.08] pt-4 text-sm">
            <div className="flex justify-between"><span className="text-[#8B8B90]">Harga</span><span>{formatRupiah(product.price)}</span></div>
            <div className="flex justify-between"><span className="text-[#8B8B90]">Akses</span><span>Selamanya, sekali beli</span></div>
            <div className="flex justify-between border-t border-white/[0.08] pt-3 text-base font-bold"><span>Total</span><span className="text-[#F6B400]">{formatRupiah(product.price)}</span></div>
          </div>
        </aside>
      </div>
    </CheckoutShell>
  );
}
