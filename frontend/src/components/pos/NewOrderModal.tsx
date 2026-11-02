import { useState, useEffect, useRef } from 'react';
import {
  X, Plus, Minus, User, Star, Gift, ScanLine, ArrowLeft, ArrowRight, ChevronDown, ChevronRight,
  Trash2, Search, ShoppingBag, Armchair, LayoutGrid, List, Image as ImageIcon, StickyNote, ArrowUpDown,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { getPosTables, getPosProducts, getPosCategories, searchPosMembers, createPosMember, calculateLoyaltyPoints, applyReward, previewPosOrder, getAvailablePosProductSerials, getPosOutletSettings } from '@/lib/hellomApi';
import { getImageUrl } from '@/lib/hellomApi';
import type { PosProductPriceTierRecord, PosProductVariantRecord } from '@/services/api/pos';
import type { PosOrderDraft, PosOrderTotals } from '@/lib/hellomApi';
import HellomLoading from '@/components/HellomLoading';
import { useBusinessProfile } from '@/hooks/useBusinessProfile';
import { getSessionUser } from '@/services/api/client';
import {
  cacheAvailablePosSerials,
  cachePosMembers,
  cachePosMasterData,
  cachePosOutletSettings,
  getCachedAvailablePosSerials,
  readCachedPosMasterData,
  getCachedPosOutletSettings,
  saveOfflineOrder,
  searchCachedPosMembers,
} from '@/lib/posOfflineDb';
import { syncPosOrders } from '@/lib/posSyncEngine';

type Table = {
  id: number;
  code: string;
  name: string | null;
  is_active: boolean;
};

type Product = {
  id: number;
  name: string;
  description: string | null;
  price: number;
  image_path: string | null;
  is_available: boolean;
  track_stock: boolean;
  stock: number | null;
  sku?: string | null;
  barcode?: string | null;
  has_variants?: boolean;
  track_serial?: boolean;
  variants?: PosProductVariantRecord[];
  price_tiers?: PosProductPriceTierRecord[];
  category: { id: number; name: string };
  options: ProductOption[];
};

type Category = {
  id: number;
  name: string;
  is_active: boolean;
};

type ProductOption = {
  id: number;
  name: string;
  type: 'single' | 'multi';
  is_required: boolean;
  is_active: boolean;
  values: Array<{
    id: number;
    name: string;
    price_delta: number;
    is_active: boolean;
  }>;
};

type SelectedAddon = {
  option_id: number;
  value_id: number;
  option_name: string;
  value_name: string;
  price_delta: number;
};

type CartItem = {
  product: Product;
  quantity: number;
  addons: SelectedAddon[];
  totalPrice: number;
  variantId: number | null;
  customerType: string;
  serialNumbers: string[];
  key: string;
};

type BarcodeDetection = { rawValue: string };
type BarcodeDetectorLike = { detect: (source: HTMLVideoElement) => Promise<BarcodeDetection[]> };
type BarcodeDetectorWindow = Window & {
  BarcodeDetector?: new (options?: { formats: string[] }) => BarcodeDetectorLike;
};

interface NewOrderModalProps {
  isOpen: boolean;
  onClose: () => void;
  onOrderCreated: () => void;
}

export default function NewOrderModal({ isOpen, onClose, onOrderCreated }: NewOrderModalProps) {
  const { terminology, hasModule, loading: businessProfileLoading } = useBusinessProfile();
  const hasTablesSelfOrder = hasModule('tables_selforder');
  const [activeTab, setActiveTab] = useState<'menu' | 'cart'>('menu');
  const [customerOpen, setCustomerOpen] = useState(false);
  const [notesOpen, setNotesOpen] = useState(false);
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [tables, setTables] = useState<Table[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [selectedTableId, setSelectedTableId] = useState<number | null>(null);
   const [customerName, setCustomerName] = useState('');
   const [customerPhone, setCustomerPhone] = useState('');
  const [notes, setNotes] = useState('');
  const [cart, setCart] = useState<CartItem[]>([]);
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null);
  const [showTableSelector, setShowTableSelector] = useState(false);
  const [loading, setLoading] = useState(false);
  const [isOnline, setIsOnline] = useState(() => navigator.onLine);
  const [loadingLabel, setLoadingLabel] = useState('Memuat data...');
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [scannerOpen, setScannerOpen] = useState(false);
  const [scannerError, setScannerError] = useState<string | null>(null);
  const [priceSort, setPriceSort] = useState<'none' | 'asc' | 'desc'>('none');
  const [notification, setNotification] = useState<{ message: string; visible: boolean } | null>(null);
  const [showAddonModal, setShowAddonModal] = useState(false);
  const [selectedProductForAddon, setSelectedProductForAddon] = useState<Product | null>(null);
  const [selectedAddons, setSelectedAddons] = useState<Record<number, number[]>>({});
  const [selectedVariantId, setSelectedVariantId] = useState('');
  const [customerType, setCustomerType] = useState('');
  const [configuredQuantity, setConfiguredQuantity] = useState('1');
  const [availableSerials, setAvailableSerials] = useState<Array<{ id: number; serial_number: string }>>([]);
  const [selectedSerials, setSelectedSerials] = useState<string[]>([]);
  const [serialLoading, setSerialLoading] = useState(false);
  const [serialError, setSerialError] = useState('');

  // Member & Loyalty states
  const [memberQuery, setMemberQuery] = useState('');
  const [memberResults, setMemberResults] = useState<any[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [selectedMember, setSelectedMember] = useState<any | null>(null);
  const [availableRewards, setAvailableRewards] = useState<any[]>([]);
  const [selectedReward, setSelectedReward] = useState<any | null>(null);
  const [discountAmount, setDiscountAmount] = useState(0);
  const [pointsToEarn, setPointsToEarn] = useState(0);
  const [showRegisterMember, setShowRegisterMember] = useState(false);
  const [newMemberName, setNewMemberName] = useState('');
  const [newMemberPhone, setNewMemberPhone] = useState('');
  const [newMemberEmail, setNewMemberEmail] = useState('');
  // Server-side totals (tax, service, rounding, discounts): what the order will really cost.
  const [serverTotals, setServerTotals] = useState<PosOrderTotals | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [redeemPoints, setRedeemPoints] = useState('');
  const [confirmMemberName, setConfirmMemberName] = useState('');
  const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const scannerVideoRef = useRef<HTMLVideoElement>(null);
  const hasLoadedForOpenRef = useRef(false);

  useEffect(() => {
    const updateNetworkState = () => setIsOnline(navigator.onLine);
    window.addEventListener('online', updateNetworkState);
    window.addEventListener('offline', updateNetworkState);
    return () => {
      window.removeEventListener('online', updateNetworkState);
      window.removeEventListener('offline', updateNetworkState);
    };
  }, []);

  useEffect(() => {
    if (isOpen) {
      if (businessProfileLoading || hasLoadedForOpenRef.current) return;
      hasLoadedForOpenRef.current = true;
      loadData();
      resetForm();
    } else {
      hasLoadedForOpenRef.current = false;
      setScannerOpen(false);
      // Cleanup when modal closes
      if (searchTimeoutRef.current) {
        clearTimeout(searchTimeoutRef.current);
      }
      setMemberResults([]);
      setIsSearching(false);
    }
    
    // Cleanup debounce timeout on unmount
    return () => {
      if (searchTimeoutRef.current) {
        clearTimeout(searchTimeoutRef.current);
      }
    };
  }, [isOpen, businessProfileLoading]);

  useEffect(() => {
    if (!scannerOpen || !isOpen) return undefined;
    let stream: MediaStream | null = null;
    let intervalId: number | undefined;
    let cancelled = false;
    let detecting = false;
    const startScanner = async () => {
      const Detector = (window as BarcodeDetectorWindow).BarcodeDetector;
      if (!Detector) {
        setScannerError('Pemindai kamera tidak tersedia di browser ini. Gunakan scanner USB atau ketik barcode.');
        return;
      }
      if (!navigator.mediaDevices?.getUserMedia) {
        setScannerError('Akses kamera tidak tersedia. Gunakan scanner USB atau ketik barcode.');
        return;
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        if (cancelled) {
          stream.getTracks().forEach((track) => track.stop());
          return;
        }
        const video = scannerVideoRef.current;
        if (!video) {
          setScannerError('Kamera belum siap. Coba buka pemindai lagi.');
          return;
        }
        video.srcObject = stream;
        await video.play();
        const detector = new Detector({ formats: ['ean_13', 'ean_8', 'code_128', 'upc_a', 'upc_e', 'qr_code'] });
        intervalId = window.setInterval(async () => {
          if (detecting || video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) return;
          detecting = true;
          try {
            const detected = await detector.detect(video);
            if (detected[0]?.rawValue) {
              setSearchQuery(detected[0].rawValue);
              setScannerOpen(false);
            }
          } catch {
            setScannerError('Barcode gagal dibaca. Coba arahkan kamera lebih dekat atau gunakan scanner USB.');
          } finally {
            detecting = false;
          }
        }, 400);
      } catch (reason) {
        setScannerError(reason instanceof Error ? `Kamera tidak dapat dibuka: ${reason.message}` : 'Kamera tidak dapat dibuka.');
      }
    };
    setScannerError(null);
    void startScanner();
    return () => {
      cancelled = true;
      if (intervalId !== undefined) window.clearInterval(intervalId);
      stream?.getTracks().forEach((track) => track.stop());
    };
  }, [isOpen, scannerOpen]);

  useEffect(() => {
    const product = selectedProductForAddon;
    if (!showAddonModal || !product?.track_serial) {
      setAvailableSerials([]);
      setSerialLoading(false);
      return undefined;
    }

    let active = true;
    setSerialLoading(true);
    setSerialError('');
    const variantId = selectedVariantId ? Number(selectedVariantId) : undefined;
    getAvailablePosProductSerials(product.id, variantId)
      .then((response) => {
        if (active) {
          setAvailableSerials(response.serials);
          void cacheAvailablePosSerials(product.id, response.serials);
        }
      })
      .catch(async (reason: unknown) => {
        const cachedSerials = await getCachedAvailablePosSerials(product.id, variantId);
        if (!active) return;
        if (cachedSerials.length > 0) setAvailableSerials(cachedSerials);
        else setSerialError(reason instanceof Error ? reason.message : 'Serial tersedia gagal dimuat.');
      })
      .finally(() => {
        if (active) setSerialLoading(false);
      });

    return () => { active = false; };
  }, [showAddonModal, selectedProductForAddon, selectedVariantId]);

  const loadData = async () => {
    let hasCachedData = false;
    try {
      setLoading(true);
      setLoadingLabel(
        hasTablesSelfOrder
          ? `Memuat ${terminology.product.toLowerCase()} dan ${terminology.table.toLowerCase()}...`
          : `Memuat ${terminology.product.toLowerCase()}...`
      );
      const cached = await readCachedPosMasterData();
      if (cached) {
        hasCachedData = true;
        setTables(cached.tables);
        setProducts(cached.products);
        setCategories(cached.categories);
      }
      if (!navigator.onLine) {
        if (!hasCachedData) setError('Data kasir belum tersedia di perangkat ini. Hubungkan ke internet untuk memuat data pertama kali.');
        return;
      }

      const [tablesRes, productsRes, categoriesRes] = await Promise.all([
        hasTablesSelfOrder ? getPosTables() : Promise.resolve(null),
        getPosProducts(),
        getPosCategories(),
      ]);

      setTables(tablesRes?.tables || []);
      setProducts(productsRes.products || []);
      setCategories(categoriesRes.categories || []);
      await cachePosMasterData({
        tables: tablesRes?.tables ?? [],
        products: productsRes.products,
        categories: categoriesRes.categories,
      });
      try {
        const settingsResponse = await getPosOutletSettings();
        await cachePosOutletSettings(settingsResponse.settings, settingsResponse.payment_methods);
      } catch (reason) {
        console.warn('[POS offline] Outlet settings refresh failed:', reason);
      }
    } catch (err) {
      if (!hasCachedData) setError(err instanceof Error ? err.message : 'Data kasir gagal dimuat.');
    } finally {
      setLoading(false);
    }
  };

  const resetForm = () => {
    // Clear timeout
    if (searchTimeoutRef.current) {
      clearTimeout(searchTimeoutRef.current);
      searchTimeoutRef.current = null;
    }
    
    setActiveTab('menu');
    setCustomerOpen(false);
    setNotesOpen(false);
    setViewMode('grid');
    setSelectedTableId(null);
     setCustomerName('');
     setCustomerPhone('');
    setNotes('');
    setCart([]);
    setSelectedCategory(null);
    setShowTableSelector(false);
    setError(null);
    setSearchQuery('');
    setPriceSort('none');
    setNotification(null);
    setShowAddonModal(false);
    setSelectedProductForAddon(null);
    setSelectedAddons({});
    setSelectedVariantId('');
    setCustomerType('');
    setConfiguredQuantity('1');
    setSelectedSerials([]);
    setSerialError('');
    setServerTotals(null);
    setPreviewError(null);
    setRedeemPoints('');
    setConfirmMemberName('');
    // Reset member states
    setMemberQuery('');
    setMemberResults([]);
    setIsSearching(false);
    setSelectedMember(null);
    setAvailableRewards([]);
    setSelectedReward(null);
    setDiscountAmount(0);
    setPointsToEarn(0);
    setShowRegisterMember(false);
    setNewMemberName('');
    setNewMemberPhone('');
    setNewMemberEmail('');
  };

  const handleProductClick = (product: Product) => {
    if (!isProductAvailable(product)) {
      setNotification({ message: `${product.name} sedang habis`, visible: true });
      setTimeout(() => setNotification(null), 2000);
      return;
    }
    // Check if product has options
    const hasOptions = product.options?.some((option) => option.is_active) ?? false;
    const needsVariant = hasModule('variants') && (product.has_variants || (product.variants?.length ?? 0) > 0);
    const needsSerial = hasModule('serial_imei') && Boolean(product.track_serial);
    const hasPriceTiers = hasModule('price_tiers') && (product.price_tiers?.length ?? 0) > 0;

    if (hasOptions || needsVariant || needsSerial || hasPriceTiers) {
      setSelectedProductForAddon(product);
      setSelectedAddons({});
      setSelectedVariantId('');
      setCustomerType('');
      setConfiguredQuantity('1');
      setSelectedSerials([]);
      setSerialError('');
      setShowAddonModal(true);
    } else {
      addToCart(product, [], null, [], '', 1);
    }
  };

  const addToCart = (
    product: Product,
    addons: SelectedAddon[],
    variantId: number | null,
    serialNumbers: string[],
    selectedCustomerType: string,
    quantity: number,
  ) => {
    const key = JSON.stringify([product.id, variantId, selectedCustomerType, addons.map((addon) => addon.value_id)]);
    const variant = product.variants?.find((row) => row.id === variantId);
    const addonsPrice = addons.reduce((sum, addon) => sum + addon.price_delta, 0);
    setCart(prev => {
      const existing = prev.find((item) => item.key === key);
      const newQuantity = (existing?.quantity ?? 0) + quantity;
      const newSerialNumbers = [...(existing?.serialNumbers ?? []), ...serialNumbers];
      const tier = product.price_tiers
        ?.filter((row) => row.min_quantity <= newQuantity
          && (row.product_variant_id === null || row.product_variant_id === variantId)
          && (row.customer_type === null || row.customer_type === selectedCustomerType))
        .sort((a, b) => Number(b.product_variant_id !== null) - Number(a.product_variant_id !== null) || b.min_quantity - a.min_quantity)[0];
      const unitPrice = tier?.price ?? variant?.price ?? product.price;
      const totalPrice = (unitPrice + addonsPrice) * newQuantity;

      const newCart = existing
        ? prev.map((item) => item.key === key
          ? { ...item, quantity: newQuantity, totalPrice, serialNumbers: newSerialNumbers }
          : item)
        : [...prev, {
          product, quantity, addons, totalPrice, variantId,
          customerType: selectedCustomerType, serialNumbers, key,
        }];

      setNotification({ message: `${product.name} ditambahkan ke keranjang`, visible: true });
      setTimeout(() => setNotification(null), 2000);

      // Animate cart tab to draw attention
      const cartTab = document.querySelector('[data-cart-tab]');
      if (cartTab) {
        cartTab.classList.add('animate-pulse', 'bg-amber-200');
        setTimeout(() => {
          cartTab.classList.remove('animate-pulse', 'bg-amber-200');
        }, 1000);
      }

      return newCart;
    });
  };

  const updateQuantity = (key: string, quantity: number) => {
    if (quantity <= 0) {
      const item = cart.find((row) => row.key === key);
      if (item) {
        setNotification({ message: `${item.product.name} dihapus dari keranjang`, visible: true });
        setTimeout(() => setNotification(null), 2000);
      }
      setCart((prev) => prev.filter((item) => item.key !== key));
    } else {
      setCart((prev) => prev.map((item) => {
        if (item.key !== key) return item;
        const serialNumbers = item.product.track_serial ? item.serialNumbers.slice(0, quantity) : item.serialNumbers;
        const variant = item.product.variants?.find((row) => row.id === item.variantId);
        const tier = item.product.price_tiers
          ?.filter((row) => row.min_quantity <= quantity
            && (row.product_variant_id === null || row.product_variant_id === item.variantId)
            && (row.customer_type === null || row.customer_type === item.customerType))
          .sort((a, b) => Number(b.product_variant_id !== null) - Number(a.product_variant_id !== null) || b.min_quantity - a.min_quantity)[0];
        const unit = tier?.price ?? variant?.price ?? item.product.price;
        const addonPrice = item.addons.reduce((sum, addon) => sum + addon.price_delta, 0);
        return { ...item, quantity, serialNumbers, totalPrice: (unit + addonPrice) * quantity };
      }));
    }
  };

  const isProductAvailable = (product: Product) => {
    if (!product.is_available) return false;
    if (product.has_variants && product.variants?.length) {
      return product.variants.some((variant) => variant.is_active && (!variant.track_stock || (variant.stock ?? 0) > 0));
    }
    return !product.track_stock || (product.stock ?? 0) > 0;
  };
  const getStockLabel = (product: Product) => {
    if (!isProductAvailable(product)) return 'Habis';
    if (product.has_variants && product.variants?.length) {
      const tracked = product.variants.filter((variant) => variant.is_active && variant.track_stock);
      if (tracked.length) return `Sisa ${tracked.reduce((sum, variant) => sum + (variant.stock ?? 0), 0)}`;
      return 'Unlimited';
    }
    if (product.track_stock) return `Sisa ${product.stock ?? 0}`;
    return 'Unlimited';
  };
  const getStockBadgeClass = (product: Product) => {
    if (!isProductAvailable(product)) return 'bg-red-100 text-red-700 border-red-200';
    if (product.track_stock || product.variants?.some((variant) => variant.is_active && variant.track_stock)) return 'bg-emerald-100 text-emerald-700 border-emerald-200';
    return 'bg-slate-100 text-slate-600 border-slate-200';
  };
  const getCartUnitPrice = (item: CartItem) => {
    const tier = item.product.price_tiers
      ?.filter((row) => row.min_quantity <= item.quantity
        && (row.product_variant_id === null || row.product_variant_id === item.variantId)
        && (row.customer_type === null || row.customer_type === item.customerType))
      .sort((a, b) => Number(b.product_variant_id !== null) - Number(a.product_variant_id !== null) || b.min_quantity - a.min_quantity)[0];
    return tier?.price ?? item.product.variants?.find((row) => row.id === item.variantId)?.price ?? item.product.price;
  };

  const filteredProducts = (() => {
    let filtered = products.filter(product => {
      // Category filter
      const categoryMatch = !selectedCategory || product.category?.id === selectedCategory;

      // Search filter
      const searchMatch = !searchQuery ||
        product.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        product.description?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        product.sku?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        product.barcode?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        product.variants?.some((variant) =>
          variant.sku?.toLowerCase().includes(searchQuery.toLowerCase())
          || variant.barcode?.toLowerCase().includes(searchQuery.toLowerCase())
        );

      return categoryMatch && searchMatch;
    });

    // Price sorting
    if (priceSort === 'asc') {
      filtered = filtered.sort((a, b) => a.price - b.price);
    } else if (priceSort === 'desc') {
      filtered = filtered.sort((a, b) => b.price - a.price);
    }

    return filtered;
  })();

  const selectedSerialsInCart = cart.flatMap((item) => item.serialNumbers);
  const totalPrice = cart.reduce((sum, item) => sum + item.totalPrice, 0);
  const cartKinds = cart.length;
  const cartUnits = cart.reduce((sum, item) => sum + item.quantity, 0);
  const cartSummaryLabel = `${cartKinds} jenis · ${cartUnits} item`;
  const serviceLabel = selectedTableId
    ? `${terminology.table} ${tables.find((table) => table.id === selectedTableId)?.code || selectedTableId}`
    : 'Bawa pulang';
  // Products without options, variants, serials or price tiers go straight into the cart, so they get an inline stepper.
  const isSimpleProduct = (product: Product) =>
    !(product.options?.some((option) => option.is_active) ?? false)
    && !(hasModule('variants') && (product.has_variants || (product.variants?.length ?? 0) > 0))
    && !(hasModule('serial_imei') && Boolean(product.track_serial))
    && !(hasModule('price_tiers') && (product.price_tiers?.length ?? 0) > 0);
  const simpleCartItem = (product: Product) =>
    cart.find((item) => item.key === JSON.stringify([product.id, null, '', []]));
  const customerSummary = selectedMember
    ? `${selectedMember.name || 'Member'} · member`
    : customerName.trim() || customerPhone.trim()
      ? [customerName.trim(), customerPhone.trim()].filter(Boolean).join(' · ')
      : `${terminology.customer} umum · opsional`;
  const configuredQty = Math.max(1, Number.parseInt(configuredQuantity, 10) || 1);
  const configuredVariant = selectedProductForAddon?.variants?.find((variant) => variant.id === Number(selectedVariantId));
  const configuredTier = selectedProductForAddon?.price_tiers
    ?.filter((row) => row.min_quantity <= (selectedProductForAddon.track_serial ? selectedSerials.length : configuredQty)
      && (row.product_variant_id === null || row.product_variant_id === (configuredVariant?.id ?? null))
      && (row.customer_type === null || row.customer_type === customerType))
    .sort((a, b) => Number(b.product_variant_id !== null) - Number(a.product_variant_id !== null) || b.min_quantity - a.min_quantity)[0];
  const configuredUnitPrice = configuredTier?.price ?? configuredVariant?.price ?? selectedProductForAddon?.price ?? 0;
  const configuredRequiresVariant = Boolean(selectedProductForAddon?.has_variants || (selectedProductForAddon?.variants?.length ?? 0) > 0);
  const configuredRequiresSerial = hasModule('serial_imei') && Boolean(selectedProductForAddon?.track_serial);
  const redeemPointsNum = Math.max(0, parseInt(redeemPoints.replace(/\D/g, ''), 10) || 0);
  const pointsNeedConfirm = Boolean(selectedMember) && redeemPointsNum > 0 && !confirmMemberName.trim();
  // The server total is authoritative; the local sum is only shown until it arrives.
  const finalAmount = serverTotals?.final_amount ?? (selectedReward ? Math.max(0, totalPrice - discountAmount) : totalPrice);

  const buildDraft = (): PosOrderDraft => ({
    ...(selectedTableId ? { table_id: selectedTableId } : {}),
    customer_name: selectedMember?.name || customerName || undefined,
    customer_phone: selectedMember?.phone || customerPhone || undefined,
    member_id: selectedMember?.id || undefined,
    reward_rule_id: selectedReward?.id || undefined,
    redeem_points: selectedMember && redeemPointsNum > 0 ? redeemPointsNum : undefined,
    confirm_member_name: selectedMember && redeemPointsNum > 0 ? confirmMemberName : undefined,
    service_type: selectedTableId ? 'dine_in' : 'takeaway',
    notes: notes || undefined,
    items: cart.map(item => ({
      product_id: item.product.id,
      ...(item.variantId ? { product_variant_id: item.variantId } : {}),
      quantity: item.quantity,
      ...(item.customerType ? { customer_type: item.customerType } : {}),
      ...(item.serialNumbers.length ? { serial_numbers: item.serialNumbers } : {}),
      options: item.addons.map(addon => ({ option_id: addon.option_id, value_id: addon.value_id })),
    })),
  });

  useEffect(() => {
    if (!isOpen || cart.length === 0) {
      setServerTotals(null);
      setPreviewError(null);
      return undefined;
    }
    const timer = window.setTimeout(async () => {
      if (!isOnline) {
        setServerTotals(null);
        if (selectedReward || redeemPointsNum > 0) {
          setPreviewError('Diskon reward dan penukaran poin membutuhkan koneksi internet.');
          return;
        }
        try {
          const cachedSettings = await getCachedPosOutletSettings();
          if (!cachedSettings) {
            setPreviewError('Pengaturan harga offline belum tersedia. Hubungkan perangkat ke internet untuk memuatnya.');
            return;
          }
          const subtotal = cart.reduce((sum, item) => sum + item.totalPrice, 0);
          const serviceAmount = Math.round(subtotal * cachedSettings.settings.pricing.service_percent / 100);
          const taxAmount = Math.round((subtotal + serviceAmount) * cachedSettings.settings.pricing.tax_percent / 100);
          const beforeRounding = subtotal + serviceAmount + taxAmount;
          const roundingStep = cachedSettings.settings.pricing.rounding;
          const finalAmount = roundingStep > 0
            ? Math.round(beforeRounding / roundingStep) * roundingStep
            : beforeRounding;
          setServerTotals({
            subtotal,
            discount_amount: 0,
            points_discount_amount: 0,
            service_amount: serviceAmount,
            tax_amount: taxAmount,
            rounding_amount: finalAmount - beforeRounding,
            final_amount: finalAmount,
            service_percent: cachedSettings.settings.pricing.service_percent,
            tax_percent: cachedSettings.settings.pricing.tax_percent,
            rounding_step: roundingStep,
          });
          setPreviewError(null);
        } catch (reason) {
          setPreviewError(reason instanceof Error ? reason.message : 'Pengaturan harga offline gagal dimuat.');
        }
        return;
      }
      try {
        const res = await previewPosOrder(buildDraft());
        setServerTotals(res.totals);
        setPreviewError(null);
      } catch (err) {
        if (err instanceof TypeError) {
          setIsOnline(false);
          return;
        }
        setServerTotals(null);
        setPreviewError(err instanceof Error ? err.message : 'Gagal menghitung total');
      }
    }, 350);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, cart, selectedMember?.id, selectedReward?.id, redeemPointsNum, selectedTableId, isOnline]);

  useEffect(() => {
    if (!selectedMember) {
      return;
    }

    const refreshLoyalty = async () => {
      await calculateLoyalty(selectedMember.id);

      if (selectedReward) {
        setSelectedReward(null);
        setDiscountAmount(0);
      }
    };

    void refreshLoyalty();
  }, [selectedMember?.id, totalPrice]);

  // Helper functions to safely get member fields (simplified - API field names are standard)
  const getMemberName = (m: any) => m.name || 'Unknown';
  const getMemberPhone = (m: any) => m.phone || '';
  const getMemberEmail = (m: any) => m.email || '';
  // Spendable balance (the ledger); total_points is lifetime earned.
  const getMemberPoints = (m: any) => m.redeemable_points ?? m.total_points ?? 0;
  const getMemberOrders = (m: any) => m.total_orders || 0;

  // Member search with debounce
  const searchMember = (query: string) => {
    setMemberQuery(query);
    
    // Clear previous timeout
    if (searchTimeoutRef.current) {
      clearTimeout(searchTimeoutRef.current);
    }

    if (query.length < 2) {
      setMemberResults([]);
      return;
    }

    // Set loading state immediately for better UX
    setIsSearching(true);

    // Debounce API call - wait 400ms before searching
    searchTimeoutRef.current = setTimeout(async () => {
      try {
        const cachedResults = await searchCachedPosMembers(query);
        if (cachedResults.length > 0) setMemberResults(cachedResults);
        if (!navigator.onLine) return;
        const res = await searchPosMembers({ q: query });
        
        // apiRequest already unwraps the envelope: res = { members: [...] }
        const results = Array.isArray(res.members) ? [...res.members] : [];
        await cachePosMembers(results);

        
        // Sort results: exact match first, then partial matches
        const sortedResults = results.sort((a: any, b: any) => {
          const aName = a.name || '';
          const bName = b.name || '';
          const aExact = aName.toLowerCase() === query.toLowerCase() ? 0 : 1;
          const bExact = bName.toLowerCase() === query.toLowerCase() ? 0 : 1;
          if (aExact !== bExact) return aExact - bExact;
          
          // Then sort by total_orders (most frequent customers first)
          return (b.total_orders || 0) - (a.total_orders || 0);
        });

        setMemberResults(sortedResults);
      } catch (err) {
        const cachedResults = await searchCachedPosMembers(query);
        if (cachedResults.length > 0) setMemberResults(cachedResults);
        else console.warn('[POS offline] Member search failed:', err);
      } finally {
        setIsSearching(false);
      }
    }, 400);
  };

  // Select member and calculate loyalty
  const selectMember = async (member: any) => {
    setSelectedMember(member);
    setMemberQuery(member.name);
    setMemberResults([]); // Clear results
    setIsSearching(false); // Stop searching state
    await calculateLoyalty(member.id);
  };

  // Calculate points and available rewards
  const calculateLoyalty = async (memberId: number) => {
    try {
      const res = await calculateLoyaltyPoints({
        total_amount: totalPrice,
        member_id: memberId,
      });
      setPointsToEarn(res.points_to_earn);
      setAvailableRewards(res.available_rewards || []);
    } catch (err) {
      console.error('Failed to calculate loyalty:', err);
    }
  };

  // Apply reward
  const handleApplyReward = async (reward: any) => {
    try {
      const res = await applyReward({
        member_id: selectedMember.id,
        reward_rule_id: reward.id,
        total_amount: totalPrice,
      });
      setSelectedReward(reward);
      setDiscountAmount(res.discount_amount);
    } catch (err) {
      console.error('Failed to apply reward:', err);
      alert('Gagal menerapkan reward');
    }
  };

  // Register new member
  const handleRegisterMember = async () => {
    try {
      const res = await createPosMember({
        name: newMemberName,
        phone: newMemberPhone || undefined,
        email: newMemberEmail || undefined,
      });
      const newMember = res.member;
      selectMember(newMember);
      setShowRegisterMember(false);
      setNewMemberName('');
      setNewMemberPhone('');
      setNewMemberEmail('');
      alert(`Member ${newMember.name} berhasil didaftarkan! 🎉`);
    } catch (err) {
      console.error('Failed to register member:', err);
      alert('Gagal mendaftarkan member');
    }
  };

  const handleCreateOrder = async () => {
    if (cart.length === 0) return;

    if (selectedMember && redeemPointsNum > 0 && !confirmMemberName.trim()) {
      setError('Ketik nama member untuk konfirmasi penukaran poin.');
      setCustomerOpen(true);
      return;
    }

    try {
      setLoading(true);
      setLoadingLabel(`Menyimpan ${terminology.transaction.toLowerCase()}...`);
      setError(null);
      const draft = buildDraft();
      if (!isOnline && (selectedReward || redeemPointsNum > 0)) {
        setError('Diskon reward dan penukaran poin tidak tersedia saat offline.');
        return;
      }
      if (!serverTotals) {
        setError('Perkiraan harga belum siap. Periksa data usaha yang tersimpan atau hubungkan ke internet.');
        return;
      }

      const user = getSessionUser<{ current_organization?: { name?: string } | null }>();
      const items = cart.map((item, index) => ({
        id: `${crypto.randomUUID()}-${index}`,
        product_id: item.product.id,
        product_variant_id: item.variantId,
        product_name: item.product.name,
        quantity: item.quantity,
        unit_price: Math.round(item.totalPrice / item.quantity),
        line_total: item.totalPrice,
        serial_numbers: item.serialNumbers,
        options: item.addons.map((addon) => `${addon.option_name}: ${addon.value_name}`),
      }));
      await saveOfflineOrder({
        items,
        subtotal: serverTotals.subtotal,
        discount_amount: serverTotals.discount_amount,
        points_discount_amount: serverTotals.points_discount_amount,
        service_amount: serverTotals.service_amount,
        tax_amount: serverTotals.tax_amount,
        rounding_amount: serverTotals.rounding_amount,
        final_amount: serverTotals.final_amount,
        payment_method: null,
        payment_amount: null,
        paid_manually: false,
        created_offline: !isOnline,
        customer_name: draft.customer_name ?? null,
        customer_phone: draft.customer_phone ?? null,
        customer_whatsapp: null,
        table_id: draft.table_id ?? null,
        table_code: tables.find((table) => table.id === draft.table_id)?.code ?? null,
        service_type: draft.service_type ?? 'takeaway',
        notes: draft.notes ?? null,
        organization_name: user?.current_organization?.name ?? 'Hellom POS',
        organization_address: null,
        organization_phone: null,
        payment_note: null,
        member_id: draft.member_id ?? null,
        draft,
      });

      onOrderCreated();
      onClose();
      if (isOnline) void syncPosOrders();
    } catch (err) {
      setError(err instanceof Error ? err.message : `Gagal membuat ${terminology.transaction.toLowerCase()}.`);
    } finally {
      setLoading(false);
    }
  };

  const renderProductAction = (product: Product) => {
    if (!isProductAvailable(product)) {
      return (
        <button type="button" disabled className="min-h-11 w-full cursor-not-allowed rounded-xl bg-gray-100 text-sm font-semibold text-gray-400">
          Habis
        </button>
      );
    }
    const inCart = isSimpleProduct(product) ? simpleCartItem(product) : undefined;
    if (inCart) {
      const atLimit = Boolean(product.track_stock) && inCart.quantity >= (product.stock ?? 0);
      return (
        <div className="flex min-h-11 items-center justify-between rounded-xl border border-amber-300 bg-white">
          <button
            type="button"
            onClick={() => updateQuantity(inCart.key, inCart.quantity - 1)}
            aria-label={`Kurangi jumlah ${product.name}`}
            className="flex h-11 w-11 items-center justify-center rounded-l-xl text-gray-700 hover:bg-amber-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
          >
            <Minus className="h-4 w-4" />
          </button>
          <span className="text-base font-semibold text-gray-900" aria-live="polite">{inCart.quantity}</span>
          <button
            type="button"
            onClick={() => handleProductClick(product)}
            disabled={atLimit}
            aria-label={`Tambah jumlah ${product.name}`}
            className="flex h-11 w-11 items-center justify-center rounded-r-xl text-gray-700 hover:bg-amber-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <Plus className="h-4 w-4" />
          </button>
        </div>
      );
    }
    return (
      <button
        type="button"
        onClick={() => handleProductClick(product)}
        className="flex min-h-11 w-full items-center justify-center gap-1 rounded-xl bg-amber-400 text-sm font-semibold text-gray-900 hover:bg-amber-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600"
      >
        <Plus className="h-4 w-4" />
        {isSimpleProduct(product) ? 'Tambah' : 'Pilih'}
      </button>
    );
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-white md:items-center md:justify-center md:bg-gray-900/30 md:backdrop-blur-sm">
      {loading && <HellomLoading mode="overlay" label={loadingLabel} />}
      <div className="relative flex h-[100dvh] w-full flex-col overflow-hidden bg-[#F7F8FA] md:h-[90vh] md:w-[94vw] md:max-w-6xl md:rounded-2xl md:shadow-2xl">
        {/* Header */}
        <div className={cn(
          'flex flex-shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 lg:px-6 lg:py-4',
          activeTab === 'cart' && 'hidden lg:flex'
        )}>
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-xl font-bold text-gray-900 lg:text-2xl">Tambah {terminology.transaction.toLowerCase()}</h2>
            <p className="hidden truncate text-sm text-gray-500 sm:block">Pilih {terminology.product.toLowerCase()} dan periksa keranjang</p>
          </div>
          {hasTablesSelfOrder && (
            <button
              type="button"
              onClick={() => setShowTableSelector(!showTableSelector)}
              aria-expanded={showTableSelector}
              aria-label="Pilih jenis transaksi atau meja"
              className="flex min-h-11 min-w-0 max-w-[10.5rem] items-center gap-2 rounded-xl border border-gray-300 bg-white px-3 text-sm font-medium text-gray-900 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 sm:max-w-[14rem]"
            >
              {selectedTableId ? <Armchair className="h-4 w-4 shrink-0 text-amber-700" /> : <ShoppingBag className="h-4 w-4 shrink-0 text-gray-500" />}
              <span className="truncate">{serviceLabel}</span>
              <ChevronDown className="h-4 w-4 shrink-0 text-gray-500" />
            </button>
          )}
          <button
            type="button"
            onClick={onClose}
            aria-label="Tutup"
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-500 hover:bg-gray-50 hover:text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Cart header (phones and portrait tablets) */}
        {activeTab === 'cart' && (
          <div className="flex flex-shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-3 py-3 lg:hidden">
            <button
              type="button"
              onClick={() => setActiveTab('menu')}
              aria-label={`Kembali ke daftar ${terminology.product.toLowerCase()}`}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
            >
              <ArrowLeft className="h-5 w-5" />
            </button>
            <div className="min-w-0 flex-1">
              <h2 className="truncate text-xl font-bold text-gray-900">Keranjang</h2>
              <p className="truncate text-sm text-gray-500">{cartSummaryLabel}</p>
            </div>
            <button
              type="button"
              onClick={onClose}
              aria-label="Tutup"
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        )}

        {/* Table Selector */}
        {hasTablesSelfOrder && showTableSelector && (
          <div className="max-h-[70vh] flex-shrink-0 overflow-y-auto border-b border-gray-200 bg-white p-4">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-semibold text-gray-900">Pilih {terminology.table.toLowerCase()}</h3>
              <button
                type="button"
                onClick={() => setShowTableSelector(false)}
                aria-label={`Tutup pilihan ${terminology.table.toLowerCase()}`}
                className="flex h-10 w-10 items-center justify-center text-gray-400 hover:text-gray-600"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-4">
              <button
                onClick={() => {
                  setSelectedTableId(null);
                  setShowTableSelector(false);
                }}
                className={cn(
                  'p-3 border-2 rounded-lg text-center transition-colors',
                  selectedTableId === null
                    ? 'border-amber-400 bg-amber-50 text-amber-900'
                    : 'border-gray-200 hover:border-gray-300 text-gray-700'
                )}
              >
                <ShoppingBag className="mx-auto mb-1 h-6 w-6" />
                <div className="text-sm font-medium">Bawa pulang</div>
              </button>
              {tables.filter(table => table.is_active).map(table => (
                <button
                  key={table.id}
                  onClick={() => {
                    setSelectedTableId(table.id);
                    setShowTableSelector(false);
                  }}
                  className={cn(
                    'p-3 border-2 rounded-lg text-center transition-colors',
                    selectedTableId === table.id
                      ? 'border-amber-400 bg-amber-50 text-amber-900'
                      : 'border-gray-200 hover:border-gray-300 text-gray-700'
                  )}
                >
                  <Armchair className="mx-auto mb-1 h-6 w-6" />
                  <div className="text-sm font-medium">{table.name || table.code}</div>
                  <div className="text-xs text-gray-500">{table.code}</div>
                </button>
              ))}
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Nama {terminology.customer} <span className="text-gray-400">(opsional)</span>
                </label>
                <input
                  type="text"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  placeholder="cth: Budi Santoso"
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Nomor HP <span className="text-gray-400">(opsional)</span>
                </label>
                <input
                  type="tel"
                  value={customerPhone}
                  onChange={(e) => setCustomerPhone(e.target.value)}
                  placeholder="cth: 08123456789"
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-sm"
                />
              </div>
              <button
                onClick={() => setShowTableSelector(false)}
                className="min-h-11 w-full rounded-lg bg-[#111111] px-4 py-2 text-sm font-semibold text-amber-400 transition-colors hover:bg-[#2a241d]"
              >
                Selesai
              </button>
            </div>
          </div>
        )}


        {/* Notification */}
        {notification?.visible && (
          <div className="absolute top-4 left-1/2 w-max max-w-[calc(100%-2rem)] transform -translate-x-1/2 z-50 animate-in slide-in-from-top-2 fade-in duration-300">
            <div className="bg-green-600 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 border border-green-500">
              <div className="w-5 h-5 shrink-0 flex items-center justify-center bg-green-700 rounded-full">
                <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <span className="min-w-0 text-sm font-medium [overflow-wrap:anywhere]">{notification.message}</span>
            </div>
          </div>
        )}

        {/* Main Content: Menu + Cart */}
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden lg:flex-row">
          {/* Menu Panel */}
          <div className={cn(
            'flex min-h-0 flex-1 flex-col',
            activeTab === 'cart' ? 'hidden lg:flex' : 'flex'
          )}>
            <div className="min-h-0 flex-1 overflow-y-auto p-4 lg:p-5">
              {/* Search and Filters */}
              <div className="mb-4 space-y-3">
                {/* Search */}
                <div className="relative">
                  <input
                    type="text"
                    placeholder={`Cari ${terminology.product.toLowerCase()}${hasModule('barcode') ? ' / SKU / barcode' : ''}...`}
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onKeyDown={(event) => {
                      if (event.key !== 'Enter' || !hasModule('barcode')) return;
                      const code = searchQuery.trim().toLowerCase();
                      const scannedProduct = products.find((product) =>
                        [product.barcode, product.sku].some((value) => value?.toLowerCase() === code)
                      );
                      const scannedVariantProduct = products.find((product) =>
                        product.variants?.some((variant) =>
                          [variant.barcode, variant.sku].some((value) => value?.toLowerCase() === code)
                        )
                      );
                      const scanned = scannedProduct ?? scannedVariantProduct;
                      if (scanned) {
                        handleProductClick(scanned);
                        const variant = scanned.variants?.find((row) =>
                          [row.barcode, row.sku].some((value) => value?.toLowerCase() === code)
                        );
                        if (variant) setSelectedVariantId(String(variant.id));
                        setSearchQuery('');
                      }
                    }}
                    className="min-h-11 w-full rounded-xl border border-gray-300 bg-white py-2 pl-10 pr-3 text-base text-gray-900 placeholder-gray-400 focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-300"
                  />
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
                </div>
                {hasModule('barcode') && (
                  <div className="space-y-2">
                    <button
                      type="button"
                      onClick={() => setScannerOpen((open) => !open)}
                      className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                      <ScanLine className="h-4 w-4" />
                      {scannerOpen ? 'Tutup kamera' : 'Pindai dengan kamera'}
                    </button>
                    {scannerOpen && <video ref={scannerVideoRef} autoPlay muted playsInline className="max-h-56 w-full rounded-lg bg-black object-cover" />}
                    {scannerError && <p role="status" className="text-sm text-amber-800">{scannerError}</p>}
                  </div>
                )}

                {/* Categories: one horizontally scrollable row, all original names kept */}
                <div className="-mx-4 overflow-x-auto px-4 pb-1 lg:-mx-5 lg:px-5" role="group" aria-label="Kategori">
                  <div className="flex w-max min-w-full gap-2">
                    <button
                      type="button"
                      aria-pressed={!selectedCategory}
                      onClick={() => setSelectedCategory(null)}
                      className={cn(
                        'min-h-10 shrink-0 whitespace-nowrap rounded-full px-4 text-sm font-semibold transition-colors',
                        !selectedCategory ? 'bg-amber-400 text-[#111111]' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'
                      )}
                    >
                      Semua
                    </button>
                    {categories.map(category => (
                      <button
                        key={category.id}
                        type="button"
                        aria-pressed={selectedCategory === category.id}
                        onClick={() => setSelectedCategory(category.id)}
                        className={cn(
                          'min-h-10 shrink-0 whitespace-nowrap rounded-full px-4 text-sm font-semibold transition-colors',
                          selectedCategory === category.id ? 'bg-amber-400 text-[#111111]' : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50'
                        )}
                      >
                        {category.name}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Count, sort (all old options in one control) and view mode */}
                <div className="flex items-center justify-between gap-2">
                  <p className="min-w-0 truncate text-sm text-gray-600">
                    {filteredProducts.filter(isProductAvailable).length} {terminology.product.toLowerCase()} tersedia
                  </p>
                  <div className="flex shrink-0 items-center gap-2">
                    <label className="relative flex min-h-10 items-center">
                      <span className="sr-only">Urutkan {terminology.product.toLowerCase()}</span>
                      <ArrowUpDown className="pointer-events-none absolute left-3 h-4 w-4 text-gray-500" aria-hidden="true" />
                      <select
                        value={priceSort}
                        onChange={(event) => setPriceSort(event.target.value as 'none' | 'asc' | 'desc')}
                        className="min-h-10 appearance-none rounded-xl border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm font-medium text-gray-800 focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-300"
                      >
                        <option value="none">Urutkan</option>
                        <option value="asc">Termurah</option>
                        <option value="desc">Termahal</option>
                      </select>
                    </label>
                    <div className="flex items-center gap-1 rounded-xl bg-gray-100 p-1" role="group" aria-label="Tampilan katalog">
                      {([['grid', 'Kisi', LayoutGrid], ['list', 'Daftar', List]] as const).map(([mode, label, Icon]) => (
                        <button
                          key={mode}
                          type="button"
                          aria-pressed={viewMode === mode}
                          aria-label={`Tampilan ${label.toLowerCase()}`}
                          onClick={() => setViewMode(mode)}
                          className={cn(
                            'flex h-9 w-9 items-center justify-center rounded-lg transition-colors',
                            viewMode === mode ? 'bg-white text-amber-800 shadow-sm' : 'text-gray-500 hover:text-gray-800'
                          )}
                        >
                          <Icon className="h-4 w-4" />
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* Products Display */}
              {loading && products.length === 0 ? (
                <p role="status" className="py-12 text-center text-sm text-gray-500">Memuat {terminology.product.toLowerCase()}...</p>
              ) : filteredProducts.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center">
                  <p className="text-sm font-semibold text-gray-800">
                    {products.length === 0 ? `Belum ada ${terminology.product.toLowerCase()}` : `${terminology.product} tidak ditemukan`}
                  </p>
                  <p className="mt-1 text-sm text-gray-500">
                    {products.length === 0 ? `Tambahkan ${terminology.product.toLowerCase()} dulu di halaman ${terminology.product}.` : 'Coba ubah kata kunci atau pilih kategori lain.'}
                  </p>
                </div>
              ) : viewMode === 'grid' ? (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                  {filteredProducts.map(product => (
                    <div
                      key={product.id}
                      className={cn(
                        'flex min-w-0 flex-col overflow-hidden rounded-xl border bg-white transition-colors',
                        simpleCartItem(product) ? 'border-amber-300 bg-amber-50/40' : 'border-gray-200',
                        !isProductAvailable(product) && 'opacity-70'
                      )}
                    >
                      <button
                        type="button"
                        onClick={() => handleProductClick(product)}
                        aria-label={`${product.name}, Rp ${product.price.toLocaleString('id-ID')}, ${getStockLabel(product)}`}
                        className="group min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amber-400"
                      >
                        <div className="relative aspect-[4/3] overflow-hidden bg-gray-100">
                          <div className="absolute inset-0 flex items-center justify-center text-gray-300" aria-hidden="true">
                            <ImageIcon className="h-8 w-8" />
                          </div>
                          {product.image_path && (
                            <img
                              src={getImageUrl(product.image_path)}
                              alt=""
                              loading="lazy"
                              className={cn('absolute inset-0 h-full w-full object-cover', !isProductAvailable(product) && 'grayscale')}
                              onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                          )}
                        </div>
                        <div className="space-y-1 p-3 pb-2">
                          <h4 className="line-clamp-2 break-words text-sm font-semibold leading-snug text-gray-900">{product.name}</h4>
                          <p className="text-sm font-bold text-amber-800">Rp {product.price.toLocaleString('id-ID')}</p>
                          <span className={cn('inline-block rounded-full border px-2 py-0.5 text-[11px] font-semibold', getStockBadgeClass(product))}>
                            {getStockLabel(product)}
                          </span>
                        </div>
                      </button>
                      <div className="p-3 pt-1">{renderProductAction(product)}</div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="space-y-2">
                  {filteredProducts.map(product => (
                    <div
                      key={product.id}
                      className={cn(
                        'flex min-w-0 items-center gap-3 rounded-xl border bg-white p-3 transition-colors',
                        simpleCartItem(product) ? 'border-amber-300 bg-amber-50/40' : 'border-gray-200',
                        !isProductAvailable(product) && 'opacity-70'
                      )}
                    >
                      <button
                        type="button"
                        onClick={() => handleProductClick(product)}
                        aria-label={`${product.name}, Rp ${product.price.toLocaleString('id-ID')}, ${getStockLabel(product)}`}
                        className="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                      >
                        <div className="relative h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-gray-100">
                          <div className="absolute inset-0 flex items-center justify-center text-gray-300" aria-hidden="true">
                            <ImageIcon className="h-6 w-6" />
                          </div>
                          {product.image_path && (
                            <img
                              src={getImageUrl(product.image_path)}
                              alt=""
                              loading="lazy"
                              className={cn('absolute inset-0 h-full w-full object-cover', !isProductAvailable(product) && 'grayscale')}
                              onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                          )}
                        </div>
                        <div className="min-w-0 flex-1">
                          <h4 className="line-clamp-2 break-words text-sm font-semibold leading-snug text-gray-900">{product.name}</h4>
                          {product.description && <p className="line-clamp-1 text-xs text-gray-500">{product.description}</p>}
                          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-sm font-bold text-amber-800">Rp {product.price.toLocaleString('id-ID')}</span>
                            <span className={cn('rounded-full border px-2 py-0.5 text-[11px] font-semibold', getStockBadgeClass(product))}>
                              {getStockLabel(product)}
                            </span>
                            {product.category?.name && (
                              <span className="max-w-[9rem] truncate rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">{product.category.name}</span>
                            )}
                          </div>
                        </div>
                      </button>
                      <div className="w-28 shrink-0 sm:w-32">{renderProductAction(product)}</div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Phone/portrait-tablet cart summary bar */}
            <div
              className="flex-shrink-0 space-y-3 border-t border-gray-200 bg-white px-4 pt-3 lg:hidden"
              style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}
            >
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="font-medium text-gray-700">{cartUnits} item</span>
                <span className="text-base font-bold text-gray-900">Rp {totalPrice.toLocaleString('id-ID')}</span>
              </div>
              <button
                type="button"
                data-cart-tab
                disabled={cart.length === 0}
                onClick={() => setActiveTab('cart')}
                className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#111111] px-4 text-base font-bold text-amber-400 transition-colors hover:bg-[#2a241d] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Lihat keranjang
                <ArrowRight className="h-5 w-5" />
              </button>
            </div>
          </div>

          {/* Cart Panel */}
          <div className={cn(
            'flex min-h-0 w-full flex-col border-gray-200 bg-white lg:w-[40%] lg:min-w-[340px] lg:max-w-[460px] lg:border-l',
            activeTab === 'menu' ? 'hidden lg:flex' : 'flex'
          )}>
            {/* Scrollable cart content: items + customer + notes */}
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 lg:p-5">
              <div className="hidden lg:block">
                <h3 className="text-xl font-bold text-gray-900">Keranjang</h3>
                <p className="text-sm text-gray-500">{cartSummaryLabel}</p>
              </div>
              {hasTablesSelfOrder && (
                <button
                  type="button"
                  onClick={() => setShowTableSelector(true)}
                  className="flex min-h-11 w-full items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 lg:hidden"
                >
                  {selectedTableId ? <Armchair className="h-4 w-4 shrink-0 text-amber-700" /> : <ShoppingBag className="h-4 w-4 shrink-0 text-gray-500" />}
                  <span className="min-w-0 flex-1 truncate">{serviceLabel}</span>
                  <span className="text-xs font-semibold text-amber-800">Ubah</span>
                </button>
              )}

              {cart.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-4 py-10 text-center">
                  <ShoppingBag className="mx-auto mb-2 h-8 w-8 text-gray-300" aria-hidden="true" />
                  <p className="text-sm font-semibold text-gray-800">Keranjang masih kosong</p>
                  <p className="mt-1 text-sm text-gray-500">Pilih {terminology.product.toLowerCase()} untuk menambah ke keranjang.</p>
                  <button
                    type="button"
                    onClick={() => setActiveTab('menu')}
                    className="mt-4 min-h-11 rounded-xl bg-[#111111] px-4 text-sm font-semibold text-amber-400 lg:hidden"
                  >
                    Pilih {terminology.product.toLowerCase()}
                  </button>
                </div>
              ) : (
                <>
                  <div className="space-y-3">
                    {cart.map((item) => (
                      <div key={item.key} className="rounded-xl border border-gray-200 bg-white p-3">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0 flex-1">
                            <h5 className="break-words text-base font-semibold leading-snug text-gray-900">{item.product.name}</h5>
                            <p className="text-sm text-gray-500">
                              Rp {getCartUnitPrice(item).toLocaleString('id-ID')} / item
                            </p>
                            {item.variantId && <p className="text-sm text-gray-500">Varian: {item.product.variants?.find((variant) => variant.id === item.variantId)?.name}</p>}
                            {item.customerType && <p className="text-sm text-gray-500">Harga: {item.customerType}</p>}
                            {item.serialNumbers.length > 0 && <p className="break-all text-sm text-gray-500">IMEI/serial: {item.serialNumbers.join(', ')}</p>}
                            {item.addons.length > 0 && (
                              <div className="mt-1 space-y-0.5">
                                {item.addons.map(addon => (
                                  <div key={addon.value_id} className="text-sm text-amber-800">
                                    + {addon.value_name} {addon.price_delta > 0 && `(+Rp ${addon.price_delta.toLocaleString('id-ID')})`}
                                  </div>
                                ))}
                              </div>
                            )}
                          </div>
                          <p className="shrink-0 text-base font-bold text-gray-900">Rp {item.totalPrice.toLocaleString('id-ID')}</p>
                        </div>
                        <div className="mt-3 flex items-center justify-between gap-2">
                          <div className="flex items-center rounded-xl border border-gray-300 bg-white">
                            <button
                              type="button"
                              onClick={() => updateQuantity(item.key, item.quantity - 1)}
                              aria-label={`Kurangi jumlah ${item.product.name}`}
                              className="flex h-11 w-11 items-center justify-center rounded-l-xl text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                            >
                              <Minus className="h-4 w-4" />
                            </button>
                            <span className="min-w-[2.5rem] text-center text-base font-semibold text-gray-900" aria-live="polite">{item.quantity}</span>
                            <button
                              type="button"
                              onClick={() => updateQuantity(item.key, item.quantity + 1)}
                              disabled={Boolean(item.product.track_serial)}
                              aria-label={`Tambah jumlah ${item.product.name}`}
                              title={item.product.track_serial ? 'Tambahkan unit dengan memilih serial baru' : undefined}
                              className={cn(
                                'flex h-11 w-11 items-center justify-center rounded-r-xl text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400',
                                item.product.track_serial && 'cursor-not-allowed opacity-40'
                              )}
                            >
                              <Plus className="h-4 w-4" />
                            </button>
                          </div>
                          <button
                            type="button"
                            onClick={() => updateQuantity(item.key, 0)}
                            aria-label={`Hapus ${item.product.name} dari keranjang`}
                            className="flex h-11 w-11 items-center justify-center rounded-xl border border-gray-200 text-gray-500 hover:border-red-200 hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>

                  {/* Customer entry point: member search/registration and walk-in details live in one sheet */}
                  <button
                    type="button"
                    onClick={() => setCustomerOpen(true)}
                    className={cn(
                      'flex min-h-14 w-full items-center gap-3 rounded-xl border bg-white px-3 py-2 text-left hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400',
                      pointsNeedConfirm ? 'border-red-300' : 'border-gray-200'
                    )}
                  >
                    <User className="h-5 w-5 shrink-0 text-gray-500" />
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-semibold text-gray-900">{terminology.customer}</span>
                      <span className="block truncate text-sm text-gray-500">{customerSummary}</span>
                      {pointsNeedConfirm && <span className="block text-xs font-medium text-red-600">Konfirmasi nama member untuk tukar poin</span>}
                    </span>
                    <ChevronRight className="h-5 w-5 shrink-0 text-gray-400" />
                  </button>

                  {customerOpen && (
                    <div
                      className="fixed inset-0 z-[55] flex items-end justify-center bg-black/40 lg:items-center lg:p-4"
                      onClick={() => setCustomerOpen(false)}
                    >
                      <div
                        role="dialog"
                        aria-modal="true"
                        aria-label={terminology.customer}
                        className="flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl lg:max-w-lg lg:rounded-2xl"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <div className="flex flex-shrink-0 items-center justify-between border-b border-gray-200 px-4 py-3">
                          <h3 className="text-lg font-bold text-gray-900">{terminology.customer}</h3>
                          <button
                            type="button"
                            onClick={() => setCustomerOpen(false)}
                            aria-label={`Tutup ${terminology.customer.toLowerCase()}`}
                            className="flex h-11 w-11 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                          >
                            <X className="h-5 w-5" />
                          </button>
                        </div>
                        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                  {/* Member Information */}
                  <div className="border border-gray-200 rounded-xl p-4 bg-gradient-to-br from-blue-50 to-transparent">
                    <h3 className="font-semibold text-gray-800 mb-3 flex items-center gap-2">
                      <Star className="w-4 h-4 fill-amber-400 text-amber-400" />
                      Member
                    </h3>

                    {/* Search member */}
                    {!selectedMember ? (
                      <div className="relative">
                        <div className="relative">
                          <input
                            type="text"
                            value={memberQuery}
                            onChange={e => searchMember(e.target.value)}
                            placeholder="Ketik nama atau nomor HP member..."
                            className="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-gray-900 text-sm focus:ring-2 focus:ring-blue-400 focus:border-blue-400"
                          />
                          {isSearching && (
                            <div className="absolute right-3 top-2.5">
                              <div className="animate-spin rounded-full h-4 w-4 border-2 border-blue-300 border-t-blue-600"></div>
                            </div>
                          )}
                          {memberQuery.length > 0 && !isSearching && (
                            <button
                              onClick={() => {
                                setMemberQuery('');
                                setMemberResults([]);
                              }}
                              className="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600"
                            >
                              ✕
                            </button>
                          )}
                        </div>

                        {/* Hasil pencarian */}
                        {memberResults.length > 0 && (
                          <div className="bg-white border border-gray-200 rounded-lg mt-2 overflow-hidden max-h-56 overflow-y-auto">
                            {memberResults.map(m => (
                              <button
                                key={m.id}
                                onClick={() => selectMember(m)}
                                className="w-full text-left px-3 py-2.5 hover:bg-blue-50 transition-colors border-b border-gray-100 last:border-0 flex items-center gap-2.5"
                              >
                                <div className="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                  <span className="text-xs font-bold text-blue-600">
                                    {getMemberName(m).charAt(0).toUpperCase()}
                                  </span>
                                </div>
                                <div className="flex-1 min-w-0">
                                  <div className="text-sm font-semibold text-gray-900 truncate leading-tight">
                                    {getMemberName(m)}
                                  </div>
                                  <div className="text-xs text-gray-400 truncate leading-tight">
                                    {getMemberPhone(m) || getMemberEmail(m) || '—'}
                                  </div>
                                </div>
                                <div className="text-right flex-shrink-0">
                                  <div className="text-xs font-bold text-blue-600">{getMemberPoints(m)} pts</div>
                                  <div className="text-[10px] text-gray-400">{getMemberOrders(m)}x order</div>
                                </div>
                              </button>
                            ))}
                          </div>
                        )}

                        {/* Tidak ada hasil */}
                        {memberQuery.length >= 2 && memberResults.length === 0 && !isSearching && (
                          <div className="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-gray-700">
                            <div className="font-medium mb-1">⚠️ Member tidak ditemukan</div>
                            <div className="text-xs text-gray-600">
                              Coba cek:
                              <ul className="list-disc list-inside mt-1">
                                <li>Pastikan spelling nama sudah benar</li>
                                <li>Coba cari dengan nomor HP saja</li>
                              </ul>
                            </div>
                            <button
                              onClick={() => setShowRegisterMember(true)}
                              className="mt-2 text-blue-600 text-xs font-semibold hover:underline"
                            >
                              + Daftarkan "{memberQuery}" sebagai member baru
                            </button>
                          </div>
                        )}

                        {/* Hint ketika belum ada input */}
                        {memberQuery.length === 0 && memberResults.length === 0 && (
                          <div className="mt-2 text-xs text-gray-500 p-2 bg-gray-50 rounded">
                            💡 Mulai ketik untuk mencari member atau buat member baru
                          </div>
                        )}
                      </div>
                    ) : (
                      /* Member terpilih */
                      <div className="bg-blue-100 border-2 border-blue-400 rounded-lg p-3">
                        <div className="flex items-start justify-between">
                          <div className="flex-1">
                            <div className="font-bold text-gray-900 flex items-center gap-2">
                              <Star className="w-4 h-4 fill-yellow-400 text-yellow-400" />
                              {getMemberName(selectedMember)}
                            </div>
                            <div className="text-xs text-gray-600 mt-1.5 space-y-0.5">
                              {getMemberPhone(selectedMember) && (
                                <div>📱 {getMemberPhone(selectedMember)}</div>
                              )}
                              {getMemberEmail(selectedMember) && (
                                <div>✉️ {getMemberEmail(selectedMember)}</div>
                              )}
                            </div>
                            <div className="flex gap-3 mt-2 text-xs font-semibold">
                              <div className="text-blue-600">⭐ {getMemberPoints(selectedMember)} poin</div>
                              <div className="text-gray-600">🛍️ {getMemberOrders(selectedMember)}x transaksi</div>
                            </div>
                          </div>
                          <button
                            onClick={() => {
                              setSelectedMember(null);
                              setMemberQuery('');
                              setAvailableRewards([]);
                              setSelectedReward(null);
                              setDiscountAmount(0);
                              setPointsToEarn(0);
                              setRedeemPoints('');
                              setConfirmMemberName('');
                            }}
                            className="text-gray-400 hover:text-red-500 text-lg px-2 py-1 rounded transition"
                          >
                            ✕
                          </button>
                        </div>

                        {/* Info poin yang akan didapat */}
                        {pointsToEarn > 0 && (
                          <div className="mt-2 text-xs text-green-600 bg-green-50 rounded-lg px-3 py-1.5">
                            ✨ Perkiraan <strong>+{pointsToEarn} poin</strong> setelah transaksi dibayar
                          </div>
                        )}

                        {/* Tukar poin: potongan harga, wajib konfirmasi nama member */}
                        {getMemberPoints(selectedMember) > 0 && (
                          <div className="mt-3 rounded-lg border border-blue-200 bg-white p-2.5">
                            <div className="text-xs font-semibold text-gray-700">Tukar poin (saldo {getMemberPoints(selectedMember)})</div>
                            <div className="mt-1.5 flex gap-2">
                              <input
                                inputMode="numeric"
                                value={redeemPoints}
                                onChange={(e) => setRedeemPoints(e.target.value.replace(/\D/g, ''))}
                                placeholder="Jumlah poin"
                                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                              />
                              <input
                                value={confirmMemberName}
                                onChange={(e) => setConfirmMemberName(e.target.value)}
                                placeholder="Ketik nama member"
                                className="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                              />
                            </div>
                            <p className="mt-1 text-[11px] text-gray-500">
                              Tanyakan nama ke {terminology.customer.toLowerCase()}, lalu ketik untuk konfirmasi. Poin terpotong saat transaksi dibuat dan kembali jika dibatalkan.
                            </p>
                          </div>
                        )}

                        {/* Reward yang tersedia */}
                        {availableRewards.length > 0 && (
                          <div className="mt-3">
                            <div className="text-xs font-semibold text-gray-700 mb-2 flex items-center gap-1">
                              <Gift className="w-3 h-3" />
                              Reward tersedia untuk member ini:
                            </div>
                            <div className="space-y-1.5">
                              {availableRewards.map(r => (
                                <button
                                  key={r.id}
                                  onClick={() => selectedReward?.id === r.id
                                    ? (() => {
                                        setSelectedReward(null);
                                        setDiscountAmount(0);
                                      })()
                                    : handleApplyReward(r)
                                  }
                                  className={`w-full text-left px-3 py-2 rounded-lg text-sm transition border ${
                                    selectedReward?.id === r.id
                                      ? 'bg-green-100 border-green-400 text-green-800'
                                      : 'bg-white border-gray-200 hover:border-blue-400'
                                  }`}
                                >
                                  <div className="font-medium">{r.name}</div>
                                  <div className="text-xs text-gray-500">
                                    {r.description}
                                  </div>
                                  {selectedReward?.id === r.id && (
                                    <div className="text-xs text-green-600 mt-1">
                                      ✅ Reward diterapkan — hemat {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(discountAmount)}
                                    </div>
                                  )}
                                </button>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                    )}
                  </div>

                  {/* Customer Information - untuk non-member */}
                  <div className="space-y-3 border-t border-gray-200 pt-4 mt-4">
                    <div className="text-xs text-gray-500 mb-2">👤 Data {terminology.customer} (jika bukan member)</div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Nama {terminology.customer} <span className="text-gray-400">(opsional)</span>
                      </label>
                      <input
                        type="text"
                        value={customerName}
                        onChange={(e) => setCustomerName(e.target.value)}
                        placeholder="misalnya: Budi Santoso"
                        className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Nomor HP <span className="text-gray-400">(opsional)</span>
                      </label>
                      <input
                        type="tel"
                        value={customerPhone}
                        onChange={(e) => setCustomerPhone(e.target.value)}
                        placeholder="misalnya: 08123456789"
                        className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                      />
                    </div>
                  </div>
                        </div>
                        <div className="flex-shrink-0 border-t border-gray-200 p-4" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom))' }}>
                          <button
                            type="button"
                            onClick={() => setCustomerOpen(false)}
                            className="min-h-12 w-full rounded-xl bg-[#111111] text-base font-semibold text-amber-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                          >
                            Selesai
                          </button>
                        </div>
                      </div>
                    </div>
                  )}

                  <div className="rounded-xl border border-gray-200 bg-white">
                    <button
                      type="button"
                      onClick={() => setNotesOpen((open) => !open)}
                      aria-expanded={notesOpen}
                      className="flex min-h-12 w-full items-center gap-3 px-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded-xl"
                    >
                      <StickyNote className="h-5 w-5 shrink-0 text-gray-500" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-gray-900">Catatan</span>
                        {!notesOpen && notes.trim() && <span className="block truncate text-sm text-gray-500">{notes}</span>}
                      </span>
                      <ChevronDown className={cn('h-5 w-5 shrink-0 text-gray-400 transition-transform', notesOpen && 'rotate-180')} />
                    </button>
                    {notesOpen && (
                      <div className="px-3 pb-3">
                        <textarea
                          value={notes}
                          onChange={(e) => setNotes(e.target.value)}
                          placeholder="misalnya: gak pake kecap, tambah sambal ya..."
                          rows={3}
                          aria-label="Catatan"
                          className="w-full resize-none rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                        />
                      </div>
                    )}
                  </div>

                  {/* Spacer so last field isn't flush against sticky footer */}
                  <div className="h-1" />
                </>
              )}
            </div>

            {/* Sticky footer: Total + Create Order button (always visible) */}
            {cart.length > 0 && (
              <div
                className="flex-shrink-0 sticky bottom-0 border-t border-gray-200 bg-gray-50 p-4 space-y-3"
                style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom))' }}
              >
                {serverTotals && (
                  <div className="space-y-0.5 text-xs text-gray-600">
                    {!isOnline && (
                      <p className="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-amber-800">
                        Perkiraan offline. Harga akhir divalidasi server setelah sinkronisasi.
                      </p>
                    )}
                    <div className="flex justify-between"><span>Subtotal</span><span>Rp {serverTotals.subtotal.toLocaleString('id-ID')}</span></div>
                    {serverTotals.discount_amount > 0 && (
                      <div className="flex justify-between text-green-700"><span>Diskon reward</span><span>− Rp {serverTotals.discount_amount.toLocaleString('id-ID')}</span></div>
                    )}
                    {serverTotals.points_discount_amount > 0 && (
                      <div className="flex justify-between text-green-700"><span>Tukar poin</span><span>− Rp {serverTotals.points_discount_amount.toLocaleString('id-ID')}</span></div>
                    )}
                    {serverTotals.service_amount > 0 && (
                      <div className="flex justify-between"><span>Service {serverTotals.service_percent}%</span><span>Rp {serverTotals.service_amount.toLocaleString('id-ID')}</span></div>
                    )}
                    {serverTotals.tax_amount > 0 && (
                      <div className="flex justify-between"><span>Pajak {serverTotals.tax_percent}%</span><span>Rp {serverTotals.tax_amount.toLocaleString('id-ID')}</span></div>
                    )}
                    {serverTotals.rounding_amount !== 0 && (
                      <div className="flex justify-between"><span>Pembulatan</span><span>Rp {serverTotals.rounding_amount.toLocaleString('id-ID')}</span></div>
                    )}
                  </div>
                )}
                {previewError && <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{previewError}</p>}
                {error && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{error}</p>}
                <div className="flex justify-between items-center">
                  <span className="font-semibold text-gray-900">Total Bayar</span>
                  <span className="font-bold text-gray-900 text-xl">Rp {finalAmount.toLocaleString('id-ID')}</span>
                </div>

                <button
                  type="button"
                  onClick={handleCreateOrder}
                  disabled={loading || Boolean(previewError)}
                  className="min-h-12 w-full rounded-xl bg-[#111111] px-4 py-3 text-base font-semibold text-amber-400 transition-colors hover:bg-[#2a241d] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {loading ? `Membuat ${terminology.transaction.toLowerCase()}...` : `Buat ${terminology.transaction} • Rp ${finalAmount.toLocaleString('id-ID')}`}
                </button>
                <p className="text-center text-xs text-gray-500">Pembayaran diproses setelah {terminology.transaction.toLowerCase()} dibuat</p>
              </div>
            )}
          </div>
        </div>

        {/* Error Message (cart empty; otherwise shown inside the footer) */}
        {error && cart.length === 0 && (
          <div className="flex-shrink-0 border-t border-gray-200 p-4 bg-red-50">
            <p className="text-red-800 text-sm">{error}</p>
          </div>
        )}

        {/* Add-on Selection Modal */}
        {showAddonModal && selectedProductForAddon && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/20 backdrop-blur-md">
            <div className="bg-white rounded-xl max-w-md w-full mx-4 max-h-[90vh] overflow-hidden flex flex-col">
              <div className="flex-shrink-0 p-4 border-b border-gray-200">
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                    {selectedProductForAddon.image_path ? (
                      <img
                        src={getImageUrl(selectedProductForAddon.image_path)}
                        alt={selectedProductForAddon.name}
                        className="w-full h-full object-cover"
                        onError={(e) => { e.currentTarget.style.display = 'none'; }}
                      />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center text-gray-400">
                        <div className="w-6 h-6 border-2 border-gray-300 rounded" />
                      </div>
                    )}
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="font-semibold text-gray-900 text-sm">{selectedProductForAddon.name}</h3>
                    <p className="text-xs text-gray-600">Rp {selectedProductForAddon.price.toLocaleString('id-ID')}</p>
                  </div>
                </div>
              </div>

              <div className="flex-1 min-h-0 overflow-y-auto p-4">
                {configuredRequiresVariant && (
                  <label className="mb-4 block">
                    <span className="mb-1 block text-sm font-medium text-gray-900">Pilih varian <span className="text-red-500">*</span></span>
                    <select
                      value={selectedVariantId}
                      onChange={(event) => {
                        setSelectedVariantId(event.target.value);
                        setSelectedSerials([]);
                      }}
                      className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900"
                    >
                      <option value="">Pilih varian</option>
                      {selectedProductForAddon.variants?.filter((variant) => variant.is_active).map((variant) => (
                        <option key={variant.id} value={variant.id} disabled={variant.track_stock && (variant.stock ?? 0) < 1}>
                          {variant.name}{variant.sku ? ` (${variant.sku})` : ''} — Rp {(variant.price ?? selectedProductForAddon.price).toLocaleString('id-ID')}
                          {variant.track_stock ? ` · Stok ${variant.stock ?? 0}` : ''}
                        </option>
                      ))}
                    </select>
                  </label>
                )}

                {hasModule('price_tiers') && (selectedProductForAddon.price_tiers?.length ?? 0) > 0 && (
                  <div className="mb-4 space-y-3">
                    <label className="block">
                      <span className="mb-1 block text-sm font-medium text-gray-900">Tipe harga</span>
                      <select
                        value={customerType}
                        onChange={(event) => setCustomerType(event.target.value)}
                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900"
                      >
                        <option value="">Harga umum</option>
                        {[...new Set(selectedProductForAddon.price_tiers?.map((row) => row.customer_type).filter((value): value is string => Boolean(value)))].map((type) => (
                          <option key={type} value={type}>{type}</option>
                        ))}
                      </select>
                    </label>
                    {!configuredRequiresSerial && (
                      <label className="block">
                        <span className="mb-1 block text-sm font-medium text-gray-900">Jumlah</span>
                        <input
                          type="number"
                          min="1"
                          value={configuredQuantity}
                          onChange={(event) => setConfiguredQuantity(event.target.value)}
                          className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900"
                        />
                      </label>
                    )}
                    <p className="text-sm text-emerald-800">
                      Harga satuan: Rp {configuredUnitPrice.toLocaleString('id-ID')}
                      {configuredTier ? ` (${configuredTier.label})` : ''}
                    </p>
                  </div>
                )}

                {configuredRequiresSerial && (
                  <section className="mb-4">
                    <h4 className="mb-1 text-sm font-medium text-gray-900">Pilih IMEI/serial <span className="text-red-500">*</span></h4>
                    <p className="mb-2 text-xs text-gray-600">Pilih satu serial untuk setiap unit yang dijual.</p>
                {configuredRequiresVariant && !selectedVariantId ? (
                  <p className="text-sm text-gray-500">Pilih varian terlebih dahulu untuk memuat serial yang sesuai.</p>
                ) : serialLoading ? (
                  <p className="text-sm text-gray-500">Memuat serial tersedia...</p>
                    ) : serialError ? (
                      <p className="text-sm text-red-700">{serialError}</p>
                    ) : availableSerials.length === 0 ? (
                      <p className="text-sm text-amber-800">Tidak ada serial tersedia untuk produk/varian ini.</p>
                    ) : (
                      <div className="max-h-40 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-2">
                        {availableSerials.map((serial) => (
                          <label key={serial.id} className="flex items-center gap-2 rounded-md p-2 text-sm hover:bg-gray-50">
                            <input
                              type="checkbox"
                              checked={selectedSerials.includes(serial.serial_number)}
                              disabled={!selectedSerials.includes(serial.serial_number) && selectedSerialsInCart.includes(serial.serial_number)}
                              onChange={(event) => setSelectedSerials((previous) => event.target.checked
                                ? [...previous, serial.serial_number]
                                : previous.filter((value) => value !== serial.serial_number))}
                              className="h-4 w-4 rounded border-gray-300 text-amber-500"
                            />
                            <span>{serial.serial_number}{selectedSerialsInCart.includes(serial.serial_number) ? ' · sudah di keranjang' : ''}</span>
                          </label>
                        ))}
                      </div>
                    )}
                    <p className="mt-2 text-xs text-gray-600">Terpilih: {selectedSerials.length} unit</p>
                    <p className="mt-1 text-sm text-emerald-800">Harga satuan: Rp {configuredUnitPrice.toLocaleString('id-ID')}{configuredTier ? ` (${configuredTier.label})` : ''}</p>
                  </section>
                )}

                {selectedProductForAddon.options.filter(opt => opt.is_active).map(option => (
                  <div key={option.id} className="mb-4">
                    <h4 className="font-medium text-gray-900 text-sm mb-2">
                      {option.name}
                      {option.is_required && <span className="text-red-500 ml-1">*</span>}
                    </h4>
                    <div className="space-y-2">
                      {option.values.filter(val => val.is_active).map(value => (
                        <label
                          key={value.id}
                          className="flex items-center justify-between p-3 border border-gray-200 rounded-lg cursor-pointer hover:border-amber-300 transition-colors"
                        >
                          <div className="flex items-center gap-3">
                            <input
                              type={option.type === 'single' ? 'radio' : 'checkbox'}
                              name={`option-${option.id}`}
                              value={value.id}
                              checked={
                                option.type === 'single'
                                  ? selectedAddons[option.id]?.[0] === value.id
                                  : selectedAddons[option.id]?.includes(value.id) || false
                              }
                              onChange={(e) => {
                                const valueId = parseInt(e.target.value);
                                setSelectedAddons(prev => {
                                  const current = { ...prev };
                                  if (option.type === 'single') {
                                    current[option.id] = [valueId];
                                  } else {
                                    if (!current[option.id]) current[option.id] = [];
                                    if (e.target.checked) {
                                      current[option.id].push(valueId);
                                    } else {
                                      current[option.id] = current[option.id].filter(id => id !== valueId);
                                    }
                                  }
                                  return current;
                                });
                              }}
                              className="w-4 h-4 text-amber-500 focus:ring-amber-300 border-gray-300"
                            />
                            <span className="text-sm text-gray-900">{value.name}</span>
                          </div>
                          {value.price_delta !== 0 && (
                            <span className="text-sm font-medium text-amber-800">
                              +Rp {value.price_delta.toLocaleString('id-ID')}
                            </span>
                          )}
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
              </div>

              <div className="flex-shrink-0 border-t border-gray-200 p-4">
                {serialError && <p className="mb-3 text-sm text-red-700">{serialError}</p>}
                <div className="flex gap-3">
                  <button
                    onClick={() => {
                      setShowAddonModal(false);
                      setSelectedProductForAddon(null);
                      setSelectedAddons({});
                    }}
                    className="flex-1 px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                  >
                    Batal
                  </button>
                  <button
                    onClick={() => {
                      if (selectedProductForAddon) {
                        if (configuredRequiresVariant && !configuredVariant) {
                          setSerialError('Pilih varian produk terlebih dahulu.');
                          return;
                        }
                        if (configuredRequiresSerial && (serialLoading || serialError || selectedSerials.length === 0)) {
                          setSerialError(serialLoading ? 'Tunggu sampai daftar serial selesai dimuat.' : serialError || 'Pilih minimal satu IMEI/serial.');
                          return;
                        }
                        const requiredOption = selectedProductForAddon.options.find((option) => option.is_active && option.is_required && !(selectedAddons[option.id]?.length));
                        if (requiredOption) {
                          setSerialError(`Pilih ${requiredOption.name} terlebih dahulu.`);
                          return;
                        }
                        if (configuredVariant?.track_stock && !configuredRequiresSerial && configuredQty > (configuredVariant.stock ?? 0)) {
                          setSerialError(`Stok varian hanya ${configuredVariant.stock ?? 0} unit.`);
                          return;
                        }
                        if (!configuredVariant && selectedProductForAddon.track_stock && !configuredRequiresSerial && configuredQty > (selectedProductForAddon.stock ?? 0)) {
                          setSerialError(`Stok produk hanya ${selectedProductForAddon.stock ?? 0} unit.`);
                          return;
                        }
                        setSerialError('');
                        // Convert selected addons to the format expected by addToCart
                        const addons: SelectedAddon[] = [];
                        Object.entries(selectedAddons).forEach(([optionId, valueIds]) => {
                          const option = selectedProductForAddon.options.find(opt => opt.id === parseInt(optionId));
                          if (option) {
                            valueIds.forEach(valueId => {
                              const value = option.values.find(val => val.id === valueId);
                              if (value) {
                                addons.push({
                                  option_id: option.id,
                                  value_id: value.id,
                                  option_name: option.name,
                                  value_name: value.name,
                                  price_delta: value.price_delta,
                                });
                              }
                            });
                          }
                        });

                        addToCart(
                          selectedProductForAddon,
                          addons,
                          configuredVariant?.id ?? null,
                          configuredRequiresSerial ? selectedSerials : [],
                          customerType,
                          configuredRequiresSerial ? selectedSerials.length : configuredQty,
                        );
                        setShowAddonModal(false);
                        setSelectedProductForAddon(null);
                        setSelectedAddons({});
                        setSelectedSerials([]);
                        setSerialError('');
                      }
                    }}
                    className="flex-1 px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors"
                  >
                    Tambah ke keranjang
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Modal Daftar Member Baru */}
        {showRegisterMember && (
          <div className="fixed inset-0 bg-black/40 z-[60] flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl p-6 w-full max-w-sm">
              <h3 className="font-bold text-lg text-gray-900 mb-4 flex items-center gap-2">
                <User className="w-5 h-5" />
                Daftarkan Member Baru
              </h3>

              <div className="space-y-3">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Nama Lengkap *
                  </label>
                  <input
                    type="text"
                    value={newMemberName}
                    onChange={e => setNewMemberName(e.target.value)}
                    placeholder="misalnya: Budi Santoso"
                    className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Nomor HP
                  </label>
                  <input
                    type="tel"
                    value={newMemberPhone}
                    onChange={e => setNewMemberPhone(e.target.value)}
                    placeholder="misalnya: 08123456789"
                    className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Email (opsional)
                  </label>
                  <input
                    type="email"
                    value={newMemberEmail}
                    onChange={e => setNewMemberEmail(e.target.value)}
                    placeholder="misalnya: budi@email.com"
                    className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  />
                </div>
              </div>

              <div className="flex gap-3 mt-5">
                <button
                  onClick={handleRegisterMember}
                  disabled={!newMemberName.trim()}
                  className="flex-1 py-2.5 bg-blue-600 text-white rounded-xl font-semibold disabled:opacity-50 transition-colors"
                >
                  Daftarkan
                </button>
                <button
                  onClick={() => setShowRegisterMember(false)}
                  className="flex-1 py-2.5 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition-colors"
                >
                  Batal
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
