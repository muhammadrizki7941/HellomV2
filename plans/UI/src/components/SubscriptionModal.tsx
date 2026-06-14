import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Check,
  Copy,
  Crown,
  CreditCard,
  Download,
  ShieldCheck,
  Sparkles,
  Star,
  TicketPercent,
  Wallet,
  X,
  Zap,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  checkoutConfirmWallet,
  checkoutStart,
  getImageUrl,
  getPaymentGatewayStatus,
  getPricingMatrix,
  pollAppEntitlement,
  validatePromoCode,
} from '@/lib/hellomApi';
import GatewayPaymentFrame from '@/components/GatewayPaymentFrame';

interface SubscriptionModalProps {
  isOpen: boolean;
  onClose: () => void;
  appName: string;
  appSlug?: string;
  appIcon?: React.ElementType;
  onSuccess?: () => void;
}

type MatrixPlan = {
  id: number;
  slug: string;
  name: string;
  type: string;
  price: number;
  description?: string | null;
  features?: unknown;
  billing_cycles?: string[] | null;
  duration_days?: number | null;
  is_recommended?: boolean;
  sort_order?: number;
  is_current: boolean;
};

type PromoPreview = {
  code: string;
  name: string;
  discount_amount: number;
  final_amount: number;
};

type PaymentFlow = 'wallet' | 'direct';
type CheckoutStep = 'plan' | 'payment' | 'confirm' | 'result';

type ManualPaymentMethod = {
  key: 'bank_transfer' | 'gopay' | 'dana' | 'qris';
  label: string;
  account_name?: string;
  account_number?: string;
  bank_name?: string;
  instructions?: string;
  image_url?: string | null;
};

type GatewayStatus = {
  provider: string;
  active_provider: 'xendit' | 'ipaymu' | 'doku';
  mode: 'sandbox' | 'production';
  is_ready: boolean;
  checkout_mode: 'manual_confirmation' | 'gateway_automatic';
  member_wallet_enabled: boolean;
  manual_confirmation: { enabled: boolean; label: string };
  manual_payment?: {
    enabled: boolean;
    notes: string;
    methods: ManualPaymentMethod[];
  };
};

type PricingMatrixResponse = {
  items: Array<{
    app: { id: number; slug: string; name: string };
    plans: MatrixPlan[];
  }>;
};

type CheckoutStartResponse = {
  checkout_intent: { intent_token: string };
  payment: {
    checkout_mode: 'manual_confirmation' | 'gateway_automatic' | 'wallet_instant';
    invoice_number?: string | null;
    payment_url?: string | null;
  };
};

function resolveAppSlug(appName: string, explicitSlug?: string): string | null {
  if (explicitSlug) return explicitSlug;

  const normalized = appName.toLowerCase().trim();
  const compact = normalized.replace(/\s+/g, '');
  if (normalized.includes('landing')) return 'landing_builder';
  if (normalized === 'pos' || normalized.includes('point of sale') || compact.includes('pointofsale')) return 'pos';

  return null;
}

function normalizeFeatureList(features: unknown): string[] {
  if (Array.isArray(features)) {
    return features
      .map((feature) => {
        if (typeof feature === 'string') return feature;
        if (feature && typeof feature === 'object' && 'label' in feature) return String((feature as { label?: unknown }).label || '');
        return '';
      })
      .filter(Boolean);
  }

  if (features && typeof features === 'object') {
    return Object.entries(features as Record<string, unknown>)
      .filter(([, value]) => value === true || typeof value === 'number' || typeof value === 'string')
      .map(([key, value]) => {
        const label = key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
        if (value === true) return label;
        if (value === -1) return `${label}: unlimited`;
        return `${label}: ${String(value)}`;
      });
  }

  return [];
}

function resolvePeriod(plan: MatrixPlan) {
  if (plan.type === 'free') return '/lifetime';
  if (plan.type === 'lifetime') return '/lifetime';
  if (plan.billing_cycles?.includes('yearly') || plan.slug.includes('yearly')) return '/year';
  if (plan.duration_days && plan.duration_days >= 365) return '/year';
  if (plan.duration_days && plan.duration_days > 0 && plan.duration_days !== 30) return `/${plan.duration_days} hari`;
  return '/month';
}

function planMeta(plan: MatrixPlan, index: number, hasConfiguredRecommendation: boolean) {
  const configuredFeatures = normalizeFeatureList(plan.features);

  if (plan.type === 'free') {
    return {
      description: plan.description || 'Aktifkan paket gratis untuk membuka akses aplikasi secara instan.',
      features: configuredFeatures.length ? configuredFeatures : ['Full app access', 'Biaya Rp 0', 'Aktif langsung'],
      icon: Zap,
      color: 'bg-zinc-100 text-zinc-900',
      popular: Boolean(plan.is_recommended) || (!hasConfiguredRecommendation && index === 0),
      period: resolvePeriod(plan),
    };
  }

  return {
    description: plan.description || 'Paket berbayar untuk operasional aplikasi dan aktivasi fitur premium.',
    features: configuredFeatures.length ? configuredFeatures : ['Full app access', 'Wallet recharge & auto-renew', 'Pembayaran langsung mengikuti setting owner'],
    icon: index === 0 ? Star : Crown,
    color: index === 0 ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700',
    popular: Boolean(plan.is_recommended) || (!hasConfiguredRecommendation && index === 0),
    period: resolvePeriod(plan),
  };
}

export default function SubscriptionModal({ isOpen, onClose, appName, appSlug, appIcon: Icon, onSuccess }: SubscriptionModalProps) {
  const navigate = useNavigate();
  const [plans, setPlans] = useState<MatrixPlan[]>([]);
  const [resolvedSlug, setResolvedSlug] = useState<string | null>(resolveAppSlug(appName, appSlug));
  const [resolvedAppId, setResolvedAppId] = useState<number | null>(null);
  const [paymentFlow, setPaymentFlow] = useState<PaymentFlow>('wallet');
  const [gatewayStatus, setGatewayStatus] = useState<GatewayStatus | null>(null);
  const [isLoadingPlans, setIsLoadingPlans] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [activePlanSlug, setActivePlanSlug] = useState<string | null>(null);
  const [selectedPlanSlug, setSelectedPlanSlug] = useState<string | null>(null);
  const [promoCode, setPromoCode] = useState('');
  const [promoLoadingForPlan, setPromoLoadingForPlan] = useState<string | null>(null);
  const [promoByPlan, setPromoByPlan] = useState<Record<string, PromoPreview | null>>({});
  const [manualPaymentMethod, setManualPaymentMethod] = useState<'bank_transfer' | 'gopay' | 'dana' | 'qris'>('bank_transfer');
  const [showReceiptModal, setShowReceiptModal] = useState(false);
  const [selectedMethod, setSelectedMethod] = useState<ManualPaymentMethod | null>(null);
  const [checkoutStep, setCheckoutStep] = useState<CheckoutStep>('plan');
  const [gatewayPaymentUrl, setGatewayPaymentUrl] = useState<string | null>(null);
  const [gatewayPollSlug, setGatewayPollSlug] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) {
      setPlans([]);
      setResolvedAppId(null);
      setErrorMessage(null);
      setSuccessMessage(null);
      setActivePlanSlug(null);
      setSelectedPlanSlug(null);
      setPromoCode('');
      setPromoByPlan({});
      setPromoLoadingForPlan(null);
      setGatewayStatus(null);
      setPaymentFlow('direct');
      setManualPaymentMethod('bank_transfer');
      setShowReceiptModal(false);
      setSelectedMethod(null);
      setCheckoutStep('plan');
      return;
    }

    const slugHint = resolveAppSlug(appName, appSlug);
    let cancelled = false;

    const loadState = async () => {
      setIsLoadingPlans(true);
      setErrorMessage(null);
      setSuccessMessage(null);

      try {
        const [matrix, gateway] = await Promise.all([
          getPricingMatrix() as Promise<PricingMatrixResponse>,
          getPaymentGatewayStatus().catch(() => null) as Promise<GatewayStatus | null>,
        ]);
        const item = matrix.items.find((entry) => entry.app.slug === slugHint)
          || matrix.items.find((entry) => entry.app.name.toLowerCase() === appName.toLowerCase());

        if (!item) {
          throw new Error('Paket untuk aplikasi ini belum tersedia.');
        }

        if (!cancelled) {
          setResolvedAppId(item.app.id);
          setResolvedSlug(item.app.slug);
          setPlans(item.plans || []);
          setGatewayStatus(gateway);
          setPaymentFlow(gateway?.member_wallet_enabled ? 'wallet' : 'direct');
          const firstManualMethod = gateway?.manual_payment?.methods?.[0]?.key;
          if (firstManualMethod) {
            setManualPaymentMethod(firstManualMethod);
          }
          setCheckoutStep('plan');
        }
      } catch (error) {
        if (!cancelled) {
          const message = error instanceof Error ? error.message : 'Gagal memuat paket langganan.';
          setErrorMessage(message);
        }
      } finally {
        if (!cancelled) {
          setIsLoadingPlans(false);
        }
      }
    };

    void loadState();

    return () => {
      cancelled = true;
    };
  }, [isOpen, appName, appSlug]);

  const decoratedPlans = useMemo(
    () => {
      const hasConfiguredRecommendation = plans.some((plan) => Boolean(plan.is_recommended));
      return plans.map((plan, index) => ({ plan, meta: planMeta(plan, index, hasConfiguredRecommendation) }));
    },
    [plans]
  );

  const selectedPlan = useMemo(
    () => plans.find((plan) => plan.slug === selectedPlanSlug) || null,
    [plans, selectedPlanSlug]
  );

  if (!isOpen) return null;

  const applyPromoToPlan = async (plan: MatrixPlan) => {
    const normalizedCode = promoCode.trim().toUpperCase();
    if (!normalizedCode) {
      setErrorMessage('Masukkan kode promo terlebih dahulu.');
      return;
    }

    setPromoLoadingForPlan(plan.slug);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const promo = await validatePromoCode({
        code: normalizedCode,
        amount: plan.price,
        app_id: resolvedAppId ?? undefined,
        plan_id: plan.id,
      }) as PromoPreview;
      setPromoByPlan((current) => ({
        ...current,
        [plan.slug]: {
          code: promo.code,
          name: promo.name,
          discount_amount: promo.discount_amount,
          final_amount: promo.final_amount,
        },
      }));
      setSuccessMessage(`Promo ${promo.code} berhasil diterapkan untuk ${plan.name}.`);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Promo tidak valid.';
      setErrorMessage(message);
      setPromoByPlan((current) => ({ ...current, [plan.slug]: null }));
    } finally {
      setPromoLoadingForPlan(null);
    }
  };

  const copyToClipboard = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setSuccessMessage('Teks berhasil disalin ke clipboard.');
    } catch {
      setErrorMessage('Gagal menyalin ke clipboard.');
    }
  };

  const handleSubscribe = async (planSlug: string) => {
    if (!resolvedSlug) {
      setErrorMessage('Slug aplikasi tidak ditemukan untuk checkout.');
      return;
    }

    setActivePlanSlug(planSlug);
    setErrorMessage(null);
    setSuccessMessage(null);

    const billingCycle: 'monthly' | 'yearly' | 'lifetime' =
      planSlug.includes('yearly') ? 'yearly' : planSlug.includes('lifetime') || planSlug === 'free' ? 'lifetime' : 'monthly';

    try {
      if (paymentFlow === 'wallet') {
        const intent = await checkoutStart({
          app_slug: resolvedSlug,
          plan_slug: planSlug,
          payment_flow: 'wallet',
          billing_cycle: billingCycle,
        }) as CheckoutStartResponse;

        await checkoutConfirmWallet({
          intent_token: intent.checkout_intent.intent_token,
        });

        setSuccessMessage('Langganan berhasil diaktifkan lewat wallet.');
        onSuccess?.();
        setTimeout(() => {
          onClose();
        }, 1400);
      } else {
        const checkout = await checkoutStart({
          app_slug: resolvedSlug,
          plan_slug: planSlug,
          payment_flow: 'direct',
          billing_cycle: billingCycle,
          manual_payment_method: manualPaymentMethod,
        }) as CheckoutStartResponse;

        if (checkout.payment.checkout_mode === 'manual_confirmation') {
          setSuccessMessage(`Permintaan berlangganan terkirim. Invoice ${checkout.payment.invoice_number || '-'} sedang menunggu konfirmasi owner via ${manualPaymentMethod}.`);
          setCheckoutStep('result');
          const method = gatewayStatus?.manual_payment?.methods.find(m => m.key === manualPaymentMethod);
          if (method) {
            setSelectedMethod(method);
            setShowReceiptModal(true);
          }
          // Don't auto-close when showing receipt modal
          onSuccess?.();
          return;
        } else if (checkout.payment.payment_url) {
          setGatewayPaymentUrl(checkout.payment.payment_url);
          setGatewayPollSlug(resolvedSlug);
          onSuccess?.();
        } else {
          setSuccessMessage(`Checkout langsung sudah dibuat dan menunggu aktivasi link payment ${providerLabel}.`);
          setCheckoutStep('result');
          onSuccess?.();
          setTimeout(() => {
            navigate('/dashboard/payments');
            onClose();
          }, 1400);
        }
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Checkout gagal diproses.';
      setErrorMessage(message);
    } finally {
      setActivePlanSlug(null);
    }
  };

  const handleConfirmCheckout = async () => {
    if (!selectedPlan) {
      setErrorMessage('Pilih paket terlebih dahulu sebelum lanjut checkout.');
      return;
    }

    if (selectedPlan.is_current) {
      setErrorMessage('Paket ini sudah aktif.');
      return;
    }

    if (paymentFlow === 'direct' && adjustedCheckoutMode === 'manual_confirmation' && !manualPaymentMethod) {
      setErrorMessage('Pilih metode pembayaran manual terlebih dahulu.');
      return;
    }

    await handleSubscribe(selectedPlan.slug);
  };

  const forceManual = Boolean(gatewayStatus?.manual_payment?.enabled && gatewayStatus.manual_payment.methods?.length > 0);
  const adjustedCheckoutMode = forceManual ? 'manual_confirmation' : gatewayStatus?.checkout_mode;
  const isDirectAutoDisabled = paymentFlow === 'direct'
    && adjustedCheckoutMode === 'gateway_automatic'
    && !gatewayStatus?.is_ready;
  const walletDisabled = !gatewayStatus?.member_wallet_enabled;
  const providerLabel = gatewayStatus?.active_provider === 'ipaymu' ? 'iPaymu' : gatewayStatus?.active_provider === 'doku' ? 'DOKU' : 'Xendit';
  const selectedPromo = selectedPlan ? promoByPlan[selectedPlan.slug] : null;
  const selectedFinalPrice = selectedPlan ? (selectedPromo ? selectedPromo.final_amount : selectedPlan.price) : 0;
  const stepItems: Array<{ key: CheckoutStep; label: string }> = [
    { key: 'plan', label: 'Paket' },
    { key: 'payment', label: 'Pembayaran' },
    { key: 'confirm', label: 'Konfirmasi' },
    { key: 'result', label: 'Hasil' },
  ];
  const currentStepIndex = stepItems.findIndex((item) => item.key === checkoutStep);
  const canProceedFromPayment = paymentFlow === 'wallet'
    || adjustedCheckoutMode !== 'manual_confirmation'
    || Boolean(manualPaymentMethod);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/55 p-4 backdrop-blur-sm">
      <div className="relative max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-3xl bg-white shadow-2xl">
        <button
          onClick={onClose}
          className="absolute right-4 top-4 z-10 rounded-full bg-zinc-100 p-2 text-zinc-500 transition hover:bg-zinc-200"
        >
          <X className="h-5 w-5" />
        </button>

        <div className="p-8">
          <div className="text-center">
            <div className="mx-auto mb-6 inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-950 text-white shadow-lg shadow-zinc-950/20">
              {Icon ? <Icon className="h-8 w-8" /> : <Star className="h-8 w-8" />}
            </div>
            <h2 className="text-3xl font-bold text-zinc-950">Aktifkan {appName}</h2>
            <p className="mx-auto mt-3 max-w-2xl text-sm leading-6 text-zinc-600">
              Pilih paket, pilih metode pembayaran, lalu cek ringkasan sebelum checkout dibuat.
            </p>
          </div>

          <div className="mx-auto mt-8 grid max-w-3xl grid-cols-4 gap-2">
            {stepItems.map((item, index) => (
              <button
                key={item.key}
                type="button"
                disabled={index > currentStepIndex || item.key === 'result'}
                onClick={() => {
                  if (item.key !== 'result' && index <= currentStepIndex) {
                    setCheckoutStep(item.key);
                  }
                }}
                className={cn(
                  'rounded-2xl border px-3 py-2 text-xs font-semibold transition',
                  index === currentStepIndex
                    ? 'border-zinc-900 bg-zinc-900 text-white'
                    : index < currentStepIndex
                      ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                      : 'border-zinc-200 bg-white text-zinc-400'
                )}
              >
                {index + 1}. {item.label}
              </button>
            ))}
          </div>

          {checkoutStep !== 'plan' && (
          <div className="mx-auto mt-6 max-w-3xl rounded-3xl border border-zinc-200 bg-zinc-50 p-4 text-left">
            <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
              <div>
                <p className="text-sm font-semibold text-zinc-950">Promo checkout</p>
                <p className="mt-1 text-xs text-zinc-500">Masukkan kode promo untuk menghitung harga final sebelum aktivasi.</p>
              </div>
              <div className="flex w-full flex-col gap-2 md:w-auto md:min-w-[340px] md:flex-row">
                <input
                  value={promoCode}
                  onChange={(event) => setPromoCode(event.target.value.toUpperCase())}
                  className="w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-amber-400"
                  placeholder="Contoh: WELCOME25"
                />
              </div>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-2">
              <button
                type="button"
                onClick={() => setPaymentFlow('wallet')}
                disabled={walletDisabled}
                className={cn(
                  'rounded-2xl border px-4 py-3 text-left transition',
                  paymentFlow === 'wallet'
                    ? 'border-emerald-300 bg-emerald-50'
                    : 'border-zinc-200 bg-white hover:bg-zinc-50',
                  walletDisabled && 'cursor-not-allowed opacity-50'
                )}
              >
                <div className="flex items-center gap-2 text-sm font-semibold text-zinc-950">
                  <Wallet className="h-4 w-4 text-emerald-600" />
                  Bayar dari wallet
                </div>
                <p className="mt-1 text-xs text-zinc-500">Aktivasi langsung jika saldo cukup.</p>
              </button>

              <button
                type="button"
                onClick={() => setPaymentFlow('direct')}
                className={cn(
                  'rounded-2xl border px-4 py-3 text-left transition',
                  paymentFlow === 'direct'
                    ? 'border-amber-300 bg-amber-50'
                    : 'border-zinc-200 bg-white hover:bg-zinc-50'
                )}
              >
                <div className="flex items-center gap-2 text-sm font-semibold text-zinc-950">
                  <CreditCard className="h-4 w-4 text-amber-700" />
                  Pembayaran langsung
                </div>
                <p className="mt-1 text-xs text-zinc-500">
                  {forceManual
                    ? 'Pembayaran manual aktif - masuk antrean konfirmasi owner'
                    : gatewayStatus?.checkout_mode === 'manual_confirmation'
                      ? 'Masuk antrean konfirmasi owner'
                      : gatewayStatus?.is_ready
                        ? `${providerLabel} ${gatewayStatus.mode} aktif`
                        : `${providerLabel} belum siap penuh`}
                </p>
              </button>
            </div>

            {walletDisabled && (
              <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                Owner sedang mematikan fitur wallet/e-wallet member, jadi checkout hanya lewat pembayaran langsung.
              </div>
            )}

            {paymentFlow === 'direct' && ((gatewayStatus?.checkout_mode === 'manual_confirmation' && gatewayStatus.manual_payment?.methods?.length) || (gatewayStatus?.manual_payment?.enabled && gatewayStatus.manual_payment.methods?.length)) ? (
              <div className="mt-4 rounded-2xl border border-zinc-200 bg-white p-4">
                <p className="text-sm font-semibold text-zinc-950">Pilih metode pembayaran</p>
                <div className="mt-3 grid gap-3 md:grid-cols-2">
                  {gatewayStatus.manual_payment.methods.map((method) => (
                    <button
                      key={method.key}
                      type="button"
                      onClick={() => setManualPaymentMethod(method.key)}
                      className={cn(
                        'rounded-2xl border px-4 py-3 text-left transition',
                        manualPaymentMethod === method.key ? 'border-amber-300 bg-amber-50' : 'border-zinc-200 hover:bg-zinc-50'
                      )}
                    >
                      {method.image_url && (
                        <div className="mb-2 flex justify-center">
                          <img src={getImageUrl(method.image_url)} alt={method.label} className="h-16 w-16 rounded-lg object-cover" />
                        </div>
                      )}
                      <div className="font-semibold text-zinc-900">{method.label}</div>
                      <div className="mt-1 text-xs text-zinc-500">
                        {[method.bank_name, method.account_name, method.account_number].filter(Boolean).join(' • ') || 'Instruksi manual dari owner'}
                      </div>
                    </button>
                  ))}
                </div>
                {gatewayStatus.manual_payment.notes && (
                  <p className="mt-3 text-xs text-zinc-500">{gatewayStatus.manual_payment.notes}</p>
                )}
              </div>
            ) : null}

            <div className="mt-4 rounded-2xl border border-zinc-200 bg-white p-4 text-xs text-zinc-600">
              <div className="flex items-start gap-2">
                <ShieldCheck className="mt-0.5 h-4 w-4 text-emerald-600" />
                <div>
                  <p className="font-semibold text-zinc-900">
                    {paymentFlow === 'wallet'
                      ? 'Wallet menjadi jalur tercepat untuk aktivasi.'
                      : gatewayStatus?.checkout_mode === 'manual_confirmation'
                        ? 'Owner saat ini memakai konfirmasi manual untuk pembayaran langsung.'
                        : `Owner saat ini memakai mode pembayaran otomatis berbasis ${providerLabel}.`}
                  </p>
                  <p className="mt-1">
                    {paymentFlow === 'direct' && isDirectAutoDisabled
                      ? `Mode otomatis dipilih owner, tetapi kredensial ${providerLabel} belum lengkap di backend.`
                      : paymentFlow === 'direct' && gatewayStatus?.checkout_mode === 'manual_confirmation'
                        ? 'Instruksi pembayaran manual akan dikirim ke email pembeli dan owner menerima notifikasi untuk proses konfirmasi.'
                        : 'Flow ini memakai jalur billing Hellom yang sama dengan dashboard owner.'}
                  </p>
                </div>
              </div>
            </div>
          </div>
          )}

          {errorMessage && (
            <div className="mx-auto mt-6 max-w-3xl rounded-2xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
              {errorMessage}
            </div>
          )}

          {successMessage && (
            <div className="mx-auto mt-6 max-w-3xl rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">
              {successMessage}
            </div>
          )}

          {isLoadingPlans && (
            <div className="mx-auto mt-6 max-w-3xl rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
              Memuat paket langganan...
            </div>
          )}

          {checkoutStep === 'plan' && (
          <div className="mt-8 grid grid-cols-1 gap-6 md:grid-cols-3">
            {decoratedPlans.map(({ plan, meta }) => {
              const promo = promoByPlan[plan.slug];
              const finalPrice = promo ? promo.final_amount : plan.price;

              return (
                <div
                  key={plan.slug}
                  className={cn(
                    'relative flex flex-col rounded-3xl border-2 p-6 transition-all hover:-translate-y-1 hover:shadow-lg',
                    meta.popular
                      ? 'border-blue-500 bg-blue-50/40 shadow-xl shadow-blue-500/10'
                      : 'border-zinc-200 bg-white'
                  )}
                >
                  {meta.popular && (
                    <div className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wider text-white shadow-sm">
                      Recommended
                    </div>
                  )}

                  <div className="flex items-center gap-3">
                    <div className={cn('rounded-2xl p-2.5', meta.color)}>
                      <meta.icon className="h-5 w-5" />
                    </div>
                    <h3 className="font-bold text-zinc-950">{plan.name}</h3>
                    {plan.is_current && (
                      <span className="ml-auto rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                        Current
                      </span>
                    )}
                  </div>

                  <div className="mt-5">
                    {promo && (
                      <div className="mb-2 inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                        <TicketPercent className="h-3.5 w-3.5" />
                        {promo.code} aktif
                      </div>
                    )}
                    <div className="flex items-end gap-2">
                      <span className="text-3xl font-bold text-zinc-950">Rp {finalPrice.toLocaleString('id-ID')}</span>
                      <span className="pb-1 text-sm text-zinc-500">{meta.period}</span>
                    </div>
                    {promo && (
                      <p className="mt-1 text-xs text-zinc-500">
                        Harga normal <span className="line-through">Rp {plan.price.toLocaleString('id-ID')}</span> | Hemat Rp {promo.discount_amount.toLocaleString('id-ID')}
                      </p>
                    )}
                  </div>

                  <p className="mt-4 min-h-[44px] text-sm text-zinc-600">{meta.description}</p>

                  <ul className="mt-6 flex-1 space-y-3">
                    {meta.features.map((feature) => (
                      <li key={feature} className="flex items-start gap-2 text-sm text-zinc-700">
                        <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                        <span>{feature}</span>
                      </li>
                    ))}
                  </ul>

                  <button
                    onClick={() => {
                      if (!plan.is_current && !activePlanSlug) {
                        setSelectedPlanSlug(plan.slug);
                        setCheckoutStep('payment');
                      }
                    }}
                    disabled={Boolean(activePlanSlug) || plan.is_current}
                    className={cn(
                      'mt-8 w-full rounded-2xl py-3 text-sm font-bold transition',
                      plan.is_current
                        ? 'cursor-default bg-emerald-100 text-emerald-700'
                        : meta.popular
                          ? 'bg-blue-600 text-white hover:bg-blue-700'
                          : 'bg-zinc-950 text-white hover:bg-zinc-800',
                      (activePlanSlug && !plan.is_current) || isDirectAutoDisabled ? 'cursor-not-allowed opacity-70' : ''
                    )}
                  >
                    {plan.is_current
                      ? 'Paket aktif'
                        : activePlanSlug === plan.slug
                          ? 'Memproses...'
                          : selectedPlanSlug === plan.slug
                            ? 'Paket dipilih'
                            : 'Pilih paket'}
                  </button>

                  {!plan.is_current && (
                    <button
                      type="button"
                      onClick={() => {
                        void applyPromoToPlan(plan);
                      }}
                      disabled={!promoCode.trim() || promoLoadingForPlan === plan.slug}
                      className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      <TicketPercent className="h-4 w-4" />
                      {promoLoadingForPlan === plan.slug ? 'Cek promo...' : 'Terapkan promo'}
                    </button>
                  )}
                </div>
              );
            })}
          </div>
          )}

          {checkoutStep !== 'plan' && selectedPlan && (
            <div className="mx-auto mt-6 max-w-3xl rounded-3xl border border-zinc-200 bg-white p-5">
              <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Ringkasan pilihan</p>
                  <h3 className="mt-1 text-lg font-bold text-zinc-950">{selectedPlan.name}</h3>
                  <p className="mt-1 text-sm text-zinc-600">
                    Rp {selectedFinalPrice.toLocaleString('id-ID')} {planMeta(selectedPlan, 0, false).period}
                    {selectedPromo ? ` - promo ${selectedPromo.code} aktif` : ''}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setCheckoutStep('plan')}
                  className="rounded-2xl border border-zinc-200 px-4 py-3 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-50"
                >
                  Ganti paket
                </button>
              </div>

              {checkoutStep === 'payment' && (
                <div className="mt-5 flex justify-end">
                  <button
                    type="button"
                    disabled={!canProceedFromPayment || isDirectAutoDisabled}
                    onClick={() => setCheckoutStep('confirm')}
                    className="rounded-2xl bg-zinc-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    Lanjut konfirmasi
                  </button>
                </div>
              )}

              {checkoutStep === 'confirm' && (
                <div className="mt-5 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                  <p className="text-sm font-semibold text-zinc-950">Konfirmasi checkout</p>
                  <p className="mt-2 text-sm text-zinc-600">
                    {paymentFlow === 'wallet'
                      ? 'Saldo wallet akan dipakai untuk aktivasi langsung jika saldo mencukupi.'
                      : adjustedCheckoutMode === 'manual_confirmation'
                        ? `Checkout akan dibuat sebagai pembayaran manual via ${manualPaymentMethod}.`
                        : `Anda akan diarahkan ke payment page ${providerLabel}.`}
                  </p>
                  <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button
                      type="button"
                      onClick={() => setCheckoutStep('payment')}
                      className="rounded-2xl border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-zinc-800 transition hover:bg-zinc-50"
                    >
                      Kembali
                    </button>
                    <button
                      type="button"
                      disabled={Boolean(activePlanSlug) || isDirectAutoDisabled}
                      onClick={() => void handleConfirmCheckout()}
                      className="rounded-2xl bg-zinc-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {activePlanSlug
                        ? 'Memproses...'
                        : paymentFlow === 'wallet'
                          ? 'Bayar wallet'
                          : adjustedCheckoutMode === 'manual_confirmation'
                            ? 'Buat pembayaran manual'
                            : `Bayar via ${providerLabel}`}
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}

          {!isLoadingPlans && decoratedPlans.length === 0 && !errorMessage && (
            <div className="mx-auto mt-6 max-w-3xl rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">
              Belum ada plan yang tersedia untuk aplikasi ini.
            </div>
          )}

          <div className="mt-8 flex items-start justify-center gap-2 text-xs text-zinc-500">
            <Sparkles className="mt-0.5 h-4 w-4 text-amber-600" />
            <p>Modal ini sekarang dipakai juga saat user klik POS yang terkunci di sidebar, jadi tidak lagi memakai modal lama yang bikin overlay gelap dan redirect mentah.</p>
          </div>
        </div>
      </div>

      {showReceiptModal && selectedMethod && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/55 p-4 backdrop-blur-sm">
          <div className="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl">
            <button
              onClick={() => {
                setShowReceiptModal(false);
                onSuccess?.();
                setTimeout(() => {
                  navigate('/dashboard/payments');
                  onClose();
                }, 300);
              }}
              className="absolute right-4 top-4 rounded-full bg-zinc-100 p-2 text-zinc-500 transition hover:bg-zinc-200"
            >
              <X className="h-5 w-5" />
            </button>

            <div className="text-center mb-4">
              <h3 className="text-lg font-bold text-zinc-950">Kwitansi Pembayaran</h3>
              <p className="text-sm text-zinc-600">Lakukan pembayaran segera dalam 1x24 jam</p>
            </div>

            <div className="space-y-4">
              {selectedMethod.image_url && (
                <div className="flex justify-center">
                  {selectedMethod.key === 'qris' ? (
                    <div className="relative">
                      <img src={getImageUrl(selectedMethod.image_url)} alt={selectedMethod.label} className="max-w-full h-auto rounded-lg" />
                      <button
                        onClick={() => window.open(getImageUrl(selectedMethod.image_url), '_blank')}
                        className="absolute bottom-2 right-2 rounded-full bg-zinc-900 p-2 text-white"
                      >
                        <Download className="h-4 w-4" />
                      </button>
                    </div>
                  ) : (
                    <img src={getImageUrl(selectedMethod.image_url)} alt={selectedMethod.label} className="max-w-full h-auto rounded-lg" />
                  )}
                </div>
              )}

              <div className="rounded-2xl border border-zinc-200 p-4">
                <h4 className="font-semibold text-zinc-900">{selectedMethod.label}</h4>
                {selectedMethod.bank_name && <p className="text-sm text-zinc-600">Bank: {selectedMethod.bank_name}</p>}
                {selectedMethod.account_name && <p className="text-sm text-zinc-600">Nama: {selectedMethod.account_name}</p>}
                {selectedMethod.account_number && (
                  <div className="flex items-center gap-2 mt-2">
                    <span className="text-sm text-zinc-600">{selectedMethod.account_number}</span>
                    <button
                      onClick={() => copyToClipboard(selectedMethod.account_number || '')}
                      className="rounded-full bg-zinc-100 p-1 text-zinc-600 hover:bg-zinc-200"
                    >
                      <Copy className="h-4 w-4" />
                    </button>
                  </div>
                )}
                {selectedMethod.instructions && (
                  <div className="mt-3 text-sm text-zinc-600">
                    <p className="font-medium">Instruksi:</p>
                    <p>{selectedMethod.instructions}</p>
                  </div>
                )}
              </div>

              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <p>Periksa email Anda untuk detail pembayaran dan konfirmasi status.</p>
              </div>

              <button
                type="button"
                onClick={() => {
                  setShowReceiptModal(false);
                  navigate('/dashboard/payments');
                  onClose();
                }}
                className="flex w-full items-center justify-center rounded-2xl bg-zinc-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
              >
                Lihat status pembayaran
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Gateway payment iframe — renders above the modal via portal */}
      {gatewayPaymentUrl && (
        <GatewayPaymentFrame
          paymentUrl={gatewayPaymentUrl}
          title={`Pembayaran ${providerLabel}`}
          onClose={() => setGatewayPaymentUrl(null)}
          onPaid={() => {
            setGatewayPaymentUrl(null);
            setSuccessMessage('Pembayaran berhasil dikonfirmasi! Akses langganan kamu sudah aktif.');
            setCheckoutStep('result');
            onSuccess?.();
          }}
          pollFn={
            gatewayPollSlug
              ? () => pollAppEntitlement(gatewayPollSlug)
              : undefined
          }
        />
      )}
    </div>
  );
}
