import { useEffect, useMemo, useState, type ElementType } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  Check, CheckCircle2, Crown, LockKeyhole, Loader2, ShoppingCart, Tag, Wallet, X,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { getToken } from '@/services/api/client';
import {
  checkoutConfirmWallet,
  checkoutStart,
  getPaymentGatewayStatus,
  getPricingMatrix,
  getWalletOverview,
  type PaymentGatewayStatus,
  type PricingMatrixPlan,
} from '@/services/api/billing';
import {
  getPosCheckoutOptions,
  previewPosCheckoutPromo,
  startGuestPosCheckout,
  type PosCheckoutPlan,
  type PosPaymentChannel,
} from '@/services/api/posCheckout';
import { trackPlatformMetaEvent } from '@/lib/platformMarketing';
import GatewayPaymentFrame from '@/components/GatewayPaymentFrame';

type Props = {
  isOpen: boolean;
  onClose: () => void;
  appName: string;
  appSlug?: string;
  appIcon?: ElementType;
  onSuccess?: () => void;
  context?: 'member' | 'guest';
  initialPlanSlug?: string;
  initialPromo?: string;
};

type Plan = Pick<PosCheckoutPlan, 'id' | 'slug' | 'name' | 'price' | 'description' | 'features' | 'is_recommended' | 'max_outlets'>;
type GuestFields = { name: string; email: string; phone: string; business_name: string };
type PaymentFlow = 'wallet' | 'direct';

const EMPTY_GUEST: GuestFields = { name: '', email: '', phone: '', business_name: '' };
const rupiah = (amount: number) => `Rp ${Math.max(0, Math.round(amount)).toLocaleString('id-ID')}`;

function featureList(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.map((item) => {
    if (typeof item === 'string') return item;
    if (item && typeof item === 'object') {
      const feature = item as Record<string, unknown>;
      return String(feature.name ?? feature.label ?? feature.title ?? '');
    }
    return '';
  }).filter(Boolean).slice(0, 7);
}

function normalizedPlan(plan: PosCheckoutPlan | PricingMatrixPlan): Plan {
  return {
    id: plan.id,
    slug: plan.slug,
    name: plan.name,
    price: Number(plan.price),
    description: String(plan.description ?? ''),
    features: plan.features,
    is_recommended: Boolean(plan.is_recommended),
    max_outlets: Number(plan.max_outlets ?? 1),
  };
}

function newEventId(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `hellom-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}

export default function CheckoutModal({
  isOpen,
  onClose,
  appName,
  appSlug = 'pos',
  appIcon: AppIcon = ShoppingCart,
  onSuccess,
  context,
  initialPlanSlug,
  initialPromo,
}: Props) {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const guest = context === 'guest' || (!context && !getToken());
  const storageKey = `hellom-checkout-v2:${guest ? 'guest' : 'member'}:${appSlug}`;
  const [plans, setPlans] = useState<Plan[]>([]);
  const [channels, setChannels] = useState<PosPaymentChannel[]>([]);
  const [gatewayReady, setGatewayReady] = useState(false);
  const [manualMode, setManualMode] = useState(false);
  const [manualMethods, setManualMethods] = useState<Array<{ key: string; label: string }>>([]);
  const [manualMethod, setManualMethod] = useState('');
  const [walletBalance, setWalletBalance] = useState(0);
  const [walletAvailable, setWalletAvailable] = useState(false);
  const [selectedPlan, setSelectedPlan] = useState('');
  const [paymentFlow, setPaymentFlow] = useState<PaymentFlow>('direct');
  const [channel, setChannel] = useState('');
  const [promoInput, setPromoInput] = useState(initialPromo || searchParams.get('promo') || '');
  const [promo, setPromo] = useState<{ code: string; discount_amount: number; final_amount: number } | null>(null);
  const [guestFields, setGuestFields] = useState<GuestFields>(EMPTY_GUEST);
  const [honeypot, setHoneypot] = useState('');
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [step, setStep] = useState<1 | 2>(1);
  const [loading, setLoading] = useState(false);
  const [loadingData, setLoadingData] = useState(false);
  const [error, setError] = useState('');
  const [successMessage, setSuccessMessage] = useState('');
  const [gatewayUrl, setGatewayUrl] = useState('');

  const plan = plans.find((item) => item.slug === selectedPlan) || null;
  const total = promo?.final_amount ?? plan?.price ?? 0;
  const walletCoversTotal = walletAvailable && walletBalance >= total;
  const directChannels = channels;
  const chosenChannel = directChannels.find((item) => item.key === channel) || null;
  const paymentUnavailable = appSlug === 'pos'
    ? !gatewayReady || !chosenChannel
    : manualMode ? !manualMethod : !gatewayReady;
  const busy = loading || loadingData;

  useEffect(() => {
    if (!isOpen) return;
    document.body.style.overflow = 'hidden';
    window.dispatchEvent(new Event('hellom-checkout-open'));
    const eventId = newEventId();
    const eventTimer = window.setTimeout(() => trackPlatformMetaEvent('InitiateCheckout', eventId), 250);
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !loading) onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = '';
      window.dispatchEvent(new Event('hellom-checkout-close'));
      window.clearTimeout(eventTimer);
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, loading, onClose]);

  useEffect(() => {
    if (!isOpen) return;
    trackPlatformMetaEvent('InitiateCheckout', newEventId());
    let active = true;
    setLoadingData(true);
    setError('');

    const load = async () => {
      try {
        const [posOptions, pricing, gateway] = await Promise.all([
          appSlug === 'pos' ? getPosCheckoutOptions() : Promise.resolve(null),
          guest ? Promise.resolve(null) : getPricingMatrix(),
          guest ? Promise.resolve(null) : getPaymentGatewayStatus().catch(() => null),
        ]);
        if (!active) return;
        const matrixPlans = pricing?.items.find((item) => item.app.slug === appSlug)?.plans ?? [];
        const availablePlans = posOptions?.plans ?? matrixPlans;
        const mappedPlans = availablePlans.map(normalizedPlan);
        setPlans(mappedPlans);
        const requestedSlug = initialPlanSlug || searchParams.get('paket') || '';
        const defaultPlan = mappedPlans.find((item) => item.slug === requestedSlug)
          || mappedPlans.find((item) => item.is_recommended)
          || mappedPlans[0];
        setSelectedPlan((current) => mappedPlans.some((item) => item.slug === current) ? current : (defaultPlan?.slug ?? ''));
        const direct = (posOptions?.payment.channels ?? (gateway as PaymentGatewayStatus | null)?.direct_channels ?? [])
          .filter((item) => item && typeof item.key === 'string')
          .map((item) => ({ key: item.key, label: item.label, type: item.type === 'qris' ? 'qris' as const : 'va' as const }));
        setChannels(direct);
        const gatewayConfig = gateway as PaymentGatewayStatus | null;
        const availableManualMethods = (gatewayConfig?.manual_payment?.methods ?? [])
          .filter((method): method is Record<string, unknown> => Boolean(method && typeof method === 'object'))
          .map((method) => ({ key: String(method.key ?? ''), label: String(method.label ?? method.name ?? '') }))
          .filter((method) => method.key && method.label);
        const usesManual = appSlug !== 'pos' && gatewayConfig?.checkout?.direct_mode === 'manual_confirmation';
        setManualMode(usesManual);
        setManualMethods(availableManualMethods);
        setManualMethod((current) => availableManualMethods.some((method) => method.key === current) ? current : (availableManualMethods[0]?.key ?? ''));
        setGatewayReady(posOptions ? posOptions.payment.available : usesManual || Boolean(gatewayConfig?.is_ready));
        const firstChannel = direct.find((item) => item.type === 'qris') || direct[0];
        setChannel((current) => direct.some((item) => item.key === current) ? current : (firstChannel?.key ?? ''));
        if (!guest && gateway) {
          setWalletAvailable(Boolean((gateway as PaymentGatewayStatus).member_wallet_enabled));
          try {
            const wallet = await getWalletOverview();
            if (active) {
              setWalletBalance(Number(wallet.wallet.available_balance || 0));
              setWalletAvailable(Boolean((gateway as PaymentGatewayStatus).member_wallet_enabled));
            }
          } catch {
            if (active) setWalletAvailable(false);
          }
        } else {
          setWalletAvailable(false);
          setWalletBalance(0);
        }
        try {
          const saved = sessionStorage.getItem(storageKey);
          if (saved) {
            const state = JSON.parse(saved) as {
              selectedPlan?: string;
              guestFields?: GuestFields;
              promoInput?: string;
              channel?: string;
            };
            if (state.selectedPlan && mappedPlans.some((item) => item.slug === state.selectedPlan)) setSelectedPlan(state.selectedPlan);
            if (state.guestFields) setGuestFields({ ...EMPTY_GUEST, ...state.guestFields });
            if (state.promoInput && !initialPromo && !searchParams.get('promo')) setPromoInput(state.promoInput);
            if (state.channel && direct.some((item) => item.key === state.channel)) setChannel(state.channel);
          }
        } catch {
          sessionStorage.removeItem(storageKey);
        }
        if (initialPromo || searchParams.get('promo')) setPromoInput(initialPromo || searchParams.get('promo') || '');
      } catch {
        if (active) setError('Paket belum dapat dimuat. Silakan coba beberapa saat lagi.');
      } finally {
        if (active) setLoadingData(false);
      }
    };
    void load();
    return () => { active = false; };
  }, [appSlug, guest, initialPlanSlug, initialPromo, isOpen, searchParams, storageKey]);

  useEffect(() => {
    if (!isOpen) return;
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        selectedPlan,
        guestFields,
        promoInput,
        channel,
      }));
    } catch {
      // Storage may be unavailable in private browsing; checkout can still continue.
    }
  }, [channel, guestFields, isOpen, promoInput, selectedPlan, storageKey]);

  useEffect(() => {
    if (walletCoversTotal && paymentFlow === 'wallet') return;
    if (!walletCoversTotal && paymentFlow === 'wallet') setPaymentFlow('direct');
  }, [paymentFlow, walletCoversTotal]);

  const features = useMemo(() => featureList(plan?.features), [plan]);

  if (!isOpen) return null;

  const applyPromo = async () => {
    if (!plan || !promoInput.trim()) return;
    setLoading(true);
    setError('');
    try {
      const result = await previewPosCheckoutPromo(plan.slug, promoInput.trim());
      setPromo({
        code: result.code,
        discount_amount: Number(result.discount_amount),
        final_amount: Number(result.final_amount ?? result.total_amount),
      });
    } catch (reason) {
      setPromo(null);
      setError(reason instanceof Error ? reason.message : 'Kode promo tidak dapat digunakan untuk paket ini.');
    } finally {
      setLoading(false);
    }
  };

  const advance = () => {
    setError('');
    if (!plan) {
      setError('Pilih paket terlebih dahulu.');
      return;
    }
    if (guest) {
      const fields = Object.values(guestFields).map((value) => value.trim());
      if (fields.some((value) => !value)) {
        setError('Lengkapi nama, email, nomor WhatsApp, dan nama usaha.');
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestFields.email)) {
        setError('Masukkan alamat email yang valid.');
        return;
      }
      if (!/^\+?[0-9\s-]{8,20}$/.test(guestFields.phone)) {
        setError('Masukkan nomor WhatsApp yang valid.');
        return;
      }
    }
    if (paymentFlow === 'direct' && paymentUnavailable) {
      setError('Pembayaran langsung belum tersedia saat ini. Silakan coba lagi nanti.');
      return;
    }
    if (paymentFlow === 'direct') trackPlatformMetaEvent('AddPaymentInfo', newEventId());
    setStep(2);
  };

  const submit = async () => {
    if (!plan || !termsAccepted) return;
    setLoading(true);
    setError('');
    const purchaseEventId = newEventId();
    try {
      if (guest) {
        const result = await startGuestPosCheckout({
          ...guestFields,
          plan_slug: plan.slug,
          payment_channel: channel,
          ...(promo ? { promo_code: promo.code } : {}),
          website: honeypot,
          purchase_event_id: purchaseEventId,
          terms_accepted: true,
        });
        trackPlatformMetaEvent('Lead', newEventId());
        sessionStorage.removeItem(storageKey);
        const path = new URL(result.status_url, window.location.origin).pathname;
        navigate(path);
        onClose();
        return;
      }
      const started = await checkoutStart({
        app_slug: appSlug,
        plan_slug: plan.slug,
        payment_flow: paymentFlow,
        expected_amount: plan.price,
        billing_cycle: 'monthly',
        ...(promo ? { promo_code: promo.code } : {}),
        ...(paymentFlow === 'direct' && appSlug === 'pos' ? { gateway_channel: channel } : {}),
        ...(paymentFlow === 'direct' && appSlug !== 'pos' && manualMode ? { manual_payment_method: manualMethod } : {}),
        purchase_event_id: purchaseEventId,
        terms_accepted: true,
      }) as {
        checkout_intent: { intent_token: string };
        status_url?: string | null;
        payment: { checkout_mode?: string; payment_url?: string | null };
      };

      if (paymentFlow === 'wallet') {
        await checkoutConfirmWallet({ intent_token: started.checkout_intent.intent_token });
        if (appSlug === 'pos') trackPlatformMetaEvent('Purchase', purchaseEventId, total);
        sessionStorage.removeItem(storageKey);
        setSuccessMessage(`Langganan ${plan.name} kamu telah aktif.`);
        onSuccess?.();
        return;
      }
      if (appSlug === 'pos' && started.status_url) {
        sessionStorage.removeItem(storageKey);
        const path = new URL(started.status_url, window.location.origin).pathname;
        navigate(path);
        onClose();
        return;
      }
      if (started.payment?.payment_url) {
        setGatewayUrl(started.payment.payment_url);
      } else {
        setSuccessMessage(started.payment?.checkout_mode === 'manual_confirmation'
          ? 'Permintaan pembayaran sudah dikirim dan menunggu konfirmasi. Kamu dapat memantau statusnya dari menu Pembayaran.'
          : 'Permintaan pembayaran berhasil dibuat. Statusnya dapat dipantau dari menu Pembayaran.');
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Checkout belum berhasil. Periksa kembali data dan coba lagi.');
    } finally {
      setLoading(false);
    }
  };

  const close = () => {
    if (loading) return;
    onClose();
  };

  const updateGuestField = (field: keyof GuestFields, value: string) => {
    setGuestFields((current) => ({ ...current, [field]: value }));
  };

  const primaryButton = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-bold text-amber-300 shadow-sm transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50';
  const secondaryButton = 'inline-flex min-h-12 items-center justify-center rounded-2xl border border-zinc-300 bg-white px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-50';
  const panel = 'rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-6';

  return (
    <div className="fixed inset-0 z-[120] flex items-end justify-center bg-zinc-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-4" onMouseDown={(event) => {
      if (event.target === event.currentTarget) close();
    }}>
      <section role="dialog" aria-modal="true" aria-labelledby="checkout-title" className="relative flex max-h-[100dvh] w-full flex-col overflow-hidden rounded-t-[2rem] bg-white sm:max-h-[92dvh] sm:max-w-5xl sm:rounded-[2rem]">
        <header className="flex items-start gap-4 border-b border-zinc-100 px-5 py-5 sm:px-8 sm:py-6">
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-zinc-950 text-amber-300 shadow-md">
            <AppIcon className="h-6 w-6" />
          </div>
          <div className="min-w-0 flex-1">
            <h2 id="checkout-title" className="font-serif text-2xl font-black tracking-tight text-zinc-950 sm:text-3xl">
              Aktifkan {appName}
            </h2>
            <p className="mt-1 text-sm text-zinc-500">
              {step === 1 ? 'Pilih paket dan metode pembayaran untuk mulai berlangganan.' : 'Periksa kembali detail langganan sebelum melakukan pembayaran.'}
            </p>
            <div className="mt-4 flex flex-wrap gap-2">
              {[1, 2].map((item) => (
                <span key={item} className={cn(
                  'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold',
                  item === step ? 'bg-amber-300 text-zinc-950' : item < step ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-500',
                )}>
                  {item < step ? <Check className="h-3.5 w-3.5" /> : item}
                  {item === 1 ? 'Pilih Paket & Pembayaran' : 'Konfirmasi & Bayar'}
                </span>
              ))}
            </div>
          </div>
          <button type="button" onClick={close} aria-label="Tutup checkout" disabled={loading} className="rounded-full p-2 text-zinc-500 hover:bg-zinc-100">
            <X className="h-5 w-5" />
          </button>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-8 sm:py-6">
          {loadingData ? (
            <div className="flex min-h-64 items-center justify-center gap-3 text-sm font-medium text-zinc-500"><Loader2 className="h-5 w-5 animate-spin" /> Memuat pilihan paket…</div>
          ) : gatewayUrl ? (
            <div className="space-y-4">
              <p className="text-sm text-zinc-600">Lanjutkan pembayaran pada halaman gateway yang aman.</p>
              <GatewayPaymentFrame paymentUrl={gatewayUrl} onClose={() => setGatewayUrl('')} />
            </div>
          ) : successMessage ? (
            <div className="flex min-h-72 flex-col items-center justify-center text-center">
              <CheckCircle2 className="h-14 w-14 text-emerald-600" />
              <h3 className="mt-4 text-2xl font-bold text-zinc-950">Pembayaran Berhasil!</h3>
              <p className="mt-2 max-w-md text-zinc-600">{successMessage}</p>
              <button type="button" className={`${primaryButton} mt-6`} onClick={onClose}>Selesai</button>
            </div>
          ) : step === 1 ? (
            <div className="space-y-7">
              <section>
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="text-base font-extrabold text-zinc-900">Pilih Paket</h3>
                  {plan && <span className="text-xs text-zinc-500">Harga per bulan</span>}
                </div>
                {plans.length ? (
                  <div className="grid gap-3 md:grid-cols-3">
                    {plans.map((item) => {
                      const chosen = item.slug === selectedPlan;
                      const itemFeatures = featureList(item.features);
                      return (
                        <button key={item.slug} type="button" onClick={() => { setSelectedPlan(item.slug); setPromo(null); }}
                          className={cn('relative rounded-2xl border p-4 text-left transition', chosen ? 'border-amber-400 bg-amber-50 ring-2 ring-amber-200' : 'border-zinc-200 hover:border-zinc-400')}>
                          {item.is_recommended && <span className="absolute -top-2 right-3 inline-flex items-center gap-1 rounded-full bg-amber-300 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-zinc-900"><Crown className="h-3 w-3" /> Paling Populer</span>}
                          <span className="flex items-center justify-between gap-2">
                            <span className="font-extrabold text-zinc-900">{item.name}</span>
                            <span className={cn('flex h-5 w-5 items-center justify-center rounded-full border', chosen ? 'border-zinc-900 bg-zinc-900 text-amber-300' : 'border-zinc-300')}>
                              {chosen && <Check className="h-3 w-3" />}
                            </span>
                          </span>
                          <span className="mt-2 block text-xl font-black text-zinc-950">{rupiah(item.price)}<span className="text-xs font-medium text-zinc-500"> / bulan</span></span>
                          {item.description && <span className="mt-1 block line-clamp-2 text-xs leading-relaxed text-zinc-500">{item.description}</span>}
                          <span className="mt-3 grid gap-1.5">
                            {itemFeatures.map((feature) => <span key={feature} className="flex items-start gap-1.5 text-xs text-zinc-600"><Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />{feature}</span>)}
                          </span>
                        </button>
                      );
                    })}
                  </div>
                ) : <p className="rounded-2xl bg-zinc-50 p-4 text-sm text-zinc-600">Belum ada paket yang tersedia.</p>}
              </section>

              {guest && (
                <section>
                  <h3 className="mb-3 text-base font-extrabold text-zinc-900">Data Akun</h3>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-xs font-semibold text-zinc-600">Nama lengkap
                      <input autoComplete="name" value={guestFields.name} onChange={(event) => updateGuestField('name', event.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-sm text-zinc-900 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100" />
                    </label>
                    <label className="text-xs font-semibold text-zinc-600">Email
                      <input type="email" autoComplete="email" value={guestFields.email} onChange={(event) => updateGuestField('email', event.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-sm text-zinc-900 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100" />
                    </label>
                    <label className="text-xs font-semibold text-zinc-600">Nomor WhatsApp
                      <input type="tel" inputMode="numeric" autoComplete="tel" value={guestFields.phone} onChange={(event) => updateGuestField('phone', event.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-sm text-zinc-900 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100" />
                    </label>
                    <label className="text-xs font-semibold text-zinc-600">Nama usaha
                      <input autoComplete="organization" value={guestFields.business_name} onChange={(event) => updateGuestField('business_name', event.target.value)} className="mt-1.5 min-h-11 w-full rounded-xl border border-zinc-300 px-3 text-sm text-zinc-900 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100" />
                    </label>
                  </div>
                  <label className="hidden" aria-hidden="true">Website
                    <input tabIndex={-1} autoComplete="off" value={honeypot} onChange={(event) => setHoneypot(event.target.value)} />
                  </label>
                </section>
              )}

              <section>
                <h3 className="mb-3 text-base font-extrabold text-zinc-900">Kode promo <span className="font-medium text-zinc-400">(opsional)</span></h3>
                <div className="flex gap-2">
                  <div className="relative min-w-0 flex-1">
                    <Tag className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input value={promoInput} onChange={(event) => { setPromoInput(event.target.value.toUpperCase()); setPromo(null); }} placeholder="Masukkan kode promo" className="min-h-11 w-full rounded-xl border border-zinc-300 pl-10 pr-3 text-sm font-semibold uppercase text-zinc-900 outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100" />
                  </div>
                  <button type="button" onClick={() => void applyPromo()} disabled={loading || !promoInput.trim() || !plan} className={secondaryButton}>Terapkan</button>
                </div>
                {promo && <p className="mt-2 text-sm font-semibold text-emerald-700">Promo {promo.code} berhasil diterapkan. Hemat {rupiah(promo.discount_amount)}.</p>}
              </section>

              <section>
                <h3 className="mb-3 text-base font-extrabold text-zinc-900">Metode Pembayaran</h3>
                <div className={cn('grid gap-3', !guest && 'sm:grid-cols-2')}>
                  {!guest && (
                    <button type="button" disabled={!walletCoversTotal} onClick={() => setPaymentFlow('wallet')} className={cn('rounded-2xl border p-4 text-left', paymentFlow === 'wallet' ? 'border-amber-400 bg-amber-50 ring-2 ring-amber-200' : 'border-zinc-200', !walletCoversTotal && 'cursor-not-allowed opacity-50')}>
                      <span className="flex items-center justify-between"><span className="flex items-center gap-2 font-bold text-zinc-900"><Wallet className="h-4 w-4" /> Bayar dengan Wallet</span><span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">Disarankan</span></span>
                      <span className="mt-1.5 block text-xs text-zinc-500">Saldo {rupiah(walletBalance)}. Aktivasi lebih cepat jika saldo cukup.</span>
                      {!walletCoversTotal && <span className="mt-1 block text-xs font-semibold text-amber-700">Saldo belum cukup untuk paket ini.</span>}
                    </button>
                  )}
                  <button type="button" onClick={() => setPaymentFlow('direct')} className={cn('rounded-2xl border p-4 text-left', paymentFlow === 'direct' ? 'border-amber-400 bg-amber-50 ring-2 ring-amber-200' : 'border-zinc-200')}>
                    <span className="font-bold text-zinc-900">Pembayaran Langsung</span>
                    <span className="mt-1.5 block text-xs text-zinc-500">Konfirmasi otomatis setelah pembayaran.</span>
                  </button>
                </div>
                {paymentFlow === 'direct' && (
                  <div className="mt-3 rounded-2xl border border-zinc-200 p-4">
                    {appSlug !== 'pos' && manualMode ? (
                      <div className="flex flex-wrap gap-2">
                        {manualMethods.map((item) => <button key={item.key} type="button" onClick={() => setManualMethod(item.key)} className={cn('rounded-full border px-3 py-2 text-xs font-bold', manualMethod === item.key ? 'border-zinc-950 bg-zinc-950 text-amber-300' : 'border-zinc-300 text-zinc-700 hover:border-zinc-700')}>{item.label}</button>)}
                        {!manualMethods.length && <p className="text-sm text-amber-800">Metode pembayaran manual belum dikonfigurasi.</p>}
                      </div>
                    ) : !gatewayReady ? <p className="text-sm font-semibold text-amber-800">Pembayaran belum tersedia saat ini.</p> : (
                      <>
                        <div className="flex flex-wrap gap-2">
                          {directChannels.map((item) => <button key={item.key} type="button" onClick={() => setChannel(item.key)} className={cn('rounded-full border px-3 py-2 text-xs font-bold', channel === item.key ? 'border-zinc-950 bg-zinc-950 text-amber-300' : 'border-zinc-300 text-zinc-700 hover:border-zinc-700')}>{item.label}</button>)}
                        </div>
                        {!directChannels.length && <p className="text-sm text-zinc-500">Belum ada metode pembayaran aktif.</p>}
                      </>
                    )}
                  </div>
                )}
                {!guest && walletAvailable && <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-900">Menggunakan wallet akan mengaktifkan langganan lebih cepat setelah pembayaran berhasil.</p>}
              </section>
            </div>
          ) : (
            <div className="space-y-5">
              {successMessage ? (
                <div className="flex min-h-64 flex-col items-center justify-center text-center">
                  <CheckCircle2 className="h-14 w-14 text-emerald-600" />
                  <h3 className="mt-4 text-2xl font-bold text-zinc-950">Pembayaran Berhasil!</h3>
                  <p className="mt-2 max-w-md text-zinc-600">{successMessage}</p>
                  <button type="button" className={`${primaryButton} mt-6`} onClick={onClose}>Selesai</button>
                </div>
              ) : (
                <>
                  <div className="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
                    <section className={panel}>
                      <div className="flex items-center justify-between">
                        <h3 className="text-base font-extrabold text-zinc-900">Ringkasan Pesanan</h3>
                        <button type="button" onClick={() => setStep(1)} className="text-xs font-bold text-zinc-600 underline">Ubah</button>
                      </div>
                      <dl className="mt-5 space-y-3 text-sm">
                        <div className="flex justify-between gap-3"><dt className="text-zinc-500">Paket</dt><dd className="font-semibold text-zinc-900">{plan?.name}</dd></div>
                        <div className="flex justify-between gap-3"><dt className="text-zinc-500">Durasi</dt><dd className="font-semibold text-zinc-900">Bulanan</dd></div>
                        <div className="flex justify-between gap-3"><dt className="text-zinc-500">Harga</dt><dd className="font-semibold text-zinc-900">{rupiah(plan?.price ?? 0)}</dd></div>
                        {promo && <div className="flex justify-between gap-3 text-emerald-700"><dt>Diskon ({promo.code})</dt><dd className="font-bold">− {rupiah(promo.discount_amount)}</dd></div>}
                        <div className="flex justify-between gap-3"><dt className="text-zinc-500">Metode pembayaran</dt><dd className="text-right font-semibold text-zinc-900">{paymentFlow === 'wallet' ? 'Wallet Hellom' : appSlug !== 'pos' && manualMode ? manualMethods.find((item) => item.key === manualMethod)?.label : chosenChannel?.label}</dd></div>
                      </dl>
                      <div className="mt-5 flex items-center justify-between rounded-2xl bg-amber-100 px-4 py-4">
                        <span className="font-bold text-zinc-900">Total bayar</span><span className="text-xl font-black text-zinc-950">{rupiah(total)}</span>
                      </div>
                    </section>
                    <section className={cn(panel, 'bg-zinc-50')}>
                      <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-950 text-amber-300"><AppIcon className="h-5 w-5" /></span>
                        <div><p className="font-extrabold text-zinc-950">{plan?.name}</p><p className="text-xs text-zinc-500">{plan?.description || appName}</p></div>
                      </div>
                      <ul className="mt-4 space-y-2">{features.map((feature) => <li key={feature} className="flex gap-2 text-sm text-zinc-600"><Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />{feature}</li>)}</ul>
                    </section>
                  </div>
                  <p className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">Langganan aktif otomatis setelah pembayaran berhasil diverifikasi.</p>
                  <label className="flex cursor-pointer items-start gap-3 text-sm leading-relaxed text-zinc-700">
                    <input type="checkbox" checked={termsAccepted} onChange={(event) => setTermsAccepted(event.target.checked)} className="mt-1 h-4 w-4 rounded border-zinc-300 accent-amber-400" />
                    <span>Saya setuju dengan <a href="/terms" target="_blank" rel="noreferrer" className="font-semibold underline">syarat berlangganan dan ketentuan layanan</a>.</span>
                  </label>
                </>
              )}
            </div>
          )}

          {error && <p role="alert" className="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{error}</p>}
        </div>

        {!loadingData && !gatewayUrl && !successMessage && (
          <footer className="flex flex-col-reverse gap-3 border-t border-zinc-100 bg-white px-5 py-4 sm:flex-row sm:justify-between sm:px-8">
            {step === 1 ? (
              <>
                <button type="button" onClick={close} className={secondaryButton}>Batal</button>
                <button type="button" onClick={advance} disabled={!plan || busy} className={primaryButton}>Lanjut ke Ringkasan <span aria-hidden="true">→</span></button>
              </>
            ) : (
              <>
                <button type="button" onClick={() => { setStep(1); setError(''); }} disabled={busy} className={secondaryButton}>Kembali</button>
                <button type="button" onClick={() => void submit()} disabled={!termsAccepted || busy} className={primaryButton}>
                  {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <LockKeyhole className="h-4 w-4" />}
                  {busy ? 'Memproses…' : 'Bayar Sekarang'}
                </button>
              </>
            )}
          </footer>
        )}
      </section>
    </div>
  );
}
