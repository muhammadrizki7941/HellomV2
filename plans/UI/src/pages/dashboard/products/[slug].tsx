import { type ReactElement, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  CheckCircle,
  CreditCard,
  Download,
  ExternalLink,
  Lock,
  MessageCircle,
  ShoppingBag,
} from 'lucide-react';
import {
  downloadProductFile,
  fetchAuthorizedBlobUrl,
  getConsumerProductBySlug,
  getImageUrl,
  getPaymentGatewayStatus,
  purchaseProduct,
} from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';

type ProductFile = {
  id: number;
  label: string;
  file_type?: string | null;
  version?: string | null;
  is_primary?: boolean;
};

type ProductDoc = {
  id: number;
  title: string;
  doc_type?: string | null;
  content?: string | null;
  file_path?: string | null;
  video_url?: string | null;
  external_url?: string | null;
};

type ProductDetail = {
  id: number;
  slug: string;
  name: string;
  tagline?: string | null;
  description?: string | null;
  category: string;
  type: string;
  price: number;
  thumbnail_url?: string | null;
  preview_images?: string[] | null;
  tech_stack?: string[] | null;
  tags?: string[] | null;
  files?: ProductFile[];
  docs?: ProductDoc[];
};

type Purchase = {
  id?: number;
  transaction_code?: string | null;
  payment_status?: string | null;
  amount_paid?: number | null;
  payment_method?: string | null;
  payment_gateway?: string | null;
  checkout_url?: string | null;
  created_at?: string | null;
};

type ManualPaymentMethod = {
  key: string;
  label: string;
  account_name?: string | null;
  account_number?: string | null;
  bank_name?: string | null;
  instructions?: string | null;
  image_url?: string | null;
};

type GatewayStatus = {
  provider?: string;
  is_ready?: boolean;
  supports?: Record<string, boolean>;
  manual_confirmation?: {
    enabled?: boolean;
    label?: string | null;
  };
  manual_payment?: {
    enabled?: boolean;
    notes?: string | null;
    methods?: ManualPaymentMethod[];
  };
};

type CheckoutStep = 'product' | 'payment' | 'confirm' | 'result';

const formatPrice = (price: number) => `Rp ${price.toLocaleString('id-ID')}`;

const normalizeWhatsAppNumber = (value?: string | null) => {
  const digits = String(value || '').replace(/\D/g, '');
  if (!digits) return '';
  if (digits.startsWith('62')) return digits;
  if (digits.startsWith('0')) return `62${digits.slice(1)}`;
  return digits;
};

const gatewayLabel = (provider?: string | null) => {
  if (provider === 'ipaymu') return 'iPaymu';
  if (provider === 'doku') return 'DOKU';
  if (provider === 'xendit') return 'Xendit';
  if (provider === 'manual') return 'Manual';
  if (provider === 'free') return 'Gratis';
  return 'Gateway';
};

const paymentMethodLabel = (value?: string | null, methods: ManualPaymentMethod[] = []) => {
  const method = methods.find((item) => item.key === value);
  if (method) return method.label;

  if (!value) return 'Belum dipilih';
  if (value === 'bank_transfer') return 'Transfer Bank';
  if (value === 'qris') return 'QRIS';
  if (value === 'gopay') return 'GoPay';
  if (value === 'dana') return 'DANA';

  return value.replace(/_/g, ' ');
};

const purchaseStatusLabel = (status?: string | null) => {
  if (status === 'paid') return 'Aktif';
  if (status === 'pending') return 'Menunggu Pembayaran';
  if (status === 'failed') return 'Pembayaran Gagal';
  if (status === 'refunded') return 'Refund';
  return 'Belum Ada Transaksi';
};

const purchaseStatusClass = (status?: string | null) => {
  if (status === 'paid') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
  if (status === 'pending') return 'border-amber-200 bg-amber-50 text-amber-800';
  if (status === 'failed') return 'border-rose-200 bg-rose-50 text-rose-700';
  if (status === 'refunded') return 'border-zinc-200 bg-zinc-100 text-zinc-600';
  return 'border-zinc-200 bg-zinc-50 text-zinc-600';
};

const resolveYoutubeEmbedUrl = (value?: string | null): string | null => {
  const raw = String(value || '').trim();
  if (!raw) return null;

  try {
    const url = new URL(raw);
    const host = url.hostname.replace(/^www\./, '');
    let videoId = '';

    if (host === 'youtu.be') {
      videoId = url.pathname.split('/').filter(Boolean)[0] || '';
    } else if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'music.youtube.com') {
      if (url.pathname === '/watch') {
        videoId = url.searchParams.get('v') || '';
      } else if (url.pathname.startsWith('/embed/')) {
        videoId = url.pathname.split('/')[2] || '';
      } else if (url.pathname.startsWith('/shorts/')) {
        videoId = url.pathname.split('/')[2] || '';
      } else if (url.pathname.startsWith('/live/')) {
        videoId = url.pathname.split('/')[2] || '';
      }
    }

    return videoId ? `https://www.youtube.com/embed/${videoId}` : null;
  } catch {
    return null;
  }
};

export default function ProductSlugPage(): ReactElement {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { brand } = useBrand();
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [product, setProduct] = useState<ProductDetail | null>(null);
  const [purchase, setPurchase] = useState<Purchase | null>(null);
  const [isPurchased, setIsPurchased] = useState(false);
  const [gatewayStatus, setGatewayStatus] = useState<GatewayStatus | null>(null);
  const [paymentFlow, setPaymentFlow] = useState<'gateway' | 'manual'>('gateway');
  const [manualMethod, setManualMethod] = useState('');
  const [docPreviewUrls, setDocPreviewUrls] = useState<Record<number, string>>({});
  const [checkoutStep, setCheckoutStep] = useState<CheckoutStep>('product');

  const loadDetail = async () => {
    if (!slug) return;

    setLoading(true);
    setError(null);

    try {
      const [detail, gateway] = await Promise.all([
        getConsumerProductBySlug(slug),
        getPaymentGatewayStatus().catch(() => null),
      ]);

      const payload = detail as {
        product?: ProductDetail;
        purchase?: Purchase | null;
        is_purchased?: boolean;
      };

      setProduct(payload.product || null);
      setPurchase(payload.purchase || null);
      setIsPurchased(Boolean(payload.is_purchased));
      setGatewayStatus((gateway as GatewayStatus | null) || null);
      if (payload.is_purchased) {
        setCheckoutStep('result');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat detail produk');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadDetail();
  }, [slug]);

  useEffect(() => {
    let cancelled = false;
    const createdUrls: string[] = [];

    const loadDocPreviews = async () => {
      if (!product?.id || !isPurchased || !product.docs?.length) {
        setDocPreviewUrls({});
        return;
      }

      const entries = await Promise.all(
        product.docs
          .filter((doc) => doc.doc_type === 'pdf')
          .map(async (doc) => {
            try {
              const blobUrl = await fetchAuthorizedBlobUrl(`/consumer/products/${product.id}/docs/${doc.id}/preview`);
              createdUrls.push(blobUrl);
              return [doc.id, blobUrl] as const;
            } catch {
              return [doc.id, ''] as const;
            }
          })
      );

      if (!cancelled) {
        setDocPreviewUrls(
          entries.reduce<Record<number, string>>((acc, [docId, url]) => {
            if (url) acc[docId] = url;
            return acc;
          }, {})
        );
      }
    };

    void loadDocPreviews();

    return () => {
      cancelled = true;
      createdUrls.forEach((url) => URL.revokeObjectURL(url));
    };
  }, [isPurchased, product]);

  const manualMethods = useMemo(
    () => gatewayStatus?.manual_payment?.methods || [],
    [gatewayStatus]
  );

  const selectedManualMethod = useMemo(
    () => manualMethods.find((item) => item.key === manualMethod) || null,
    [manualMethod, manualMethods]
  );

  const activeManualMethod = useMemo(() => {
    const activeKey = purchase?.payment_gateway === 'manual'
      ? purchase.payment_method || manualMethod
      : manualMethod;

    return manualMethods.find((item) => item.key === activeKey) || selectedManualMethod;
  }, [purchase?.payment_gateway, purchase?.payment_method, manualMethod, manualMethods, selectedManualMethod]);

  const isPending = purchase?.payment_status === 'pending';
  const isPendingManual = isPending && purchase?.payment_gateway === 'manual';
  const isPendingGateway = isPending && purchase?.payment_gateway !== 'manual';
  const isFree = product?.type === 'free' || (product?.price || 0) === 0;
  const gatewayReady = Boolean(gatewayStatus?.is_ready);
  const manualConfirmationEnabled = Boolean(gatewayStatus?.manual_confirmation?.enabled);
  const manualEnabled = manualConfirmationEnabled
    && Boolean(gatewayStatus?.manual_payment?.enabled)
    && manualMethods.length > 0;
  const supportsManual = manualEnabled;
  const canUseGateway = !isFree && gatewayReady;
  const canUseManual = !isFree && supportsManual;
  const paymentFlowOptions = [canUseGateway, canUseManual].filter(Boolean).length;

  useEffect(() => {
    if (purchase?.payment_gateway === 'manual') {
      setPaymentFlow('manual');
      if (purchase.payment_method) {
        setManualMethod(purchase.payment_method);
      }
      return;
    }

    if (purchase?.payment_gateway && purchase.payment_gateway !== 'manual') {
      setPaymentFlow('gateway');
      return;
    }

    if (!canUseGateway && canUseManual) {
      setPaymentFlow('manual');
      if (!manualMethod && manualMethods[0]?.key) {
        setManualMethod(manualMethods[0].key);
      }
      return;
    }

    if (canUseGateway) {
      setPaymentFlow('gateway');
      return;
    }

    if (canUseManual && manualMethods[0]?.key && !manualMethod) {
      setPaymentFlow('manual');
      setManualMethod(manualMethods[0].key);
    }
  }, [
    canUseGateway,
    canUseManual,
    manualMethod,
    manualMethods,
    purchase?.payment_gateway,
    purchase?.payment_method,
  ]);

  const whatsappUrl = useMemo(() => {
    const phone = normalizeWhatsAppNumber(brand.support_phone);
    if (!phone || !product) return '';

    const methodLabel = paymentMethodLabel(
      purchase?.payment_gateway === 'manual' ? purchase?.payment_method : manualMethod,
      manualMethods
    );
    const amount = formatPrice(Number(purchase?.amount_paid ?? product.price ?? 0));
    const message = [
      `Halo owner ${brand.business_name || brand.app_name},`,
      `Saya ingin konfirmasi pembayaran produk ${product.name}.`,
      `Invoice: ${purchase?.transaction_code || '-'}`,
      `Metode pembayaran: ${methodLabel}`,
      `Nominal: ${amount}`,
      `Mohon dibantu cek pembayaran saya. Terima kasih.`,
    ].join('\n');

    return `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
  }, [
    brand.app_name,
    brand.business_name,
    brand.support_phone,
    manualMethod,
    manualMethods,
    product,
    purchase?.amount_paid,
    purchase?.payment_gateway,
    purchase?.payment_method,
    purchase?.transaction_code,
  ]);

  const handlePurchase = async () => {
    if (!product || isPurchased || product.type === 'subscription_locked') return;

    if (!isFree && paymentFlow === 'manual' && !manualMethod) {
      const fallbackMethod = manualMethods[0]?.key || '';
      if (!fallbackMethod) {
        setError('Metode pembayaran manual belum tersedia.');
        return;
      }
      setManualMethod(fallbackMethod);
    }

    setSubmitting(true);
    setError(null);

    try {
      const resolvedManualMethod = manualMethod || manualMethods[0]?.key || '';
      const response = await purchaseProduct(product.id, {
        payment_flow: isFree ? undefined : paymentFlow,
        manual_payment_method: !isFree && paymentFlow === 'manual' ? resolvedManualMethod : undefined,
      });

      const payload = response as {
        checkout_url?: string | null;
      };

      await loadDetail();

      if (payload.checkout_url) {
        window.location.href = payload.checkout_url;
        return;
      }

      if (!isFree) {
        setCheckoutStep('result');
        navigate('/dashboard/my-purchases');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memproses pembelian');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDownload = async (fileId: number) => {
    if (!product) return;

    setSubmitting(true);
    setError(null);

    try {
      const response = await downloadProductFile(product.id, fileId);
      const url = (response as { download_url?: string }).download_url;
      if (url) {
        window.open(url, '_blank', 'noopener');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal generate link download');
    } finally {
      setSubmitting(false);
    }
  };

  const handleContinueGatewayPayment = () => {
    if (purchase?.checkout_url) {
      window.location.href = purchase.checkout_url;
    }
  };

  const handlePrimaryCheckoutAction = () => {
    if (isFree) {
      void handlePurchase();
      return;
    }

    if (checkoutStep === 'product') {
      setCheckoutStep('payment');
      return;
    }

    if (checkoutStep === 'payment') {
      if (paymentFlow === 'manual' && !manualMethod) {
        const fallbackMethod = manualMethods[0]?.key || '';
        if (!fallbackMethod) {
          setError('Metode pembayaran manual belum tersedia.');
          return;
        }
        setManualMethod(fallbackMethod);
      }
      setCheckoutStep('confirm');
      return;
    }

    void handlePurchase();
  };

  if (loading) {
    return <div className="text-sm text-zinc-500">Memuat detail produk...</div>;
  }

  if (!product) {
    return (
      <div className="space-y-3">
        <h1 className="text-xl font-semibold text-zinc-900">Checkout Produk</h1>
        <p className="text-sm text-zinc-600">Produk tidak ditemukan.</p>
        <Link to="/dashboard/products" className="text-sm font-semibold text-zinc-900">
          Kembali ke katalog
        </Link>
      </div>
    );
  }

  const thumbnail = getImageUrl(product.thumbnail_url || '');
  const checkoutSteps: Array<{ key: CheckoutStep; label: string }> = [
    { key: 'product', label: 'Produk' },
    { key: 'payment', label: 'Pembayaran' },
    { key: 'confirm', label: 'Konfirmasi' },
    { key: 'result', label: 'Hasil' },
  ];
  const currentStepIndex = checkoutSteps.findIndex((item) => item.key === checkoutStep);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Checkout Produk</p>
          <h1 className="mt-2 text-2xl font-bold text-zinc-900">{product.name}</h1>
          <p className="mt-1 text-sm text-zinc-600">
            {product.tagline || 'Pilih metode pembayaran dan lanjutkan checkout produk digital kamu.'}
          </p>
        </div>
        <Link to="/dashboard/products" className="text-sm font-semibold text-zinc-900">
          Kembali ke katalog
        </Link>
      </div>

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {error}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
        <div className="space-y-5">
          <div className="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            {thumbnail ? (
              <img src={thumbnail} alt={product.name} className="h-64 w-full object-cover" />
            ) : (
              <div className="h-64 w-full bg-gradient-to-br from-zinc-100 to-zinc-50" />
            )}
            <div className="p-5">
              <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-zinc-500">
                <span className="rounded-full border border-zinc-200 px-3 py-1">{product.category}</span>
                <span className="rounded-full border border-zinc-200 px-3 py-1">
                  {isFree ? 'Gratis' : formatPrice(product.price)}
                </span>
              </div>
              <p className="mt-4 text-sm leading-relaxed text-zinc-700">
                {product.description || 'Deskripsi produk akan ditampilkan di sini.'}
              </p>
              {product.tech_stack && product.tech_stack.length > 0 ? (
                <div className="mt-4 flex flex-wrap gap-2">
                  {product.tech_stack.map((tag) => (
                    <span key={tag} className="rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600">
                      {tag}
                    </span>
                  ))}
                </div>
              ) : null}
            </div>
          </div>

          {product.preview_images && product.preview_images.length > 0 ? (
            <div className="space-y-3">
              <h2 className="text-sm font-semibold text-zinc-900">Preview</h2>
              <div className="grid gap-3 sm:grid-cols-2">
                {product.preview_images.map((item, index) => (
                  <img
                    key={`${item}-${index}`}
                    src={getImageUrl(item)}
                    alt={`Preview ${index + 1}`}
                    className="h-40 w-full rounded-2xl border border-zinc-200 object-cover"
                    loading="lazy"
                  />
                ))}
              </div>
            </div>
          ) : null}

          {!isPurchased && !isFree && checkoutStep !== 'product' && (
            <div className="rounded-2xl border border-zinc-200 bg-white p-5">
              <div className="flex items-center gap-2">
                <CreditCard className="h-4 w-4 text-zinc-500" />
                <h2 className="text-sm font-semibold text-zinc-900">Metode Pembayaran</h2>
              </div>

              {paymentFlowOptions === 0 ? (
                <div className="mt-4 rounded-xl border border-dashed border-zinc-200 bg-zinc-50 px-4 py-4 text-sm text-zinc-600">
                  Owner belum mengaktifkan payment gateway atau manual payment untuk produk ini.
                </div>
              ) : (
                <div className="mt-4 space-y-4">
                  {canUseGateway && (
                    <label className={`flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-4 transition ${
                      paymentFlow === 'gateway'
                        ? 'border-zinc-900 bg-zinc-50'
                        : 'border-zinc-200 bg-white'
                    }`}>
                      <input
                        type="radio"
                        name="payment-flow"
                        className="mt-1"
                        checked={paymentFlow === 'gateway'}
                        onChange={() => setPaymentFlow('gateway')}
                      />
                      <div>
                        <div className="text-sm font-semibold text-zinc-900">
                          Pembayaran via {gatewayLabel(gatewayStatus?.provider)}
                        </div>
                        <p className="mt-1 text-sm text-zinc-600">
                          Kamu akan diarahkan ke halaman pembayaran aman dan status diperbarui otomatis setelah berhasil.
                        </p>
                      </div>
                    </label>
                  )}

                  {canUseManual && (
                    <label className={`flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-4 transition ${
                      paymentFlow === 'manual'
                        ? 'border-zinc-900 bg-zinc-50'
                        : 'border-zinc-200 bg-white'
                    }`}>
                      <input
                        type="radio"
                        name="payment-flow"
                        className="mt-1"
                        checked={paymentFlow === 'manual'}
                        onChange={() => {
                          setPaymentFlow('manual');
                          if (!manualMethod && manualMethods[0]?.key) {
                            setManualMethod(manualMethods[0].key);
                          }
                        }}
                      />
                      <div className="w-full">
                        <div className="text-sm font-semibold text-zinc-900">Bayar Manual (Transfer / QRIS)</div>
                        <p className="mt-1 text-sm text-zinc-600">
                          Lakukan transfer atau scan QR ke rekening owner, lalu kirim bukti pembayaran via WhatsApp.
                        </p>
                      </div>
                    </label>
                  )}

                  {paymentFlow === 'manual' && canUseManual && (
                    <div className="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                      <div>
                        <label htmlFor="manual-method" className="text-sm font-semibold text-zinc-900">
                          Pilih metode manual
                        </label>
                        <select
                          id="manual-method"
                          value={manualMethod}
                          onChange={(event) => setManualMethod(event.target.value)}
                          className="mt-2 w-full rounded-xl border border-zinc-200 bg-white px-3 py-3 text-sm text-zinc-900 outline-none focus:border-zinc-400"
                        >
                          <option value="">Pilih metode pembayaran manual</option>
                          {manualMethods.map((method) => (
                            <option key={method.key} value={method.key}>
                              {method.label}
                            </option>
                          ))}
                        </select>
                      </div>

                      {gatewayStatus?.manual_payment?.notes ? (
                        <div className="rounded-lg border border-zinc-200 bg-white px-3 py-3 text-sm text-zinc-600">
                          {gatewayStatus.manual_payment.notes}
                        </div>
                      ) : null}

                      {selectedManualMethod && (
                        <div className="rounded-xl border border-zinc-200 bg-white p-4">
                          <div className="text-sm font-semibold text-zinc-900">{selectedManualMethod.label}</div>
                          {selectedManualMethod.bank_name ? (
                            <p className="mt-2 text-sm text-zinc-600">Bank: {selectedManualMethod.bank_name}</p>
                          ) : null}
                          {selectedManualMethod.account_name ? (
                            <p className="mt-1 text-sm text-zinc-600">Atas nama: {selectedManualMethod.account_name}</p>
                          ) : null}
                          {selectedManualMethod.account_number ? (
                            <p className="mt-1 text-sm text-zinc-600">Nomor tujuan: {selectedManualMethod.account_number}</p>
                          ) : null}
                          {selectedManualMethod.instructions ? (
                            <p className="mt-3 text-sm leading-relaxed text-zinc-600">
                              {selectedManualMethod.instructions}
                            </p>
                          ) : null}
                          {selectedManualMethod.image_url ? (
                            <img
                              src={getImageUrl(selectedManualMethod.image_url)}
                              alt={selectedManualMethod.label}
                              className="mt-4 max-h-72 rounded-2xl border border-zinc-200 object-contain"
                            />
                          ) : null}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {isPendingManual && activeManualMethod && (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5">
              <h2 className="text-sm font-semibold text-amber-900">Instruksi Pembayaran Manual</h2>
              <p className="mt-2 text-sm text-amber-800">
                Pembayaran kamu sedang menunggu konfirmasi owner. Pastikan nominal sesuai lalu kirim bukti bayar melalui WhatsApp owner.
              </p>
              <div className="mt-4 rounded-xl border border-amber-200 bg-white px-4 py-4 text-sm text-zinc-700">
                <p className="font-semibold text-zinc-900">{activeManualMethod.label}</p>
                {activeManualMethod.bank_name ? <p className="mt-2">Bank: {activeManualMethod.bank_name}</p> : null}
                {activeManualMethod.account_name ? <p>Atas nama: {activeManualMethod.account_name}</p> : null}
                {activeManualMethod.account_number ? <p>Nomor tujuan: {activeManualMethod.account_number}</p> : null}
                {activeManualMethod.instructions ? (
                  <p className="mt-3 leading-relaxed">{activeManualMethod.instructions}</p>
                ) : null}
                {activeManualMethod.image_url ? (
                  <img
                    src={getImageUrl(activeManualMethod.image_url)}
                    alt={activeManualMethod.label}
                    className="mt-4 max-h-80 rounded-2xl border border-amber-100 object-contain"
                  />
                ) : null}
              </div>
              <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                <a
                  href={whatsappUrl || undefined}
                  target="_blank"
                  rel="noreferrer"
                  className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold ${
                    whatsappUrl
                      ? 'bg-zinc-900 text-white hover:bg-zinc-800'
                      : 'cursor-not-allowed bg-zinc-200 text-zinc-500'
                  }`}
                >
                  <MessageCircle className="h-4 w-4" />
                  Konfirmasi via WhatsApp
                </a>
                <button
                  type="button"
                  onClick={() => void loadDetail()}
                  className="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                  Refresh status
                </button>
              </div>
            </div>
          )}

          <div className="space-y-3">
            <h2 className="text-sm font-semibold text-zinc-900">File Produk</h2>
            <div className="space-y-2">
              {(product.files || []).length === 0 ? (
                <div className="rounded-xl border border-dashed border-zinc-200 bg-zinc-50 px-4 py-4 text-sm text-zinc-500">
                  Belum ada file yang tersedia.
                </div>
              ) : (
                product.files?.map((file) => (
                  <div key={file.id} className="flex items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-3">
                    <div>
                      <p className="text-sm font-semibold text-zinc-900">{file.label}</p>
                      <p className="text-xs text-zinc-500">
                        {file.file_type || 'file'} {file.version ? `- v${file.version}` : ''}
                      </p>
                    </div>
                    <button
                      type="button"
                      disabled={!isPurchased || submitting}
                      onClick={() => void handleDownload(file.id)}
                      className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold transition ${
                        isPurchased ? 'bg-zinc-900 text-white hover:bg-zinc-800' : 'bg-zinc-100 text-zinc-400'
                      }`}
                    >
                      <Download className="h-4 w-4" />
                      {isPurchased ? 'Download' : 'Terkunci'}
                    </button>
                  </div>
                ))
              )}
            </div>
          </div>

          {isPurchased && product.docs && product.docs.length > 0 ? (
            <div className="space-y-3">
              <h2 className="text-sm font-semibold text-zinc-900">Dokumentasi</h2>
              <div className="space-y-2">
                {product.docs.map((doc) => (
                  <div key={doc.id} className="rounded-xl border border-zinc-200 bg-white px-4 py-3">
                    <p className="text-sm font-semibold text-zinc-900">{doc.title}</p>
                    {doc.content ? <p className="mt-2 text-sm text-zinc-600">{doc.content}</p> : null}
                    {doc.doc_type === 'pdf' && docPreviewUrls[doc.id] ? (
                      <iframe
                        src={docPreviewUrls[doc.id]}
                        title={doc.title}
                        className="mt-4 h-[520px] w-full rounded-2xl border border-zinc-200"
                      />
                    ) : null}
                    {doc.doc_type === 'video' && resolveYoutubeEmbedUrl(doc.video_url) ? (
                      <iframe
                        src={resolveYoutubeEmbedUrl(doc.video_url) || undefined}
                        title={doc.title}
                        className="mt-4 aspect-video w-full rounded-2xl border border-zinc-200"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowFullScreen
                      />
                    ) : null}
                    {doc.external_url ? (
                      <a
                        href={doc.external_url}
                        className="mt-2 inline-flex text-sm font-semibold text-zinc-900"
                        target="_blank"
                        rel="noreferrer"
                      >
                        Buka link
                      </a>
                    ) : null}
                  </div>
                ))}
              </div>
            </div>
          ) : null}
        </div>

        <aside className="space-y-4">
          <div className="rounded-2xl border border-zinc-200 bg-white p-5">
            <h3 className="text-sm font-semibold text-zinc-900">Status Pembelian</h3>
            {!isPurchased && !isPending ? (
              <div className="mt-4 grid grid-cols-4 gap-2">
                {checkoutSteps.map((step, index) => (
                  <button
                    key={step.key}
                    type="button"
                    disabled={index > currentStepIndex || step.key === 'result'}
                    onClick={() => {
                      if (step.key !== 'result' && index <= currentStepIndex) {
                        setCheckoutStep(step.key);
                      }
                    }}
                    className={`rounded-xl border px-2 py-2 text-xs font-semibold transition ${
                      index === currentStepIndex
                        ? 'border-zinc-900 bg-zinc-900 text-white'
                        : index < currentStepIndex
                          ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                          : 'border-zinc-200 bg-white text-zinc-400'
                    }`}
                  >
                    {index + 1}. {step.label}
                  </button>
                ))}
              </div>
            ) : null}
            <div className={`mt-3 rounded-xl border px-4 py-3 text-sm ${purchaseStatusClass(purchase?.payment_status)}`}>
              {isPurchased ? (
                <div className="flex items-center gap-2">
                  <CheckCircle className="h-4 w-4" />
                  <span>Produk sudah aktif dan siap diunduh.</span>
                </div>
              ) : (
                <div>
                  <p className="font-semibold">{purchaseStatusLabel(purchase?.payment_status)}</p>
                  {purchase?.payment_status === 'pending' ? (
                    <p className="mt-1">
                      {isPendingManual
                        ? 'Menunggu verifikasi manual dari owner.'
                        : 'Menunggu penyelesaian pembayaran dari gateway.'}
                    </p>
                  ) : purchase?.payment_status === 'failed' ? (
                    <p className="mt-1">Pembayaran sebelumnya gagal. Kamu bisa ulangi checkout.</p>
                  ) : (
                    <p className="mt-1">Belum ada transaksi aktif untuk produk ini.</p>
                  )}
                </div>
              )}
            </div>

            <div className="mt-4 grid gap-3 text-sm text-zinc-600">
              <div>
                <div className="text-xs uppercase tracking-wide text-zinc-400">Metode</div>
                <div className="mt-1 font-medium text-zinc-900">
                  {purchase?.payment_gateway === 'manual'
                    ? paymentMethodLabel(purchase.payment_method, manualMethods)
                    : purchase?.payment_gateway
                      ? gatewayLabel(purchase.payment_gateway)
                      : canUseGateway
                        ? gatewayLabel(gatewayStatus?.provider)
                        : canUseManual
                          ? 'Bayar Manual'
                          : 'Belum tersedia'}
                </div>
              </div>

              <div>
                <div className="text-xs uppercase tracking-wide text-zinc-400">Harga</div>
                <div className="mt-1 font-medium text-zinc-900">
                  {isFree ? 'Gratis' : formatPrice(product.price)}
                </div>
              </div>

              {purchase?.transaction_code ? (
                <div>
                  <div className="text-xs uppercase tracking-wide text-zinc-400">Invoice</div>
                  <div className="mt-1 font-medium text-zinc-900">{purchase.transaction_code}</div>
                </div>
              ) : null}

              {!isPurchased && !isPending && checkoutStep === 'confirm' ? (
                <div className="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                  <div className="text-xs uppercase tracking-wide text-zinc-400">Ringkasan</div>
                  <p className="mt-2 text-sm text-zinc-700">
                    {paymentFlow === 'manual'
                      ? `Setelah konfirmasi, kamu akan mendapat instruksi pembayaran via ${paymentMethodLabel(manualMethod, manualMethods)}.`
                      : `Kamu akan diarahkan ke halaman pembayaran ${gatewayLabel(gatewayStatus?.provider)} dan status diperbarui otomatis.`}
                  </p>
                </div>
              ) : null}
            </div>
          </div>

          <div className="rounded-2xl border border-zinc-200 bg-white p-5">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-xs uppercase tracking-wide text-zinc-500">Harga</p>
                <p className="mt-1 text-xl font-semibold text-zinc-900">{isFree ? 'Gratis' : formatPrice(product.price)}</p>
              </div>
              {product.type === 'subscription_locked' ? (
                <span className="inline-flex items-center gap-2 rounded-full border border-zinc-200 px-3 py-1 text-xs text-zinc-500">
                  <Lock className="h-3.5 w-3.5" /> Butuh langganan
                </span>
              ) : null}
            </div>

            {!isPurchased && isPendingGateway && purchase?.checkout_url ? (
              <button
                type="button"
                onClick={handleContinueGatewayPayment}
                className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-zinc-900 px-4 py-3 text-sm font-semibold text-white hover:bg-zinc-800"
              >
                <ExternalLink className="h-4 w-4" />
                Lanjut ke Halaman Pembayaran
              </button>
            ) : null}

            {!isPurchased && isPendingManual && whatsappUrl ? (
              <a
                href={whatsappUrl}
                target="_blank"
                rel="noreferrer"
                className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-zinc-900 px-4 py-3 text-sm font-semibold text-white hover:bg-zinc-800"
              >
                <MessageCircle className="h-4 w-4" />
                Konfirmasi ke Owner
              </a>
            ) : null}

            <button
              type="button"
              disabled={
                isPurchased ||
                submitting ||
                product.type === 'subscription_locked' ||
                (!isFree && paymentFlowOptions === 0) ||
                (isPending && purchase?.payment_gateway === 'manual')
              }
              onClick={handlePrimaryCheckoutAction}
              className={`mt-4 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold transition ${
                product.type === 'subscription_locked'
                  ? 'bg-zinc-100 text-zinc-400'
                  : isPurchased
                    ? 'bg-emerald-600 text-white'
                    : isPendingManual
                      ? 'bg-amber-100 text-amber-700'
                      : isPendingGateway
                        ? 'border border-zinc-200 bg-white text-zinc-900 hover:bg-zinc-50'
                        : !isFree && paymentFlowOptions === 0
                          ? 'bg-zinc-100 text-zinc-400'
                          : 'bg-zinc-900 text-white hover:bg-zinc-800'
              }`}
            >
              {isPurchased ? (
                <>
                  <CheckCircle className="h-4 w-4" /> Sudah aktif
                </>
              ) : isPendingManual ? (
                'Menunggu konfirmasi manual'
              ) : isPendingGateway ? (
                <>
                  <ShoppingBag className="h-4 w-4" /> Coba Bayar Lagi
                </>
              ) : product.type === 'subscription_locked' ? (
                <>
                  <Lock className="h-4 w-4" /> Butuh langganan
                </>
              ) : (
                <>
                  <ShoppingBag className="h-4 w-4" /> {isFree
                    ? 'Aktifkan Gratis'
                    : checkoutStep === 'product'
                      ? 'Pilih metode pembayaran'
                      : checkoutStep === 'payment'
                        ? 'Lanjut konfirmasi'
                        : 'Konfirmasi & Bayar'}
                </>
              )}
            </button>

            {!isPurchased && !isPending && checkoutStep === 'confirm' ? (
              <button
                type="button"
                onClick={() => setCheckoutStep('payment')}
                className="mt-3 flex w-full items-center justify-center rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
              >
                Kembali pilih metode
              </button>
            ) : null}
          </div>
        </aside>
      </div>
    </div>
  );
}
