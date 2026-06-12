import { useEffect, useMemo, useState, type ReactNode, type JSX } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  ArrowRight,
  ChevronLeft,
  ChevronRight,
  Copy,
  MapPin,
  Minus,
  Phone,
  Plus,
  ReceiptText,
  Search,
  ShoppingBag,
  ShoppingCart,
  Sparkles,
  Star,
  Store,
  Ticket,
  UserRound,
  UtensilsCrossed,
  X,
} from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import { useCart } from '@/hooks/useCart';
import { getImageUrl, HELLOM_API_BASE } from '@/lib/hellomApi';
import {
  claimCustomerPromo,
  createCustomerReservation,
  createCustomerOrder,
  getCustomerMenu,
  getCustomerMenuByOrganization,
  type PosCustomerExperiencePayload,
  type PosMenuCategory,
  type PosMenuProduct,
} from '@/lib/pos/posApi';
import { cn } from '@/lib/utils';

function formatCurrency(value: number) {
  return `Rp ${value.toLocaleString('id-ID')}`;
}

function formatDate(value: string | null | undefined) {
  if (!value) return null;

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(date);
}

const BACKEND_WEB_BASE = HELLOM_API_BASE.replace(/\/api\/v1\/hellom\/?$/, '');

function absoluteAssetUrl(path: string | null | undefined) {
  if (!path) return null;
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:')) return path;
  if (path.startsWith('/')) return `${BACKEND_WEB_BASE}${path}`;
  return `${BACKEND_WEB_BASE}/${path}`;
}

function buildCustomerRoute(tableToken: string, organizationSlug?: string | null, suffix = '') {
  const base = organizationSlug
    ? tableToken
      ? `/customer/${organizationSlug}/order/${tableToken}`
      : `/customer/${organizationSlug}`
    : tableToken
      ? `/customer/order/${tableToken}`
      : '/';
  return `${base}${suffix}`;
}

function formatTableLabel(value: string | undefined, fallback?: string) {
  const label = (value || fallback || '').trim();
  if (!label) return '-';
  return /^meja\b/i.test(label) ? label : `Meja ${label}`;
}

const modalFieldClass =
  'mt-2 w-full rounded-2xl border border-[#E6A800] bg-[#FFFFFF] px-4 py-3 text-sm text-[#1A1A1A] outline-none placeholder:text-[#888888] focus:border-[#1A1A1A]';

function updateHash(id: string) {
  if (typeof window === 'undefined') return;
  window.history.replaceState(null, '', `#${id}`);
}

function goToSection(id: string) {
  updateHash(id);
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function openExternal(url: string | null | undefined) {
  if (!url) return;
  window.location.href = url;
}

function normalizeWhatsappNumber(value: string | null | undefined) {
  if (!value) return null;
  const digits = value.replace(/\D+/g, '');
  if (!digits) return null;
  if (digits.startsWith('62')) return digits;
  if (digits.startsWith('0')) return `62${digits.slice(1)}`;
  return digits;
}

function buildWhatsappLink(number: string | null | undefined, message: string) {
  const normalized = normalizeWhatsappNumber(number);
  if (!normalized) return null;
  return `https://wa.me/${normalized}?text=${encodeURIComponent(message)}`;
}

type PaymentMethod = 'cash' | 'transfer' | 'gopay' | 'dana' | 'qris_static' | 'qris';

function getPaymentLabel(method: PaymentMethod) {
  switch (method) {
    case 'cash':
      return 'Tunai';
    case 'transfer':
      return 'Transfer Bank';
    case 'gopay':
      return 'GoPay';
    case 'dana':
      return 'DANA';
    case 'qris':
    case 'qris_static':
      return 'QRIS';
    default:
      return 'Pembayaran';
  }
}

function buildPaymentDeeplink(template: string | null | undefined, amount: number) {
  if (!template) return null;
  return template.replace(/\{amount\}/g, String(amount));
}

type PublicPaymentMethod = {
  key: PaymentMethod;
  label: string;
  icon: string;
  info: string;
  bank_name?: string | null;
  account_number?: string | null;
  account_name?: string | null;
  number?: string | null;
  name?: string | null;
  deep_link?: string | null;
  qris_image?: string | null;
};

const DEFAULT_EXPERIENCE: PosCustomerExperiencePayload = {
  brand: {
    business_name: 'Resto',
    tagline: 'Selamat Datang',
    about: null,
    phone: null,
    whatsapp: null,
    address: null,
    instagram: null,
    website: null,
    primary_color: '#1A1A1A',
    secondary_color: '#2D2D2D',
    accent_color: '#F5C518',
    background_color: '#FFFFFF',
    logo_url: null,
    banner_url: null,
    banner_kind: null,
    google_rating: null,
  },
  routes: {
    legacy_order: '',
    promo: '',
    reservations: '',
    member_login: '',
    member_register: '',
    member_dashboard: '',
  },
  promos: [],
  reservations: [],
  summary: {
    promo_count: 0,
    reservation_count: 0,
  },
  payment: {
    qris_static_enabled: false,
    qris_static_image_url: null,
    require_paid_before_submit: true,
    whatsapp_number: null,
    gopay_enabled: false,
    gopay_account_name: null,
    gopay_account_number: null,
    gopay_deeplink_template: null,
    dana_enabled: false,
    dana_account_name: null,
    dana_account_number: null,
    dana_deeplink_template: null,
  },
  pending_order: null,
};

// Icon components (SVG inline, tidak butuh library tambahan)
const PaymentIcon = ({ type }: { type: string }) => {
  if (type === 'dana') {
    return <img src="/assets/DANA-ICON.png" alt="DANA" className="w-6 h-6 object-contain" />;
  }
  if (type === 'gopay') {
    return <img src="/assets/GOPAY-ICON.jpg" alt="GoPay" className="w-6 h-6 object-contain" />;
  }
  if (type === 'qris') {
    return <img src="/assets/QRIS-ICON.webp" alt="QRIS" className="w-6 h-6 object-contain" />;
  }
  const icons: Record<string, JSX.Element> = {
    cash: (
      <svg viewBox="0 0 24 24" className="w-6 h-6" fill="none"
          stroke="currentColor" strokeWidth={1.5}>
        <rect x="2" y="6" width="20" height="12" rx="2"/>
        <circle cx="12" cy="12" r="3"/>
        <path d="M6 12h.01M18 12h.01"/>
      </svg>
    ),
    transfer: (
      <svg viewBox="0 0 24 24" className="w-6 h-6" fill="none"
          stroke="currentColor" strokeWidth={1.5}>
        <path d="M3 9l4-4 4 4M7 5v14M21 15l-4 4-4-4M17 19V5"/>
      </svg>
    ),
  };
  return icons[type] || icons.cash;
};

// Warna per metode
const methodColors: Record<string, {
  bg: string; border: string;
  selectedBg: string; selectedBorder: string;
  iconBg: string; iconColor: string;
}> = {
  cash: {
    bg: 'bg-white',
    border: 'border-gray-200',
    selectedBg: 'bg-emerald-50',
    selectedBorder: 'border-emerald-500',
    iconBg: 'bg-emerald-100',
    iconColor: 'text-emerald-600',
  },
  transfer: {
    bg: 'bg-white',
    border: 'border-gray-200',
    selectedBg: 'bg-blue-50',
    selectedBorder: 'border-blue-500',
    iconBg: 'bg-blue-100',
    iconColor: 'text-blue-600',
  },
  gopay: {
    bg: 'bg-white',
    border: 'border-gray-200',
    selectedBg: 'bg-cyan-50',
    selectedBorder: 'border-cyan-500',
    iconBg: 'bg-cyan-100',
    iconColor: 'text-cyan-600',
  },
  dana: {
    bg: 'bg-white',
    border: 'border-gray-200',
    selectedBg: 'bg-sky-50',
    selectedBorder: 'border-sky-500',
    iconBg: 'bg-sky-100',
    iconColor: 'text-sky-700',
  },
  qris: {
    bg: 'bg-white',
    border: 'border-gray-200',
    selectedBg: 'bg-violet-50',
    selectedBorder: 'border-violet-500',
    iconBg: 'bg-violet-100',
    iconColor: 'text-violet-600',
  },
};

// Komponen utama PaymentSelector
const PaymentSelector = ({
  methods,
  selected,
  onSelect,
  totalAmount,
  onBack,
  onNext,
}: {
  methods: PublicPaymentMethod[];
  selected: string;
  onSelect: (methodKey: string) => void;
  totalAmount: number;
  onBack: () => void;
  onNext: () => void;
}) => {
  const formatRp = (n: number) => `Rp ${n.toLocaleString('id-ID')}`;
  const selectedMethod = methods.find((method) => method.key === selected) || null;

  const copyToClipboard = (text: string, label: string) => {
    if (!text) return;

    const showToast = (message: string) => {
      const toast = document.createElement('div');
      toast.textContent = message;
      toast.className = 'fixed top-4 left-1/2 z-[9999] -translate-x-1/2 rounded-full bg-gray-900 px-4 py-2 text-sm text-white shadow-lg';
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 2000);
    };

    navigator.clipboard.writeText(text).then(() => {
      showToast(`${label} disalin`);
    }).catch(() => {
      const textArea = document.createElement('textarea');
      textArea.value = text;
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
      showToast(`${label} disalin`);
    });
  };

  const handleDownloadQris = () => {
    if (!selectedMethod?.qris_image) return;
    const link = document.createElement('a');
    link.href = selectedMethod.qris_image;
    link.download = `qris-static-${totalAmount}.png`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div className="fixed inset-0 z-[70] bg-black/55 backdrop-blur-sm">
      <div className="absolute inset-x-0 bottom-0 mx-auto max-h-[92vh] max-w-[430px] rounded-t-[32px] bg-white">
        <div className="flex items-start justify-between gap-4 border-b border-[#E6A800] px-5 py-4">
          <div>
            <h2 className="text-[1.55rem] font-bold text-[#1A1A1A]">Pilih Cara Bayar</h2>
            <p className="mt-1 text-[13px] text-[#888888]">Total yang harus dibayar</p>
          </div>
          <button type="button" onClick={onBack} className="rounded-full p-2 text-[#888888] hover:bg-[#F9F9F9]">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="border-b border-[#E6A800] px-5 py-4">
          <div className="flex items-center justify-between rounded-[20px] bg-[#1F2937] px-4 py-3 text-white">
            <span className="text-[13px] font-medium text-white/90">Total pembayaran</span>
            <span className="text-[1.8rem] font-extrabold leading-none">{formatRp(totalAmount)}</span>
          </div>
        </div>

        <div className="max-h-[calc(92vh-220px)] space-y-4 overflow-y-auto px-5 py-5">
          {methods.length === 0 ? (
            <div className="rounded-[24px] border border-dashed border-[#E6A800] bg-[#F9F9F9] p-6 text-center">
              <p className="text-base font-semibold text-[#1A1A1A]">Belum ada metode pembayaran aktif</p>
              <p className="mt-2 text-sm text-[#888888]">Hubungi kasir atau admin untuk mengaktifkan pembayaran.</p>
            </div>
          ) : methods.map((method) => {
            const isSelected = selected === method.key;
            const colors = methodColors[method.key] || methodColors.cash;

            return (
              <div key={method.key}>
                <button
                  type="button"
                  onClick={() => onSelect(method.key)}
                  className={cn(
                    'w-full rounded-[24px] border bg-white p-4 text-left shadow-[0_10px_24px_rgba(15,23,42,0.08)] transition',
                    isSelected ? `${colors.selectedBorder} ${colors.selectedBg}` : 'border-[#E5E7EB]'
                  )}
                >
                  <div className="flex items-center gap-3">
                    <div className={cn('flex h-12 w-12 items-center justify-center rounded-2xl', colors.iconBg, colors.iconColor)}>
                      <PaymentIcon type={method.key} />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="text-[1rem] font-bold text-[#1A1A1A]">{method.label}</div>
                      <div className="mt-1 text-[13px] text-[#777777]">{method.info}</div>
                    </div>
                    <div className={cn(
                      'flex h-7 w-7 items-center justify-center rounded-full border-2 transition',
                      isSelected ? `${colors.selectedBorder} bg-[#1A1A1A]` : 'border-[#D1D5DB] bg-white'
                    )}>
                      {isSelected ? <div className="h-3 w-3 rounded-full bg-[#F5C518]" /> : null}
                    </div>
                  </div>
                </button>

                {isSelected ? (
                  <div className={cn('mt-3 rounded-[24px] border p-4', colors.selectedBorder, colors.selectedBg)}>
                    {method.key === 'transfer' && method.account_number ? (
                      <div className="rounded-[20px] border border-[#E6A800] bg-white p-4">
                        <div className="flex items-center justify-between gap-3">
                          <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#888888]">{method.bank_name || 'Transfer bank'}</p>
                            <p className="mt-2 text-lg font-extrabold tracking-wide text-[#1A1A1A]">{method.account_number}</p>
                            <p className="mt-1 text-[13px] text-[#666666]">a.n. {method.account_name || '-'}</p>
                          </div>
                          <button type="button" onClick={() => copyToClipboard(method.account_number || '', 'Nomor rekening')} className="rounded-full border border-[#E6A800] px-3 py-2 text-xs font-semibold text-[#1A1A1A]">
                            Salin
                          </button>
                        </div>
                        <p className="mt-3 text-[12px] text-[#666666]">Transfer tepat {formatRp(totalAmount)} agar mudah diverifikasi kasir.</p>
                      </div>
                    ) : null}

                    {(method.key === 'gopay' || method.key === 'dana') && method.number ? (
                      <div className="rounded-[20px] border border-[#E6A800] bg-white p-4">
                        <div className="flex items-center justify-between gap-3">
                          <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#888888]">{method.key === 'gopay' ? 'GoPay' : 'DANA'}</p>
                            <p className="mt-2 text-lg font-extrabold tracking-wide text-[#1A1A1A]">{method.number}</p>
                            <p className="mt-1 text-[13px] text-[#666666]">a.n. {method.name || '-'}</p>
                          </div>
                          <button type="button" onClick={() => copyToClipboard(method.number || '', method.key === 'gopay' ? 'Nomor GoPay' : 'Nomor DANA')} className="rounded-full border border-[#E6A800] px-3 py-2 text-xs font-semibold text-[#1A1A1A]">
                            Salin
                          </button>
                        </div>
                        {method.deep_link ? (
                          <button type="button" onClick={() => window.open(`${method.deep_link}&amount=${totalAmount}`, '_blank')} className="mt-4 w-full rounded-full bg-[#1A1A1A] px-4 py-3 text-[13px] font-semibold text-[#F5C518]">
                            Buka {method.key === 'gopay' ? 'GoPay' : 'DANA'}
                          </button>
                        ) : null}
                        <p className="mt-3 text-[12px] text-[#666666]">Nominal pembayaran akan diarahkan sesuai total order.</p>
                      </div>
                    ) : null}

                    {method.key === 'qris' ? (
                      <div className="rounded-[20px] border border-[#8B5CF6] bg-[#F7F5FF] p-4">
                        {method.qris_image ? (
                          <div className="rounded-[18px] bg-white px-4 py-4 text-left">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#8B5CF6]">QRIS statis</p>
                            <p className="mt-2 text-sm font-semibold text-[#1A1A1A]">QR akan ditampilkan setelah order dibuat.</p>
                            <p className="mt-2 text-[13px] leading-5 text-[#666666]">
                              Silakan lakukan pembayaran dan scan QRIS di halaman status order agar pesanan bisa diterima.
                            </p>
                            <button type="button" onClick={handleDownloadQris} className="mt-4 rounded-full border border-[#8B5CF6] bg-white px-4 py-2 text-[13px] font-semibold text-[#6D28D9]">
                              Unduh QR statis
                            </button>
                          </div>
                        ) : (
                          <div className="rounded-[18px] border border-dashed border-[#D9CCFF] bg-white px-4 py-6">
                            <p className="text-sm font-semibold text-[#1A1A1A]">QR belum tersedia</p>
                            <p className="mt-1 text-[12px] text-[#666666]">Admin belum mengunggah QR pembayaran.</p>
                          </div>
                        )}
                      </div>
                    ) : null}
                  </div>
                ) : null}
              </div>
            );
          })}
        </div>

        <div className="border-t border-[#E6A800] px-5 py-4" style={{ paddingBottom: 'calc(4rem + env(safe-area-inset-bottom))' }}>
          <button
            type="button"
            onClick={onNext}
            disabled={!selected}
            className={cn(
              'w-full rounded-[20px] px-4 py-4 text-[15px] font-bold transition disabled:cursor-not-allowed disabled:opacity-60',
              selected ? 'bg-[#1A1A1A] text-[#F5C518]' : 'bg-[#E5E7EB] text-[#9CA3AF]'
            )}
          >
            {selected ? `Pesan dengan ${selectedMethod?.label || 'metode ini'}` : 'Pilih metode pembayaran dulu'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default function OrderPage() {
  const { organizationSlug, tableToken } = useParams<{ organizationSlug?: string; tableToken?: string }>();
  const navigate = useNavigate();
  const { brand } = useBrand();
  const [resolvedTableToken, setResolvedTableToken] = useState<string>(tableToken || '');
  const cart = useCart(resolvedTableToken || organizationSlug || 'guest');
  const [menu, setMenu] = useState<PosMenuCategory[]>([]);
  const [tableName, setTableName] = useState('');
  const [tenantSlug, setTenantSlug] = useState<string | null>(organizationSlug || null);
  const [experience, setExperience] = useState<PosCustomerExperiencePayload>(DEFAULT_EXPERIENCE);
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null);
  const [featuredProductId, setFeaturedProductId] = useState<number | null>(null);
  const [showCart, setShowCart] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [customerName, setCustomerName] = useState('');
  const [notes, setNotes] = useState('');
  const [placingOrder, setPlacingOrder] = useState(false);
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<string>('');
  const [paymentMethods, setPaymentMethods] = useState<PublicPaymentMethod[]>([]);
  const [orderStep, setOrderStep] = useState<'menu' | 'cart' | 'payment' | 'confirm' | 'success'>('menu');
  const [activeSection, setActiveSection] = useState('home');
  const [spotlightAnimationKey, setSpotlightAnimationKey] = useState(0);
  const [customerIdentity, setCustomerIdentity] = useState({
    name: '',
    phone: '',
    email: '',
  });
  const [activePromoId, setActivePromoId] = useState<number | null>(null);
  const [promoClaimNotes, setPromoClaimNotes] = useState('');
  const [promoClaimLoading, setPromoClaimLoading] = useState(false);
  const [promoClaimMessage, setPromoClaimMessage] = useState<string | null>(null);
  const [promoClaimError, setPromoClaimError] = useState<string | null>(null);
  const [activeReservationId, setActiveReservationId] = useState<number | null>(null);
  const [showReservationModal, setShowReservationModal] = useState(false);
  const [reservationDate, setReservationDate] = useState('');
  const [reservationTime, setReservationTime] = useState('');
  const [reservationDuration, setReservationDuration] = useState('120');
  const [reservationGuests, setReservationGuests] = useState('10');
  const [reservationNotes, setReservationNotes] = useState('');
  const [reservationPackageQty, setReservationPackageQty] = useState<Record<number, number>>({});
  const [reservationMenuQty, setReservationMenuQty] = useState<Record<number, number>>({});
  const [reservationSubmitting, setReservationSubmitting] = useState(false);
  const [reservationMessage, setReservationMessage] = useState<string | null>(null);
  const [reservationError, setReservationError] = useState<string | null>(null);

  useEffect(() => {
    async function loadMenu() {
      if (!tableToken && !organizationSlug) {
        setError('Halaman customer tidak ditemukan.');
        setLoading(false);
        return;
      }

      try {
        setLoading(true);
        const data = tableToken
          ? await getCustomerMenu(tableToken)
          : await getCustomerMenuByOrganization(organizationSlug as string);
        const resolvedSlug = data.table.organization_slug || organizationSlug || null;
        const nextTableToken = data.table.public_id || tableToken || '';

        setMenu(data.categories);
        setExperience(data.experience || DEFAULT_EXPERIENCE);
        setTableName(data.table.name || data.table.code);
        setSelectedCategory(data.categories[0]?.id ?? null);
        setTenantSlug(resolvedSlug);
        setResolvedTableToken(nextTableToken);
        setError(null);

        if (resolvedSlug) {
          loadPaymentMethods(resolvedSlug);
        }

        if (tableToken && resolvedSlug && resolvedSlug !== organizationSlug) {
          navigate(buildCustomerRoute(nextTableToken, resolvedSlug, window.location.hash), { replace: true });
        }
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : 'Gagal memuat halaman order.');
      } finally {
        setLoading(false);
      }
    }

    void loadMenu();
  }, [navigate, organizationSlug, tableToken]);

  const loadPaymentMethods = async (slug: string) => {
    try {
      const res = await fetch(`${HELLOM_API_BASE}/pos/public/payment-methods/${slug}`, {
        headers: { 'Accept': 'application/json' },
      });
      const data = await res.json();
      if (data.success) {
        const methods = data.data.payment_methods || [];
        setPaymentMethods(methods);

        // Set default payment method if none selected or if selected is not available
        if (methods.length > 0 && (!selectedPaymentMethod || !methods.some(m => m.key === selectedPaymentMethod))) {
          // Prefer 'cash' if available, otherwise first method
          const defaultMethod = methods.find(m => m.key === 'cash') || methods[0];
          setSelectedPaymentMethod(defaultMethod.key);
        }
      }
    } catch (err) {
      console.error('Failed to load payment methods:', err);
      // Fallback to cash only
      setPaymentMethods([{
        key: 'cash',
        label: 'Tunai',
        icon: 'cash',
        info: 'Bayar langsung ke kasir',
      }]);
      setSelectedPaymentMethod('cash');
    }
  };

  const handleSubmitOrder = async () => {
    if (!activeTableToken || !selectedPaymentMethod) return;

    setPlacingOrder(true);
    try {
      const response = await createCustomerOrder({
        table_token: activeTableToken,
        items: cart.items.map(item => ({
          product_id: item.product.id,
          quantity: item.quantity,
        })),
        payment_method: selectedPaymentMethod as any,
        customer_name: customerName.trim() || undefined,
        customer_phone: undefined, // Add if needed
        notes: notes.trim() || undefined,
      });

      const orderNumber = response.order.order_number;
      cart.clear();
      setOrderStep('success');
      navigate(`${successRouteBase}/success/${orderNumber}`);
    } catch (err) {
      alert('Gagal membuat pesanan: ' + (err instanceof Error ? err.message : 'Unknown error'));
    } finally {
      setPlacingOrder(false);
    }
  };

  useEffect(() => {
    if (loading || typeof window === 'undefined' || !window.location.hash) return;
    const id = window.location.hash.replace('#', '');
    setActiveSection(id);
    window.setTimeout(() => {
      document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 150);
  }, [loading]);

  const products = useMemo(() => menu.flatMap((category) => category.products), [menu]);
  const productMap = useMemo(() => new Map(products.map((product) => [product.id, product])), [products]);
  const isProductAvailable = (product: PosMenuProduct) =>
    product.is_available_now ?? (product.is_available && (!product.track_stock || (product.stock ?? 0) > 0));
  const getStockLabel = (product: PosMenuProduct) => {
    if (!isProductAvailable(product)) return 'Habis';
    if (product.track_stock) return `Sisa ${product.stock ?? 0}`;
    return 'Unlimited';
  };
  const getStockBadgeClass = (product: PosMenuProduct) => {
    if (!isProductAvailable(product)) return 'bg-red-100 text-red-700 border-red-200';
    if (product.track_stock) return 'bg-emerald-100 text-emerald-700 border-emerald-200';
    return 'bg-slate-100 text-slate-600 border-slate-200';
  };
  const filteredProducts = useMemo(
    () => products.filter((product) => !selectedCategory || product.category?.id === selectedCategory),
    [products, selectedCategory]
  );
  const activePromo = useMemo(
    () => experience.promos.find((promo) => promo.id === activePromoId) || null,
    [activePromoId, experience.promos]
  );
  const activeReservation = useMemo(
    () => experience.reservations.find((reservation) => reservation.id === activeReservationId) || null,
    [activeReservationId, experience.reservations]
  );
  const featuredProduct = useMemo(() => {
    if (filteredProducts.length === 0) return null;
    return filteredProducts.find((product) => product.id === featuredProductId) || filteredProducts[0];
  }, [featuredProductId, filteredProducts]);
  const visibleCategoryChips = useMemo(() => {
    if (menu.length <= 4) return menu;
    const selectedIndex = menu.findIndex((category) => category.id === selectedCategory);

    if (selectedIndex <= 1) return menu.slice(0, 4);
    if (selectedIndex >= menu.length - 2) return menu.slice(-4);
    return menu.slice(selectedIndex - 1, selectedIndex + 3);
  }, [menu, selectedCategory]);

  const tenantBrand = experience.brand;
  const businessName = tenantBrand.business_name || 'Resto';
  const tagline = tenantBrand.tagline || tenantBrand.about || 'Selamat Datang';
  const businessLogo = absoluteAssetUrl(tenantBrand.logo_url);
  const bannerUrl = absoluteAssetUrl(tenantBrand.banner_url);
  const accent = '#F5C518';
  const primary = '#1A1A1A';
  const secondary = '#2D2D2D';
  const pendingOrder = experience.pending_order;
  const activeTableToken = resolvedTableToken || tableToken || '';
  const menuRoute = buildCustomerRoute(activeTableToken, tenantSlug, '#menu');
  const successRouteBase = buildCustomerRoute(activeTableToken, tenantSlug);
  const heroImage = bannerUrl || (products[0]?.image_path ? getImageUrl(products[0].image_path) : null);
  const tableLabel = tableToken ? formatTableLabel(tableName, tableToken) : 'Order Online';
  const paymentConfig = experience.payment;
  const qrisImageUrl = absoluteAssetUrl(paymentConfig?.qris_static_image_url);
  const groupedSections = [
    { id: 'home', label: 'Home' },
  { id: 'menu', label: 'Products' },
  { id: 'pesanan', label: 'Order' },
    { id: 'promo', label: 'Promo' },
  ];

  const reservationPackageTotal = useMemo(() => {
    if (!activeReservation) return 0;

    return activeReservation.items.reduce((total, item) => {
      const qty = reservationPackageQty[item.id] ?? (item.is_required ? item.qty : 0);
      if (qty <= 0) return total;
      return total + item.unit_price * qty;
    }, 0);
  }, [activeReservation, reservationPackageQty]);

  const reservationMenuTotal = useMemo(
    () =>
      Object.entries(reservationMenuQty).reduce((total, [productId, qty]) => {
        if (qty <= 0) return total;
        const product = productMap.get(Number(productId));
        return total + (product ? product.price * qty : 0);
      }, 0),
    [productMap, reservationMenuQty]
  );

  const reservationGrandTotal = useMemo(() => {
    if (!activeReservation) return 0;
    const rent = activeReservation.rent_enabled ? activeReservation.rent_price : 0;
    return rent + reservationPackageTotal + reservationMenuTotal;
  }, [activeReservation, reservationMenuTotal, reservationPackageTotal]);

  const handleCheckout = async () => {
    setShowCart(false);
    setOrderStep('payment');
  };

  useEffect(() => {
    setFeaturedProductId(filteredProducts[0]?.id ?? null);
  }, [filteredProducts]);

  useEffect(() => {
    if (!featuredProduct?.id) return;
    setSpotlightAnimationKey((value) => value + 1);
  }, [featuredProduct?.id]);

  // Set default payment method based on available methods
  useEffect(() => {
    if (paymentMethods.length === 0) return;
    if (paymentMethods.some((method) => method.key === selectedPaymentMethod)) return;

    // Default to 'cash' if available, otherwise first available method
    const defaultMethod = paymentMethods.find(m => m.key === 'cash') || paymentMethods[0];
    if (defaultMethod) {
      setSelectedPaymentMethod(defaultMethod.key);
    }
  }, [paymentMethods, selectedPaymentMethod]);

  const handleSectionSelect = (id: string) => {
    setActiveSection(id);
    goToSection(id);
  };

  const openPromoClaimModal = (promoId: number) => {
    setActivePromoId(promoId);
    setPromoClaimNotes('');
    setPromoClaimError(null);
    setPromoClaimMessage(null);
  };

  const openReservationModal = (reservationId: number) => {
    const reservation = experience.reservations.find((item) => item.id === reservationId);
    setActiveReservationId(reservationId);
    setShowReservationModal(true);
    setReservationError(null);
    setReservationMessage(null);
    setReservationNotes('');
    setReservationMenuQty({});
    setReservationDuration('120');
    setReservationGuests(reservation?.capacity ? String(Math.min(reservation.capacity, 10)) : '10');
    setReservationPackageQty(
      Object.fromEntries(
        (reservation?.items || []).map((item) => [item.id, item.is_required ? item.qty : 0])
      )
    );
  };

  const handleCategorySelect = (categoryId: number | null) => {
    setSelectedCategory(categoryId);
    setFeaturedProductId(null);
  };

  const handleFeaturedCheckout = () => {
    if (!featuredProduct || !isProductAvailable(featuredProduct)) return;
    cart.addItem(featuredProduct);
    setShowCart(true);
  };

  const scrollCategoryRail = (direction: 'left' | 'right') => {
    const rail = document.getElementById('customer-menu-category-rail');
    if (!rail) return;
    rail.scrollBy({ left: direction === 'left' ? -220 : 220, behavior: 'smooth' });
  };

  const handleClaimPromo = async () => {
    if (!activeTableToken || !activePromo) return;

    setPromoClaimLoading(true);
    setPromoClaimError(null);
    setPromoClaimMessage(null);

    try {
      const result = await claimCustomerPromo(
        {
          table_token: activeTableToken,
          customer_name: customerIdentity.name.trim(),
          customer_phone: customerIdentity.phone.trim(),
          customer_email: customerIdentity.email.trim() || undefined,
          notes: promoClaimNotes.trim() || undefined,
        },
        activePromo.id
      );

      setPromoClaimMessage(
        result.already_claimed
          ? 'Promo ini sudah pernah diambil dengan nomor pelanggan yang sama.'
          : `Promo berhasil diambil${result.awarded_points ? ` dan Anda mendapat ${result.awarded_points} poin.` : '.'}`
      );
    } catch (claimError) {
      setPromoClaimError(claimError instanceof Error ? claimError.message : 'Gagal mengambil promo.');
    } finally {
      setPromoClaimLoading(false);
    }
  };

  const handleSubmitReservation = async () => {
    if (!activeTableToken || !activeReservation) return;

    const scheduledAt = reservationDate && reservationTime ? `${reservationDate} ${reservationTime}` : '';
    const selectedSpaceItems = activeReservation.items
      .map((item) => ({
        item_id: item.id,
        qty: reservationPackageQty[item.id] ?? (item.is_required ? item.qty : 0),
      }))
      .filter((item) => item.qty > 0);
    const menuItems = Object.entries(reservationMenuQty)
      .filter(([, qty]) => qty > 0)
      .map(([productId, qty]) => ({
        product_id: Number(productId),
        qty,
      }));

    setReservationSubmitting(true);
    setReservationError(null);
    setReservationMessage(null);

    try {
      const result = await createCustomerReservation({
        table_token: activeTableToken,
        reservation_space_id: activeReservation.id,
        customer_name: customerIdentity.name.trim(),
        customer_phone: customerIdentity.phone.trim(),
        customer_email: customerIdentity.email.trim() || undefined,
        scheduled_at: scheduledAt,
        duration_minutes: Number(reservationDuration),
        guests_count: Number(reservationGuests),
        selected_space_items: selectedSpaceItems,
        menu_items: menuItems,
        notes: reservationNotes.trim() || undefined,
      });

      setReservationMessage(
        `Reservasi terkirim. Estimasi poin loyalty untuk booking ini: ${result.estimated_points} poin.`
      );
    } catch (submitError) {
      setReservationError(submitError instanceof Error ? submitError.message : 'Gagal mengirim reservasi.');
    } finally {
      setReservationSubmitting(false);
    }
  };

  return (
    <div
      className="min-h-screen text-[#1A1A1A]"
      style={{ background: '#FFFFFF', paddingBottom: 'calc(11rem + env(safe-area-inset-bottom))' }}
    >
      <div className="mx-auto max-w-[430px] px-4 py-5">
        <header id="home" className="mb-5 flex items-center gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/80 bg-white shadow-sm">
            {businessLogo ? (
              <img src={businessLogo} alt={businessName} className="h-9 w-9 rounded-xl object-contain" />
            ) : (
              <Store className="h-5 w-5 text-[#1A1A1A]" />
            )}
          </div>
          <div className="min-w-0">
            <p className="truncate text-2xl font-semibold text-[#1A1A1A]">{businessName}</p>
            <p className="truncate text-sm text-[#888888]">{tagline}</p>
          </div>
        </header>

        <section className="overflow-hidden rounded-[32px] border border-white/70 bg-white shadow-[0_22px_60px_rgba(15,23,42,0.08)]">
          <div className="relative min-h-[330px] overflow-hidden p-5">
            {heroImage ? (
              <img src={heroImage} alt={businessName} className="absolute inset-0 h-full w-full object-cover" />
            ) : (
              <div
                className="absolute inset-0"
                style={{ background: `linear-gradient(135deg, ${primary} 0%, ${secondary} 58%, ${accent} 100%)` }}
              />
            )}
            <div className="absolute inset-0 bg-black/55" />

            <div className="relative flex min-h-[290px] flex-col">
              <div className="flex items-start justify-between gap-3">
                <div className="inline-flex rounded-full bg-white/12 px-4 py-2 text-sm font-medium text-white backdrop-blur">
                  {tableLabel}
                </div>
                {tenantBrand.google_rating && (
                  <div className="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-semibold text-white backdrop-blur">
                    <Star className="h-4 w-4 text-[#F5C518]" />
                    {tenantBrand.google_rating.rating.toFixed(1)} Rating
                  </div>
                )}
              </div>

              <div className="mt-auto text-center text-white">
                <h1 className="text-[2.15rem] font-bold leading-none" style={{ color: '#FFFFFF', textShadow: '0 2px 8px rgba(0,0,0,0.8)' }}>
                  {businessName}
                </h1>
                <p className="mt-3 text-lg font-medium" style={{ color: '#F5C518' }}>{tagline}</p>

                <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
                  <button
                    type="button"
                    onClick={() => goToSection('menu')}
                    className="inline-flex min-w-[170px] items-center justify-center gap-2 rounded-full px-6 py-4 text-base font-bold shadow-lg"
                    style={{ backgroundColor: '#F5C518', color: '#1A1A1A' }}
                  >
                    Pesan Sekarang
                  </button>
                  <button
                    type="button"
                    onClick={() => goToSection('reservasi')}
                    className="inline-flex min-w-[140px] items-center justify-center rounded-full border border-white px-6 py-4 text-base font-semibold text-white backdrop-blur"
                    style={{ backgroundColor: 'transparent' }}
                  >
                    Reservasi
                  </button>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section className="mt-6">
          <div className="grid grid-cols-3 gap-3">
            <QuickAccessCard
              icon={<Store className="h-5 w-5" />}
              label="Home"
              onClick={() => handleSectionSelect('home')}
            />
            <QuickAccessCard
              icon={<UtensilsCrossed className="h-5 w-5" />}
              label="Products"
              onClick={() => handleSectionSelect('menu')}
            />
            <QuickAccessCard
              icon={<ReceiptText className="h-5 w-5" />}
              label="Order"
              onClick={() => handleSectionSelect('pesanan')}
            />
            <QuickAccessCard
              icon={<Sparkles className="h-5 w-5" />}
              label="Reservasi"
              onClick={() => handleSectionSelect('reservasi')}
            />
            <QuickAccessCard
              icon={<Ticket className="h-5 w-5" />}
              label="Promo"
              onClick={() => handleSectionSelect('promo')}
            />
            <QuickAccessCard
              icon={<UserRound className="h-5 w-5" />}
              label="Login"
              onClick={() => handleSectionSelect('member')}
            />
          </div>
        </section>

        {error && (
          <div className="mt-5 rounded-[26px] border border-[#E6A800] bg-[#F9F9F9] px-4 py-3 text-sm text-[#1A1A1A]">
            {error}
          </div>
        )}

        <section id="promo" className="mt-8">
          <SectionHeading
            title="Promo & Diskon"
            description="Penawaran spesial untuk hari ini."
            actionLabel={experience.promos.length > 1 ? 'Lihat Semua' : undefined}
            onAction={() => goToSection('promo-list')}
          />

          <div id="promo-list" className="mt-4 space-y-4">
            {experience.promos.length > 0 ? (
              <>
                <PromoHeroCard promo={experience.promos[0]} accent={accent} />
                {experience.promos.slice(1).map((promo) => (
                  <CompactPromoCard key={promo.id} promo={promo} />
                ))}
              </>
            ) : (
              <SoftEmptyCard
                title="Belum ada promo aktif"
                description="Begitu tenant menambahkan promo, kartu promo akan langsung tampil di area ini."
              />
            )}
          </div>

          {experience.promos.length > 0 ? (
            <div className="mt-4 grid gap-3">
              {experience.promos.map((promo) => (
                <button
                  key={`claim-${promo.id}`}
                  type="button"
                  onClick={() => openPromoClaimModal(promo.id)}
                  className="flex items-center justify-between rounded-[24px] border border-[#E6A800] bg-[#F9F9F9] px-5 py-4 text-left"
                >
                  <div>
                    <p className="text-sm font-semibold text-[#1A1A1A]">{promo.title}</p>
                    <p className="mt-1 text-xs text-[#888888]">
                      {promo.promo_code ? `Kode ${promo.promo_code}` : 'Ambil promo langsung'}
                      {promo.bonus_points > 0 ? ` Ã¢â‚¬Â¢ Bonus ${promo.bonus_points} poin` : ''}
                    </p>
                  </div>
                  <span className="rounded-full bg-white px-3 py-2 text-xs font-bold text-[#1A1A1A]">Ambil promo</span>
                </button>
              ))}
            </div>
          ) : null}

          <div className="mt-6 rounded-[28px] border border-white/80 bg-white p-6 text-center shadow-sm">
            <h3 className="text-[1.7rem] font-extrabold text-[#1A1A1A]">Siap untuk memesan?</h3>
            <p className="mt-3 text-sm leading-6 text-[#888888]">Lihat menu lengkap dan mulai pesan sekarang.</p>
            <button
              type="button"
              onClick={() => goToSection('menu')}
              className="mt-6 inline-flex items-center gap-2 rounded-full px-6 py-4 text-base font-semibold"
              style={{ backgroundColor: '#1A1A1A', color: '#F5C518' }}
            >
              View Products
              <ArrowRight className="h-4 w-4" />
            </button>
          </div>
        </section>

        <section id="pesanan" className="mt-8 rounded-[28px] border border-white/75 bg-white p-5 shadow-sm">
          <SectionHeading title="Order Status" description="Check the progress of active orders at this table." />

          {pendingOrder ? (
            <div className="mt-4 rounded-[24px] bg-[linear-gradient(135deg,#1A1A1A,#2D2D2D)] p-5 text-white">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-sm font-medium text-white/70">Active orders</p>
                  <h3 className="mt-1 text-2xl font-bold">{pendingOrder.order_number}</h3>
                  <p className="mt-2 text-sm text-white/80">{statusLabel(pendingOrder.status)}</p>
                </div>
                <button
                  type="button"
                  onClick={async () => {
                    if (!pendingOrder.order_number || !navigator.clipboard) return;
                    await navigator.clipboard.writeText(pendingOrder.order_number);
                  }}
                  className="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white/10 text-white"
                >
                  <Copy className="h-4 w-4" />
                </button>
              </div>

              <div className="mt-4 space-y-3">
                {pendingOrder.items.slice(0, 3).map((item) => (
                  <div key={`${item.id}-${item.product_id}`} className="rounded-2xl bg-white/10 px-4 py-3">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="font-semibold">{item.product_name}</p>
                        <p className="mt-1 text-sm text-white/70">{item.quantity} item</p>
                      </div>
                      <p className="font-semibold">{formatCurrency(item.line_total)}</p>
                    </div>
                  </div>
                ))}
              </div>

              <div className="mt-4 rounded-2xl bg-white/10 px-4 py-4">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-xs uppercase tracking-[0.18em] text-white/55">Metode pembayaran</p>
                    <p className="mt-1 text-base font-semibold">
                      {getPaymentLabel((pendingOrder.payment_method as PaymentMethod) || 'cash')}
                    </p>
                  </div>
                  <div className="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/75">
                    {pendingOrder.payment_status === 'paid' ? 'Sudah dibayar' : 'Menunggu konfirmasi'}
                  </div>
                </div>

                {(pendingOrder.payment_method === 'qris' || pendingOrder.payment_method === 'qris_static') && qrisImageUrl ? (
                  <div className="mt-4 rounded-2xl bg-white px-4 py-4 text-center text-[#1A1A1A]">
                    <img
                      src={qrisImageUrl}
                      alt="QRIS static"
                      className="mx-auto h-44 w-44 rounded-2xl object-contain"
                    />
                    <p className="mt-3 text-sm font-semibold">Silakan lakukan pembayaran dan scan QRIS ini.</p>
                    <p className="mt-1 text-xs text-[#666666]">Agar pesanan diterima, selesaikan pembayaran melalui QRIS pada status order ini.</p>
                    <p className="mt-2 text-sm font-bold text-[#1A1A1A]">{formatCurrency(pendingOrder.final_amount || pendingOrder.total_amount)}</p>
                  </div>
                ) : null}

                {pendingOrder.payment_method === 'transfer' && paymentMethods.find((method) => method.key === 'transfer') ? (
                  <div className="mt-4 rounded-2xl bg-white px-4 py-4 text-[#1A1A1A]">
                    <p className="text-xs uppercase tracking-[0.18em] text-[#888888]">Transfer bank</p>
                    <p className="mt-2 text-lg font-bold">
                      {paymentMethods.find((method) => method.key === 'transfer')?.account_number || '-'}
                    </p>
                    <p className="mt-1 text-sm text-[#666666]">
                      {(paymentMethods.find((method) => method.key === 'transfer')?.bank_name || 'Bank')}
                      {' â€¢ '}
                      {(paymentMethods.find((method) => method.key === 'transfer')?.account_name || '-')}
                    </p>
                  </div>
                ) : null}

                {(pendingOrder.payment_method === 'gopay' || pendingOrder.payment_method === 'dana') && paymentMethods.find((method) => method.key === pendingOrder.payment_method) ? (
                  <div className="mt-4 rounded-2xl bg-white px-4 py-4 text-[#1A1A1A]">
                    <p className="text-xs uppercase tracking-[0.18em] text-[#888888]">
                      {getPaymentLabel(pendingOrder.payment_method as PaymentMethod)}
                    </p>
                    <p className="mt-2 text-lg font-bold">
                      {paymentMethods.find((method) => method.key === pendingOrder.payment_method)?.number || '-'}
                    </p>
                    <p className="mt-1 text-sm text-[#666666]">
                      a.n. {paymentMethods.find((method) => method.key === pendingOrder.payment_method)?.name || '-'}
                    </p>
                  </div>
                ) : null}
              </div>
            </div>
          ) : (
            <SoftEmptyCard
              className="mt-4"
              title="Belum ada pesanan aktif"
              description="Saat tamu mulai checkout, ringkasan pesanan meja akan tampil di sini."
            />
          )}
        </section>

        <section id="reservasi" className="mt-8 rounded-[28px] border border-white/75 bg-white p-5 shadow-sm">
          <SectionHeading title="Reservasi Tempat" description="Lihat ruang, kapasitas, dan kebutuhan minimum tenant." />
          <div className="mt-4 space-y-4">
            {experience.reservations.length > 0 ? (
              experience.reservations.map((reservation) => (
                <ReservationCard key={reservation.id} reservation={reservation} onBook={() => openReservationModal(reservation.id)} />
              ))
            ) : (
              <SoftEmptyCard
                title="Belum ada area reservasi"
                description="Tenant ini belum mengaktifkan area reservasi, tapi tamu masih bisa order langsung dari menu."
              />
            )}
          </div>
        </section>

        <section id="menu" className="mt-8">
          <div
            className="overflow-hidden rounded-[32px] border border-white/10 px-4 py-5 text-white shadow-[0_26px_80px_rgba(8,15,23,0.18)]"
            style={{ background: `radial-gradient(circle at top left, ${accent}18 0%, transparent 28%), linear-gradient(150deg, #1A1A1A 0%, #2D2D2D 100%)` }}
          >
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-sm font-bold">Featured Products</p>
                <p className="mt-2 text-xs text-white/60">Meja aktif: {tableLabel}</p>
              </div>
              <p className="max-w-[120px] text-right text-[11px] leading-5 text-white/65">Pilih kategori di sisi gambar</p>
            </div>

            {loading ? (
              <SoftEmptyCard
                className="mt-4 border-white/10 bg-white/5 text-white"
                title="Memuat menu"
                description="Tunggu sebentar, menu tenant sedang kami siapkan."
              />
            ) : featuredProduct ? (
              <div key={spotlightAnimationKey} className="animate-[spotlightSlideUp_420ms_ease-out]">
                <div className="mt-4 flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => handleCategorySelect(selectedCategory)}
                    className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/8 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur"
                  >
                    <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: accent }} />
                    {featuredProduct.category?.name || 'Store products'}
                  </button>
                </div>

                <div className="mt-5">
                  <h2 className="text-[2.2rem] font-extrabold leading-none tracking-[-0.03em]">{featuredProduct.name}</h2>
                  <p className="mt-3 max-w-[290px] text-sm leading-6 text-white/78">
                    {featuredProduct.description || 'Rasa yang pas, dibuat fresh, dan cocok untuk temani momen kamu.'}
                  </p>
                </div>

                <div className="mt-5 flex flex-wrap items-center gap-4">
                  <div className="rounded-[18px] border border-white/14 bg-white/8 px-4 py-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]">
                    <p className="text-[11px] text-white/65">Harga Untukmu</p>
                    <p className="mt-1 text-[1.8rem] font-extrabold leading-none">{formatCurrency(featuredProduct.price)}</p>
                    <span className={cn('mt-2 inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold', getStockBadgeClass(featuredProduct))}>
                      {getStockLabel(featuredProduct)}
                    </span>
                  </div>
                  <button
                    type="button"
                    onClick={handleFeaturedCheckout}
                    disabled={!isProductAvailable(featuredProduct)}
                    className="inline-flex min-h-[56px] items-center gap-3 rounded-full px-6 py-4 text-base font-bold text-white shadow-[0_16px_32px_rgba(16,185,129,0.35)] disabled:cursor-not-allowed disabled:opacity-50"
                    style={{ backgroundColor: accent }}
                  >
                    <span className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/12">
                      <ShoppingCart className="h-5 w-5" />
                    </span>
                    Checkout
                  </button>
                </div>

                <div className="mt-6 rounded-[28px] border border-white/12 bg-white/6 p-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)] backdrop-blur">
                  <div className="relative overflow-hidden rounded-[24px] bg-black/15">
                    <div className="aspect-[1.12] bg-[#1A1A1A]/40">
                      {featuredProduct.image_path ? (
                        <img
                          src={getImageUrl(featuredProduct.image_path)}
                          alt={featuredProduct.name}
                          className={cn('h-full w-full object-cover', !isProductAvailable(featuredProduct) && 'grayscale')}
                        />
                      ) : (
                        <div className="flex h-full items-center justify-center text-white/60">
                          <Store className="h-10 w-10" />
                        </div>
                      )}
                    </div>
                    {!isProductAvailable(featuredProduct) && (
                      <div className="absolute inset-x-0 bottom-0 bg-red-500/90 text-white text-xs font-semibold text-center py-1">
                        Habis
                      </div>
                    )}

                    <div className="pointer-events-none absolute inset-y-0 right-3 flex flex-col items-end justify-center gap-3">
                      {visibleCategoryChips.map((category, index) => {
                        const active = selectedCategory === category.id;
                        index = 1;
                        return (
                          <button
                            key={category.id}
                            type="button"
                            onClick={() => handleCategorySelect(category.id)}
                            className={cn(
                              'pointer-events-auto rounded-full px-3 py-1.5 text-[11px] font-semibold shadow-lg transition',
                              active ? 'text-[#1A1A1A]' : 'bg-white/85 text-[#888888]'
                            )}
                            style={active ? { backgroundColor: accent } : undefined}
                          >
                            {index === 0 && !active ? 'Ã¢â€“Â² ' : ''}
                            {category.name}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                </div>

                <div className="mt-5">
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-xs font-semibold text-[#F5C518]">Rekomendasi di kategori ini</p>
                    <button
                      type="button"
                      onClick={() => scrollCategoryRail('right')}
                      className="text-xs font-medium text-white/70"
                    >
                      Geser <ArrowRight className="inline h-3.5 w-3.5" />
                    </button>
                  </div>

                  <div className="mt-3 flex gap-2.5 overflow-x-auto pb-1">
                    {filteredProducts.map((product) => {
                      const active = product.id === featuredProduct.id;
                      const available = isProductAvailable(product);

                      return (
                        <button
                          key={product.id}
                          type="button"
                          onClick={() => setFeaturedProductId(product.id)}
                          className={cn(
                            'min-w-[76px] max-w-[76px] rounded-[18px] border p-1.5 text-left transition',
                            active ? 'border-white/40 bg-white/16 shadow-[0_14px_28px_rgba(0,0,0,0.22)]' : 'border-white/12 bg-white/[0.06]',
                            !available && 'opacity-70'
                          )}
                        >
                          <div className="overflow-hidden rounded-[14px] bg-white/10">
                            <div className="aspect-square">
                              {product.image_path ? (
                                <img
                                  src={getImageUrl(product.image_path)}
                                  alt={product.name}
                                  className={cn('h-full w-full object-cover', !available && 'grayscale')}
                                />
                              ) : (
                                <div className="flex h-full items-center justify-center text-white/60">
                                  <Store className="h-5 w-5" />
                                </div>
                              )}
                            </div>
                          </div>
                          <p className="mt-1.5 line-clamp-2 text-[10px] font-semibold leading-4 text-white">{product.name}</p>
                          <p className="mt-1 text-[10px] text-white/62">{formatCurrency(product.price)}</p>
                          <span className={cn('mt-1 inline-flex items-center rounded-full border px-1.5 py-0.5 text-[9px] font-semibold', getStockBadgeClass(product))}>
                            {getStockLabel(product)}
                          </span>
                        </button>
                      );
                    })}
                  </div>
                </div>
              </div>
            ) : (
              <SoftEmptyCard
                className="mt-4 border-white/10 bg-white/5 text-white"
                title="Menu tenant belum tampil"
                description="Data menu tenant belum tersedia di integrasi ini. Saat sudah aktif, daftar menu akan langsung muncul di sini."
              />
            )}
          </div>

          <div className="mt-3 rounded-[28px] border border-white/80 bg-white px-3 py-4 shadow-sm">
            <div className="flex items-center justify-between gap-3 px-2">
              <div>
                <p className="text-base font-semibold text-[#1A1A1A]">Products</p>
                <div className="mt-1 inline-flex items-center gap-2 rounded-full border border-[#E6A800] bg-white px-3 py-1 text-xs text-[#888888]">
                  <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: accent }} />
                  Semua
                </div>
              </div>
              <button
                type="button"
                onClick={() => scrollCategoryRail('right')}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E6A800] bg-white text-[#888888] shadow-sm"
              >
                <Search className="h-4 w-4" />
              </button>
            </div>

            <div className="mt-4 flex items-center gap-2">
              <button
                type="button"
                onClick={() => scrollCategoryRail('left')}
                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[#E6A800] bg-white text-[#888888] shadow-sm"
              >
                <ChevronLeft className="h-4 w-4" />
              </button>
              <div id="customer-menu-category-rail" className="flex min-w-0 flex-1 gap-2 overflow-x-auto pb-1">
                <button
                  type="button"
                  onClick={() => handleCategorySelect(null)}
                  className={cn(
                    'whitespace-nowrap rounded-full border px-4 py-2 text-sm font-semibold transition',
                    selectedCategory === null ? 'border-[#1A1A1A] bg-[#1A1A1A] text-[#F5C518]' : 'border-[#E6A800] bg-white text-[#1A1A1A]'
                  )}
                >
                  Semua
                </button>
                {menu.length > 0 ? (
                  menu.map((category) => (
                    <button
                      key={category.id}
                      type="button"
                      onClick={() => handleCategorySelect(category.id)}
                      className={cn(
                        'whitespace-nowrap rounded-full border px-4 py-2 text-sm font-semibold transition',
                        selectedCategory === category.id ? 'border-[#1A1A1A] bg-[#1A1A1A] text-[#F5C518]' : 'border-[#E6A800] bg-white text-[#1A1A1A]'
                      )}
                    >
                      {category.name}
                    </button>
                  ))
                ) : (
                  <div className="rounded-full border border-[#E6A800] bg-white px-4 py-2 text-sm text-[#888888]">Belum ada kategori aktif</div>
                )}
              </div>
              <button
                type="button"
                onClick={() => scrollCategoryRail('right')}
                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[#E6A800] bg-white text-[#888888] shadow-sm"
              >
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>

            <div className="mt-5">
              <SectionHeading title="Product List" description="Visual storefront with modern cart experience." />
            </div>

            <div className="mt-5">
            {loading ? (
              <SoftEmptyCard title="Memuat menu" description="Tunggu sebentar, menu tenant sedang kami siapkan." />
            ) : filteredProducts.length > 0 ? (
              <div className="grid grid-cols-2 gap-4">
                {filteredProducts.map((product) => (
                  <ProductCard
                    key={product.id}
                    product={product}
                    onAdd={() => cart.addItem(product)}
                    accent={accent}
                    isFeatured={product.id === featuredProduct?.id}
                  />
                ))}
              </div>
            ) : (
              <SoftEmptyCard
                title="Menu tenant belum tampil"
                description="Data menu tenant belum tersedia di integrasi ini. Saat sudah aktif, daftar menu akan langsung muncul di sini."
              />
            )}
          </div>
          </div>
        </section>

        <section id="member" className="mt-8 rounded-[28px] border border-white/75 bg-[#1A1A1A] p-5 text-white shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.22em] text-white/50">Akses Member</p>
          <h2 className="mt-3 text-2xl font-bold">Benefit member tenant</h2>
          <p className="mt-2 text-sm leading-6 text-white/70">
            Gunakan akun member untuk promo, loyalty, dan riwayat pembelian yang terhubung ke tenant ini.
          </p>
          <div className="mt-5 flex flex-wrap gap-3">
            <button
              type="button"
              onClick={() => openExternal(experience.routes.member_login || menuRoute)}
              className="rounded-full bg-white px-5 py-3 text-sm font-semibold text-[#1A1A1A]"
            >
              Login Member
            </button>
            <button
              type="button"
              onClick={() => openExternal(experience.routes.member_register || menuRoute)}
              className="rounded-full border border-white/20 bg-white/10 px-5 py-3 text-sm font-semibold text-white"
            >
              Daftar Member
            </button>
          </div>
        </section>

        {activePromo ? (
          <section className="mt-8 rounded-[28px] border border-[#E6A800] bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[#F5C518]">Claim Promo</p>
                <h2 className="mt-2 text-2xl font-bold text-[#1A1A1A]">{activePromo.title}</h2>
                <p className="mt-2 text-sm leading-6 text-[#888888]">{activePromo.description || 'Lengkapi data pelanggan untuk mengambil promo ini.'}</p>
              </div>
              <button
                type="button"
                onClick={() => {
                  setActivePromoId(null);
                  setPromoClaimError(null);
                  setPromoClaimMessage(null);
                }}
                className="rounded-full border border-[#E6A800] px-3 py-1 text-sm text-[#888888]"
              >
                Tutup
              </button>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-2">
              <div className="rounded-[24px] bg-[#F9F9F9] p-4 text-sm text-[#1A1A1A]">
                <p className="font-semibold">{activePromo.promo_code ? `Kode ${activePromo.promo_code}` : 'Promo langsung aktif saat diambil'}</p>
                <p className="mt-2">Bonus loyalty: {activePromo.bonus_points > 0 ? `${activePromo.bonus_points} poin` : 'tidak ada bonus poin'}</p>
                <p className="mt-2">Minimal belanja: {activePromo.minimum_spend > 0 ? formatCurrency(activePromo.minimum_spend) : 'tanpa syarat'}</p>
              </div>
              <div className="rounded-[24px] bg-[#F9F9F9] p-4 text-sm text-[#888888]">
                <p className="font-semibold text-[#1A1A1A]">Catatan bisnis</p>
                <p className="mt-2">Setelah claim, data pelanggan masuk ke admin tenant dan otomatis dihubungkan ke member loyalty berdasarkan nomor HP atau email.</p>
              </div>
            </div>

            <div className="mt-5 rounded-[24px] border border-[#E6A800] p-4">
              <label className="block text-sm font-medium text-[#1A1A1A]">
                Nama pelanggan
                <input
                  value={customerIdentity.name}
                  onChange={(event) => setCustomerIdentity((current) => ({ ...current, name: event.target.value }))}
                  className={modalFieldClass}
                  placeholder="Nama lengkap"
                />
              </label>
              <div className="mt-4 grid gap-4 md:grid-cols-2">
                <label className="block text-sm font-medium text-[#1A1A1A]">
                  Nomor HP
                  <input
                    value={customerIdentity.phone}
                    onChange={(event) => setCustomerIdentity((current) => ({ ...current, phone: event.target.value }))}
                    className={modalFieldClass}
                    placeholder="08xxxxxxxxxx"
                  />
                </label>
                <label className="block text-sm font-medium text-[#1A1A1A]">
                  Email
                  <input
                    value={customerIdentity.email}
                    onChange={(event) => setCustomerIdentity((current) => ({ ...current, email: event.target.value }))}
                    className={modalFieldClass}
                    placeholder="email@contoh.com"
                  />
                </label>
              </div>
              <label className="mt-4 block text-sm font-medium text-[#1A1A1A]">
                Catatan
                <textarea
                  value={promoClaimNotes}
                  onChange={(event) => setPromoClaimNotes(event.target.value)}
                  rows={3}
                  className={modalFieldClass}
                  placeholder="Contoh: dipakai untuk gathering Jumat malam"
                />
              </label>
            </div>

            {promoClaimError ? <div className="mt-4 rounded-[20px] border border-[#E6A800] bg-[#F9F9F9] px-4 py-3 text-sm text-[#1A1A1A]">{promoClaimError}</div> : null}
            {promoClaimMessage ? <div className="mt-4 rounded-[20px] border border-[#E6A800] bg-[#F9F9F9] px-4 py-3 text-sm text-[#1A1A1A]">{promoClaimMessage}</div> : null}

            <div className="mt-5 flex flex-wrap gap-3">
              <button
                type="button"
                onClick={() => void handleClaimPromo()}
                disabled={promoClaimLoading}
                className="rounded-full px-6 py-3 text-sm font-semibold disabled:opacity-60"
                style={{ backgroundColor: '#1A1A1A', color: '#F5C518' }}
              >
                {promoClaimLoading ? 'Mengambil promo...' : 'Ambil Promo Sekarang'}
              </button>
              <button type="button" onClick={() => goToSection('reservasi')} className="rounded-full border border-[#E6A800] px-6 py-3 text-sm font-semibold text-[#1A1A1A]">
                Lanjut ke reservasi
              </button>
            </div>
          </section>
        ) : null}

        {activeReservation ? (
  <div className="fixed inset-0 z-[60] bg-black/55 backdrop-blur-sm" onClick={(e) => {
    if (e.target === e.currentTarget) {
      setActiveReservationId(null);
      setShowReservationModal(false);
    }
  }}>
    <div className="absolute inset-x-0 bottom-0 mx-auto max-h-[92vh] max-w-[430px] rounded-t-[32px] bg-white overflow-hidden flex flex-col">
      {/* Header fixed */}
      <div className="flex items-start justify-between gap-3 border-b border-[#E6A800] px-5 py-4 shrink-0">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[#F5C518]">Form Reservasi</p>
          <h2 className="mt-1 text-xl font-bold text-[#1A1A1A]">{activeReservation.name}</h2>
          <p className="mt-1 text-sm leading-5 text-[#888888]">{activeReservation.description || 'Lengkapi detail kunjungan, pilih paket, lalu tambahkan menu custom bila diperlukan.'}</p>
        </div>
        <button
          type="button"
          onClick={() => {
            setActiveReservationId(null);
            setShowReservationModal(false);
            setReservationError(null);
            setReservationMessage(null);
          }}
          className="rounded-full border border-[#E6A800] px-3 py-1 text-sm text-[#888888] shrink-0"
        >
          Tutup
        </button>
      </div>

      {/* Scrollable body */}
      <div className="overflow-y-auto flex-1 px-5 py-5 space-y-5">
        <div className="grid gap-3 md:grid-cols-3">
          <InfoPill label="Kapasitas" value={`${activeReservation.capacity || 0} tamu`} />
          <InfoPill label="Sewa tempat" value={activeReservation.rent_enabled ? formatCurrency(activeReservation.rent_price) : 'Tanpa sewa'} />
          <InfoPill label="Potensi poin" value={`${activeReservation.estimated_points} poin`} />
        </div>

        <div className="rounded-[24px] border border-[#E6A800] p-4">
          <div className="grid gap-4 md:grid-cols-2">
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Nama pelanggan
              <input value={customerIdentity.name} onChange={(event) => setCustomerIdentity((current) => ({ ...current, name: event.target.value }))} className={modalFieldClass} />
            </label>
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Nomor HP
              <input value={customerIdentity.phone} onChange={(event) => setCustomerIdentity((current) => ({ ...current, phone: event.target.value }))} className={modalFieldClass} />
            </label>
          </div>
          <div className="mt-4 grid gap-4 md:grid-cols-2">
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Email
              <input value={customerIdentity.email} onChange={(event) => setCustomerIdentity((current) => ({ ...current, email: event.target.value }))} className={modalFieldClass} />
            </label>
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Jumlah peserta
              <input type="number" min="1" max={activeReservation.capacity || 1000} value={reservationGuests} onChange={(event) => setReservationGuests(event.target.value)} className={modalFieldClass} />
            </label>
          </div>
          <div className="mt-4 grid gap-4 md:grid-cols-3">
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Tanggal
              <input type="date" value={reservationDate} onChange={(event) => setReservationDate(event.target.value)} className={modalFieldClass} />
            </label>
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Jam mulai
              <input type="time" value={reservationTime} onChange={(event) => setReservationTime(event.target.value)} className={modalFieldClass} />
            </label>
            <label className="block text-sm font-medium text-[#1A1A1A]">
              Durasi (menit)
              <input type="number" min="30" step="30" value={reservationDuration} onChange={(event) => setReservationDuration(event.target.value)} className={modalFieldClass} />
            </label>
          </div>
          <label className="mt-4 block text-sm font-medium text-[#1A1A1A]">
            Catatan acara / kebutuhan khusus
            <textarea value={reservationNotes} onChange={(event) => setReservationNotes(event.target.value)} rows={3} className={modalFieldClass} placeholder="Contoh: butuh projector, kursi anak, atau dekor sederhana" />
          </label>
        </div>

        <div className="rounded-[24px] border border-[#E6A800] p-4">
          <h3 className="text-base font-bold text-[#1A1A1A]">Pilih paket add-on reservasi</h3>
          <div className="mt-4 space-y-3">
            {activeReservation.items.length > 0 ? (
              activeReservation.items.map((item) => {
                const qty = reservationPackageQty[item.id] ?? (item.is_required ? item.qty : 0);
                return (
                  <div key={item.id} className="flex items-center justify-between gap-3 rounded-[22px] bg-[#F9F9F9] px-4 py-3">
                    <div>
                      <p className="font-semibold text-[#1A1A1A]">{item.product_name}</p>
                      <p className="mt-1 text-xs text-[#888888]">{formatCurrency(item.unit_price)} per item {item.is_required ? 'Ã¢â‚¬Â¢ paket wajib' : 'Ã¢â‚¬Â¢ opsional'}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      {!item.is_required ? (
                        <button type="button" onClick={() => setReservationPackageQty((current) => ({ ...current, [item.id]: Math.max(0, (current[item.id] || 0) - 1) }))} className="rounded-full border border-[#E6A800] p-2">
                          <Minus className="h-4 w-4" />
                        </button>
                      ) : null}
                      <span className="min-w-8 text-center text-sm font-semibold text-[#1A1A1A]">{qty}</span>
                      <button type="button" onClick={() => setReservationPackageQty((current) => ({ ...current, [item.id]: Math.max(item.is_required ? item.qty : 1, (current[item.id] || (item.is_required ? item.qty : 0)) + 1) }))} className="rounded-full border border-[#E6A800] p-2">
                        <Plus className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                );
              })
            ) : (
              <SoftEmptyCard title="Tanpa paket bawaan" description="Area ini tidak memiliki add-on paket. Anda masih bisa tambahkan menu custom di bawah." />
            )}
          </div>
        </div>

        <div className="rounded-[24px] border border-[#E6A800] p-4">
          <h3 className="text-base font-bold text-[#1A1A1A]">Tambah menu custom</h3>
          <p className="mt-1 text-sm text-[#888888]">Cocok untuk request menu di luar paket, misalnya welcome drink, platter, atau makanan utama.</p>
          <div className="mt-4 space-y-3">
            {products.slice(0, 8).map((product) => (
              <div key={product.id} className="flex items-center justify-between gap-3 rounded-[22px] bg-[#F9F9F9] px-4 py-3">
                <div>
                  <p className="font-semibold text-[#1A1A1A]">{product.name}</p>
                  <p className="mt-1 text-xs text-[#888888]">{formatCurrency(product.price)}</p>
                </div>
                <div className="flex items-center gap-3">
                  <button type="button" onClick={() => setReservationMenuQty((current) => ({ ...current, [product.id]: Math.max(0, (current[product.id] || 0) - 1) }))} className="rounded-full border border-[#E6A800] p-2">
                    <Minus className="h-4 w-4" />
                  </button>
                  <span className="min-w-8 text-center text-sm font-semibold text-[#1A1A1A]">{reservationMenuQty[product.id] || 0}</span>
                  <button type="button" onClick={() => setReservationMenuQty((current) => ({ ...current, [product.id]: (current[product.id] || 0) + 1 }))} className="rounded-full border border-[#E6A800] p-2">
                    <Plus className="h-4 w-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-[12px] bg-[#1A1A1A] p-4 text-white">
          <p className="mb-3 text-xs text-[#888888]">Estimasi pembayaran reservasi</p>
          <div className="space-y-0 text-base">
            <div className="flex items-center justify-between border-b border-white/20 py-1 text-[#FFFFFF]">
              <span>Sewa tempat</span>
              <span>{activeReservation.rent_enabled ? formatCurrency(activeReservation.rent_price) : 'Rp 0'}</span>
            </div>
            <div className="flex items-center justify-between border-b border-white/20 py-1 text-[#FFFFFF]">
              <span>Subtotal paket</span>
              <span>{formatCurrency(reservationPackageTotal)}</span>
            </div>
            <div className="flex items-center justify-between border-b border-white/20 py-1 text-[#FFFFFF]">
              <span>Custom menu</span>
              <span>{formatCurrency(reservationMenuTotal)}</span>
            </div>
          </div>
          <div className="mt-2 flex items-center justify-between border-t border-white/20 pt-2">
            <span className="font-semibold text-[#FFFFFF]">Grand total</span>
            <span className="text-xl font-bold text-[#F5C518]">{formatCurrency(reservationGrandTotal)}</span>
          </div>
        </div>

        {reservationError ? <div className="rounded-[20px] border border-[#E6A800] bg-[#F9F9F9] px-4 py-3 text-sm text-[#1A1A1A]">{reservationError}</div> : null}
        {reservationMessage ? <div className="rounded-[20px] border border-[#E6A800] bg-[#F9F9F9] px-4 py-3 text-sm text-[#1A1A1A]">{reservationMessage}</div> : null}
      </div>

      {/* Footer fixed */}
      <div className="border-t border-[#E6A800] px-5 py-4 shrink-0 flex gap-2">
        <button
          type="button"
          onClick={() => void handleSubmitReservation()}
          disabled={reservationSubmitting}
          className="flex-1 rounded-full px-3 py-3 text-base font-bold disabled:opacity-60"
          style={{ backgroundColor: '#F5C518', color: '#1A1A1A' }}
        >
          {reservationSubmitting ? 'Mengirim reservasi...' : 'Kirim Reservasi'}
        </button>
        <button
          type="button"
          onClick={() => {
            setActiveReservationId(null);
            setShowReservationModal(false);
          }}
          className="flex-1 rounded-full border border-[#1A1A1A] px-3 py-3 text-base font-semibold"
          style={{ backgroundColor: 'transparent', color: '#1A1A1A' }}
        >
          Tutup
        </button>
      </div>
    </div>
  </div>
) : null}

        <footer className="mt-8 rounded-[28px] bg-[#1A1A1A] px-6 py-5 text-white shadow-sm">
          <div className="flex items-start justify-between gap-5">
            <div>
              <h2 className="text-3xl font-extrabold">{businessName}</h2>
              <div className="mt-4 space-y-3 text-sm text-white/75">
                <FooterLine icon={<MapPin className="h-4 w-4" />} text={tenantBrand.address || 'Alamat tenant belum diisi'} />
                <FooterLine icon={<Phone className="h-4 w-4" />} text={tenantBrand.phone || tenantBrand.whatsapp || 'Kontak tenant belum diisi'} />
              </div>
            </div>
            <div className="text-right text-sm text-white/65">
              <p>Hubungi Kami:</p>
              <div className="mt-4 space-y-2">
                <p>{tenantBrand.website || '-'}</p>
                <p>{tenantBrand.instagram || '-'}</p>
              </div>
            </div>
          </div>
        </footer>
      </div>

      {cart.totalItems > 0 && !showCart && orderStep !== 'payment' && (
        <button
          type="button"
          onClick={() => setShowCart(true)}
          className="fixed left-0 right-0 z-40 mx-auto flex max-w-[398px] items-center justify-between rounded-[26px] px-5 py-4 text-left shadow-[0_20px_50px_rgba(15,23,42,0.28)]"
          style={{
            background: 'linear-gradient(180deg, rgba(245, 197, 24, 0.93) 0%, rgba(255, 199, 46, 0.62) 100%)',
            color: '#1A1A1A',
            bottom: 'calc(7.5rem + env(safe-area-inset-bottom))',
            border: '1px solid rgba(255,255,255,0.45)',
            backdropFilter: 'blur(10px)',
          }}
        >
          <div>
            <p className="text-sm font-semibold">{cart.totalItems} item di cart</p>
            <p className="text-xs" style={{ color: '#1A1A1A' }}>{formatCurrency(cart.totalPrice)}</p>
          </div>
          <ShoppingBag className="h-5 w-5" style={{ color: '#1A1A1A' }} />
        </button>
      )}

      {orderStep !== 'payment' && (
        <BottomNav items={groupedSections} activeId={activeSection} onSelect={handleSectionSelect} />
      )}

      {showCart && (
        <div className="fixed inset-0 z-[60] bg-black/55 backdrop-blur-sm">
          <div className="absolute inset-x-0 bottom-0 mx-auto max-h-[88vh] max-w-[430px] rounded-t-[32px] bg-white">
            <div className="flex items-center justify-between border-b border-[#E6A800] px-5 py-4">
              <div>
                <h2 className="text-lg font-semibold text-[#1A1A1A]">Checkout pesanan</h2>
                <p className="text-sm text-[#888888]">Periksa item sebelum dikirim ke dapur.</p>
              </div>
              <button type="button" onClick={() => setShowCart(false)} className="rounded-full p-2 text-[#888888] hover:bg-[#F9F9F9]">
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="max-h-[calc(88vh-140px)] space-y-5 overflow-y-auto px-5 py-5">
              {cart.items.length === 0 ? (
                <SoftEmptyCard title="Cart masih kosong" description="Tambahkan menu lebih dulu sebelum checkout." />
              ) : (
                <>
                  <div className="space-y-3">
                    {cart.items.map((item) => (
                      <div key={item.product.id} className="rounded-[24px] border border-[#E6A800] p-4">
                        <div className="flex items-start justify-between gap-4">
                          <div>
                            <p className="font-semibold text-[#1A1A1A]">{item.product.name}</p>
                            <p className="mt-1 text-sm text-[#888888]">{formatCurrency(item.product.price)}</p>
                          </div>
                          <button
                            type="button"
                            onClick={() => cart.removeItem(item.product.id)}
                            className="text-sm font-medium text-[#1A1A1A]"
                          >
                            Hapus
                          </button>
                        </div>
                        <div className="mt-4 flex items-center justify-between">
                          <div className="inline-flex items-center gap-3 rounded-full border border-[#E6A800] bg-[#F9F9F9] px-3 py-2">
                            <button type="button" onClick={() => cart.updateQuantity(item.product.id, item.quantity - 1)}>
                              <Minus className="h-4 w-4" />
                            </button>
                            <span className="min-w-6 text-center text-sm font-semibold">{item.quantity}</span>
                            <button type="button" onClick={() => cart.updateQuantity(item.product.id, item.quantity + 1)}>
                              <Plus className="h-4 w-4" />
                            </button>
                          </div>
                          <p className="font-semibold text-[#1A1A1A]">{formatCurrency(item.product.price * item.quantity)}</p>
                        </div>
                      </div>
                    ))}
                  </div>

                  <div className="rounded-[24px] border border-[#E6A800] bg-[#F9F9F9] p-4">
                    <label className="block text-sm font-medium text-[#1A1A1A]">
                      Nama pelanggan
                      <input
                        value={customerName}
                        onChange={(event) => setCustomerName(event.target.value)}
                        placeholder="Opsional"
                        className="mt-2 w-full rounded-2xl border border-[#E6A800] bg-white px-4 py-3 text-sm outline-none placeholder:text-[#888888] focus:border-[#1A1A1A]"
                      />
                    </label>

                    <label className="mt-4 block text-sm font-medium text-[#1A1A1A]">
                      Catatan pesanan
                      <textarea
                        value={notes}
                        onChange={(event) => setNotes(event.target.value)}
                        placeholder="Contoh: tanpa es, sambal terpisah"
                        rows={3}
                        className="mt-2 w-full rounded-2xl border border-[#E6A800] bg-white px-4 py-3 text-sm outline-none placeholder:text-[#888888] focus:border-[#1A1A1A]"
                      />
                    </label>
                  </div>
                </>
              )}
            </div>

            {cart.items.length > 0 && (
              <div className="border-t border-[#E6A800] px-5 pt-4" style={{ paddingBottom: 'calc(6rem + env(safe-area-inset-bottom))' }}>
                <div className="mb-4 flex items-center justify-between text-sm">
                  <span className="text-[#888888]">Total pembayaran</span>
                  <span className="text-xl font-semibold text-[#1A1A1A]">{formatCurrency(cart.totalPrice)}</span>
                </div>
                <button
                  type="button"
                  onClick={() => void handleCheckout()}
                  disabled={placingOrder}
                  className="w-full rounded-2xl px-4 py-3 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-60"
                  style={{ backgroundColor: '#1A1A1A', color: '#F5C518' }}
                >
                  Lanjut ke pembayaran
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Payment Step */}
      {orderStep === 'payment' && (
        <PaymentSelector
          methods={paymentMethods}
          selected={selectedPaymentMethod}
          onSelect={(methodKey) => setSelectedPaymentMethod(methodKey)}
          totalAmount={cart.totalPrice}
          onBack={() => {
            setOrderStep('menu');
            setShowCart(true);
          }}
          onNext={handleSubmitOrder}
        />
      )}
    </div>
  );
}

function statusLabel(status: string) {
  const normalized = status.toLowerCase();
  if (normalized === 'new') return 'New order';
  if (normalized === 'preparing') return 'Sedang disiapkan';
  if (normalized === 'prepared') return 'Siap diantar';
  if (normalized === 'completed') return 'Selesai';
  if (normalized === 'cancelled') return 'Dibatalkan';
  return status;
}

function SectionHeading({
  title,
  description,
  actionLabel,
  onAction,
}: {
  title: string;
  description: string;
  actionLabel?: string;
  onAction?: () => void;
}) {
  return (
    <div className="flex items-end justify-between gap-4">
      <div>
        <h2 className="text-2xl font-extrabold text-[#1A1A1A]">{title}</h2>
        <p className="mt-1 text-sm text-[#888888]">{description}</p>
      </div>
      {actionLabel && onAction ? (
        <button type="button" onClick={onAction} className="text-sm font-semibold text-[#F5C518]">
          {actionLabel} <ArrowRight className="inline h-4 w-4" />
        </button>
      ) : null}
    </div>
  );
}

function QuickAccessCard({ icon, label, onClick }: { icon: ReactNode; label: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="flex flex-col items-center gap-2 rounded-[22px] border border-white/80 bg-white px-3 py-4 shadow-sm">
      <div className="flex h-11 w-11 items-center justify-center rounded-2xl text-white" style={{ background: 'linear-gradient(135deg,#F5C518,#E6A800)' }}>
        {icon}
      </div>
      <span className="text-xs font-semibold text-[#1A1A1A]">{label}</span>
    </button>
  );
}


function PromoHeroCard({
  promo,
  accent,
}: {
  promo: PosCustomerExperiencePayload['promos'][number];
  accent: string;
}) {
  const imageUrl = absoluteAssetUrl(promo.thumbnail_url);

  return (
    <article className="relative overflow-hidden rounded-[28px] bg-[linear-gradient(135deg,#F5C518,#E6A800)] p-6 text-[#1A1A1A] shadow-sm">
      <div className="absolute inset-y-0 right-0 w-28 overflow-hidden rounded-l-[28px] bg-white/10">
        {imageUrl ? <img src={imageUrl} alt={promo.title} className="h-full w-full object-cover" /> : null}
      </div>
      <div className="absolute -left-10 top-0 h-36 w-36 rounded-full bg-white/10 blur-2xl" />
      <div className="relative pr-24">
        <div className="inline-flex rounded-full bg-[#1A1A1A] px-3 py-1 text-xs font-extrabold text-[#F5C518]">PROMO SPESIAL</div>
        <h3 className="mt-4 text-[2rem] font-extrabold leading-none">{promo.title}</h3>
        <p className="mt-3 text-sm leading-6 text-[#1A1A1A]">{promo.description || 'Penawaran spesial tenant untuk pengunjung hari ini.'}</p>
        {promo.link_url ? (
          <button
            type="button"
            onClick={() => openExternal(promo.link_url)}
            className="mt-5 inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold"
            style={{ color: '#1A1A1A' }}
          >
            Lihat detail
          </button>
        ) : null}
      </div>
    </article>
  );
}

function CompactPromoCard({ promo }: { promo: PosCustomerExperiencePayload['promos'][number] }) {
  const validUntil = formatDate(promo.valid_until);

  return (
    <article className="flex items-center gap-4 rounded-[26px] border border-white/80 bg-white px-5 py-5 shadow-sm">
      <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-[#F5C518] text-[#1A1A1A]">
        <Ticket className="h-5 w-5" />
      </div>
      <div className="flex-1">
        <p className="text-[1.05rem] font-bold text-[#1A1A1A]">{promo.title}</p>
        <p className="mt-1 text-sm text-[#888888]">{promo.description || 'Promo tenant aktif untuk pengunjung.'}</p>
        {validUntil ? <p className="mt-2 text-xs font-medium text-[#888888]">Berlaku sampai {validUntil}</p> : null}
      </div>
      {promo.link_url ? (
        <button type="button" onClick={() => openExternal(promo.link_url)} className="text-[#888888]">
          <ChevronRight className="h-5 w-5" />
        </button>
      ) : null}
    </article>
  );
}

function ReservationCard({
  reservation,
  onBook,
}: {
  reservation: PosCustomerExperiencePayload['reservations'][number];
  onBook: () => void;
}) {
  const imageUrl = absoluteAssetUrl(reservation.cover_image_url);

  return (
    <article className="overflow-hidden rounded-[24px] border border-[#E6A800] bg-white">
      <div className="grid gap-0 sm:grid-cols-[132px_1fr]">
        <div className="h-28 bg-[#F9F9F9] sm:h-full">
          {imageUrl ? (
            <img src={imageUrl} alt={reservation.name} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full items-center justify-center bg-[#F9F9F9] text-[#888888]">
              <Sparkles className="h-6 w-6" />
            </div>
          )}
        </div>
        <div className="p-4">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="font-bold text-[#1A1A1A]">{reservation.name}</p>
              <p className="mt-1 text-sm text-[#888888]">{reservation.location || 'Lokasi tenant'}</p>
            </div>
            <span className="rounded-full bg-[#F9F9F9] px-3 py-1 text-xs font-semibold text-[#1A1A1A]">
              Kapasitas {reservation.capacity || '-'}
            </span>
          </div>
          <p className="mt-3 text-sm leading-6 text-[#888888]">
            {reservation.description || 'Area reservasi tersedia untuk kebutuhan event atau kunjungan khusus.'}
          </p>
          <div className="mt-3 flex flex-wrap gap-2 text-xs">
            {reservation.rent_price > 0 ? (
              <span className="rounded-full bg-[#F9F9F9] px-3 py-2 font-semibold text-[#1A1A1A]">
                Sewa {formatCurrency(reservation.rent_price)}
              </span>
            ) : null}
            {reservation.min_menu_total > 0 ? (
              <span className="rounded-full bg-[#F9F9F9] px-3 py-2 font-semibold text-[#1A1A1A]">
                Min. menu {formatCurrency(reservation.min_menu_total)}
              </span>
            ) : null}
            {reservation.estimated_points > 0 ? (
              <span className="rounded-full bg-[#F9F9F9] px-3 py-2 font-semibold text-[#F5C518]">
                Potensi {reservation.estimated_points} poin
              </span>
            ) : null}
          </div>
          {reservation.items.length > 0 ? (
            <div className="mt-3 flex flex-wrap gap-2 text-xs text-[#888888]">
              {reservation.items.slice(0, 3).map((item) => (
                <span key={item.id} className="rounded-full bg-[#F9F9F9] px-3 py-2">
                  {item.product_name} {item.is_required ? 'Ã¢â‚¬Â¢ wajib' : 'Ã¢â‚¬Â¢ opsional'}
                </span>
              ))}
            </div>
          ) : null}
          <button
            type="button"
            onClick={onBook}
            className="mt-4 inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold"
            style={{ backgroundColor: '#1A1A1A', color: '#F5C518' }}
          >
            Isi Form Reservasi
            <ArrowRight className="h-4 w-4" />
          </button>
        </div>
      </div>
    </article>
  );
}

function InfoPill({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-[22px] bg-[#F9F9F9] px-4 py-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-[#888888]">{label}</p>
      <p className="mt-1 text-sm font-semibold text-[#1A1A1A]">{value}</p>
    </div>
  );
}

function ProductCard({
  product,
  onAdd,
  accent,
  isFeatured = false,
}: {
  product: PosMenuProduct;
  onAdd: () => void;
  accent: string;
  isFeatured?: boolean;
}) {
  const available = product.is_available_now ?? (product.is_available && (!product.track_stock || (product.stock ?? 0) > 0));
  const stockLabel = !available
    ? 'Habis'
    : product.track_stock
      ? `Sisa ${product.stock ?? 0}`
      : 'Unlimited';
  const stockBadgeClass = !available
    ? 'bg-red-100 text-red-700 border-red-200'
    : product.track_stock
      ? 'bg-emerald-100 text-emerald-700 border-emerald-200'
      : 'bg-slate-100 text-slate-600 border-slate-200';

  return (
    <article
      className={cn(
        'overflow-hidden rounded-[18px] border bg-white shadow-[0_10px_24px_rgba(15,23,42,0.06)] transition',
        isFeatured ? 'border-[#E6A800] shadow-[0_16px_34px_rgba(16,185,129,0.12)]' : 'border-[#F9F9F9]',
        !available && 'opacity-80'
      )}
    >
      <div className="aspect-[0.92] bg-[#F9F9F9]">
        {product.image_path ? (
          <img
            src={getImageUrl(product.image_path)}
            alt={product.name}
            className={cn('h-full w-full object-cover', !available && 'grayscale')}
          />
        ) : (
          <div className="flex h-full items-center justify-center text-[#888888]">
            <Store className="h-8 w-8" />
          </div>
        )}
      </div>
      <div className="p-3">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="line-clamp-1 text-[11px] font-semibold text-[#1A1A1A]">{product.name}</p>
            <p className="mt-2 text-base font-extrabold text-[#1A1A1A]">{formatCurrency(product.price)}</p>
          </div>
          <button type="button" className="mt-0.5 text-[#888888]">
            <Plus className="h-3.5 w-3.5" />
          </button>
        </div>
        <p className="mt-1 line-clamp-2 min-h-[2rem] text-[10px] leading-4 text-[#888888]">{product.description || 'Store product ready to order.'}</p>
        <span className={cn('mt-2 inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold', stockBadgeClass)}>
          {stockLabel}
        </span>
        <div className={cn('mt-2 h-1.5 w-10 rounded-full', isFeatured ? '' : 'bg-[#F9F9F9]')} style={isFeatured ? { backgroundColor: accent } : undefined} />
        <button
          type="button"
          onClick={onAdd}
          disabled={!available}
          className={cn(
            'mt-4 inline-flex w-full items-center justify-center rounded-full px-4 py-2.5 text-[11px] font-semibold text-white',
            !available && 'cursor-not-allowed bg-[#E6A800] text-[#1A1A1A]'
          )}
          style={available ? { backgroundColor: accent } : undefined}
        >
          <ShoppingCart className="mr-1.5 h-3.5 w-3.5" />
          Checkout
        </button>
      </div>
    </article>
  );
}

function SoftEmptyCard({
  title,
  description,
  className,
  titleClassName,
  descriptionClassName,
}: {
  title: string;
  description: string;
  className?: string;
  titleClassName?: string;
  descriptionClassName?: string;
}) {
  return (
    <div className={cn('rounded-[24px] border border-dashed border-[#E6A800] bg-[#F9F9F9] p-5', className)}>
      <p className={cn('text-lg font-bold text-[#1A1A1A]', titleClassName)}>{title}</p>
      <p className={cn('mt-2 text-sm leading-6 text-[#888888]', descriptionClassName)}>{description}</p>
    </div>
  );
}

function FooterLine({ icon, text }: { icon: ReactNode; text: string }) {
  return (
    <div className="flex items-start gap-3">
      <span className="mt-0.5 text-[#F5C518]">{icon}</span>
      <span>{text}</span>
    </div>
  );
}

function BottomNav({
  items,
  activeId,
  onSelect,
}: {
  items: Array<{ id: string; label: string }>;
  activeId?: string;
  onSelect: (id: string) => void;
}) {
  const iconMap: Record<string, ReactNode> = {
    home: <Store className="h-4 w-4" />,
    menu: <UtensilsCrossed className="h-4 w-4" />,
    pesanan: <ReceiptText className="h-4 w-4" />,
    promo: <Ticket className="h-4 w-4" />,
  };

  return (
    <div
      className="fixed inset-x-0 bottom-2 z-50 px-4 pb-2 pt-2"
      style={{ paddingBottom: 'max(0.5rem, env(safe-area-inset-bottom))' }}
      data-bottom-nav
    >
      <div className="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-[linear-gradient(180deg,rgba(26,26,26,0)_0%,rgba(26,26,26,0.92)_48%,rgba(26,26,26,1)_100%)]" />
      <div className="relative mx-auto max-w-[430px] rounded-[30px] border border-[#2D2D2D] bg-[#1A1A1A] p-2 shadow-[0_20px_60px_rgba(15,23,42,0.14)] backdrop-blur-xl">
        <div className="grid grid-cols-4 gap-2">
        {items.map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => onSelect(item.id)}
            className={cn(
              'group relative overflow-hidden rounded-[22px] px-2 py-2.5 text-xs font-semibold transition duration-300',
              activeId === item.id ? 'bg-[#1A1A1A] text-[#F5C518] shadow-[0_14px_28px_rgba(18,26,47,0.24)]' : 'text-[#888888] hover:bg-[#2D2D2D]'
            )}
          >
            <span
              className={cn(
                'absolute inset-x-5 top-0 h-[2px] rounded-full transition duration-300',
                activeId === item.id ? 'bg-[#F5C518] opacity-100' : 'bg-transparent opacity-0'
              )}
            />
            <span className="flex flex-col items-center gap-1.5">
              <span
                className={cn(
                  'flex h-8 w-8 items-center justify-center rounded-full transition duration-300',
                  activeId === item.id ? 'bg-white/12 text-[#F5C518]' : 'bg-[#2D2D2D] text-[#888888] group-hover:bg-[#2D2D2D]'
                )}
              >
                {iconMap[item.id] || <Store className="h-4 w-4" />}
              </span>
              <span className={cn('tracking-[-0.01em]', activeId === item.id ? 'text-[#F5C518]' : 'text-[#888888]')}>{item.label}</span>
            </span>
          </button>
        ))}
        </div>
      </div>


    </div>
  );
}
