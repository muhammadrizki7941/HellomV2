import { useState, useEffect } from 'react';
import { X, Plus, Minus, ShoppingCart, User, Star, Gift } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getPosTables, getPosProducts, getPosCategories, createPosOrder, searchPosMembers, createPosMember, calculateLoyaltyPoints, applyReward } from '@/lib/hellomApi';
import { getImageUrl } from '@/lib/hellomApi';

type Table = {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
};

type Product = {
  id: number;
  name: string;
  description: string;
  price: number;
  image_path: string;
  is_available: boolean;
  track_stock: boolean;
  stock: number | null;
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
};

interface NewOrderModalProps {
  isOpen: boolean;
  onClose: () => void;
  onOrderCreated: () => void;
}

export default function NewOrderModal({ isOpen, onClose, onOrderCreated }: NewOrderModalProps) {
  const [activeTab, setActiveTab] = useState<'menu' | 'cart'>('menu');
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
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [priceSort, setPriceSort] = useState<'none' | 'asc' | 'desc'>('none');
  const [notification, setNotification] = useState<{ message: string; visible: boolean } | null>(null);
  const [showAddonModal, setShowAddonModal] = useState(false);
  const [selectedProductForAddon, setSelectedProductForAddon] = useState<Product | null>(null);
  const [selectedAddons, setSelectedAddons] = useState<Record<number, number[]>>({});

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

  useEffect(() => {
    if (isOpen) {
      loadData();
      resetForm();
    }
  }, [isOpen]);

  const loadData = async () => {
    try {
      setLoading(true);
      const [tablesRes, productsRes, categoriesRes] = await Promise.all([
        getPosTables(),
        getPosProducts(),
        getPosCategories(),
      ]);

      setTables(tablesRes.tables || []);
      setProducts(productsRes.products || []);
      setCategories(categoriesRes.categories || []);
    } catch (err) {
      setError('Failed to load data');
    } finally {
      setLoading(false);
    }
  };

  const resetForm = () => {
    setActiveTab('menu');
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
    // Reset member states
    setMemberQuery('');
    setMemberResults([]);
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
    const hasOptions = product.options && product.options.length > 0 && product.options.some(opt => opt.is_active);

    if (hasOptions) {
      // Show add-on modal
      setSelectedProductForAddon(product);
      setSelectedAddons({});
      setShowAddonModal(true);
    } else {
      // Add directly to cart
      addToCart(product, []);
    }
  };

  const addToCart = (product: Product, addons: SelectedAddon[]) => {
    setCart(prev => {
      const existing = prev.find(item =>
        item.product.id === product.id &&
        JSON.stringify(item.addons) === JSON.stringify(addons)
      );

      let newQuantity = 1;
      let message = '';

      if (existing) {
        newQuantity = existing.quantity + 1;
        message = `${product.name} quantity updated to ${newQuantity}`;
      } else {
        message = `${product.name} added to cart`;
      }

      const addonPrice = addons.reduce((sum, addon) => sum + addon.price_delta, 0);
      const totalPrice = (product.price + addonPrice) * newQuantity;

      const newCart = existing
        ? prev.map(item =>
            (item.product.id === product.id && JSON.stringify(item.addons) === JSON.stringify(addons))
              ? { ...item, quantity: newQuantity, totalPrice }
              : item
          )
        : [...prev, { product, quantity: 1, addons, totalPrice: product.price + addonPrice }];

      // Show notification
      setNotification({ message, visible: true });
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

  const updateQuantity = (productId: number, quantity: number, addons?: SelectedAddon[]) => {
    if (quantity <= 0) {
      const item = cart.find(item =>
        item.product.id === productId &&
        (!addons || JSON.stringify(item.addons) === JSON.stringify(addons))
      );
      if (item) {
        setNotification({ message: `${item.product.name} removed from cart`, visible: true });
        setTimeout(() => setNotification(null), 2000);
      }
      setCart(prev => prev.filter(item =>
        !(item.product.id === productId &&
          (!addons || JSON.stringify(item.addons) === JSON.stringify(addons)))
      ));
    } else {
      setCart(prev => prev.map(item => {
        if (item.product.id === productId &&
            (!addons || JSON.stringify(item.addons) === JSON.stringify(addons))) {
          const addonPrice = item.addons.reduce((sum, addon) => sum + addon.price_delta, 0);
          const totalPrice = (item.product.price + addonPrice) * quantity;
          return { ...item, quantity, totalPrice };
        }
        return item;
      }));
    }
  };

  const isProductAvailable = (product: Product) =>
    product.is_available && (!product.track_stock || (product.stock ?? 0) > 0);
  const getStockLabel = (product: Product) => {
    if (!isProductAvailable(product)) return 'Habis';
    if (product.track_stock) return `Sisa ${product.stock ?? 0}`;
    return 'Unlimited';
  };
  const getStockBadgeClass = (product: Product) => {
    if (!isProductAvailable(product)) return 'bg-red-100 text-red-700 border-red-200';
    if (product.track_stock) return 'bg-emerald-100 text-emerald-700 border-emerald-200';
    return 'bg-slate-100 text-slate-600 border-slate-200';
  };

  const filteredProducts = (() => {
    let filtered = products.filter(product => {
      // Category filter
      const categoryMatch = !selectedCategory || product.category?.id === selectedCategory;

      // Search filter
      const searchMatch = !searchQuery ||
        product.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        product.description?.toLowerCase().includes(searchQuery.toLowerCase());

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

  const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
  const totalPrice = cart.reduce((sum, item) => sum + item.totalPrice, 0);
  const finalAmount = selectedReward ? Math.max(0, totalPrice - discountAmount) : totalPrice;

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

  // Member search with debounce
  const searchMember = async (query: string) => {
    setMemberQuery(query);
    if (query.length < 2) {
      setMemberResults([]);
      return;
    }
    setIsSearching(true);
    try {
      const res = await searchPosMembers(query);
      setMemberResults(res.members || []);
    } catch (err) {
      console.error('Failed to search members:', err);
      setMemberResults([]);
    } finally {
      setIsSearching(false);
    }
  };

  // Select member and calculate loyalty
  const selectMember = async (member: any) => {
    setSelectedMember(member);
    setMemberQuery(member.name);
    setMemberResults([]);
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

    try {
      setLoading(true);
      const payload = {
        ...(selectedTableId ? { table_id: selectedTableId } : {}),
        customer_name: selectedMember?.name || customerName || undefined,
        customer_phone: selectedMember?.phone || customerPhone || undefined,
        member_id: selectedMember?.id || undefined,
        reward_rule_id: selectedReward?.id || undefined,
        discount_amount: discountAmount || 0,
        final_amount: finalAmount,
        service_type: (selectedTableId ? 'dine_in' : 'takeaway') as 'dine_in' | 'takeaway',
        notes: notes || undefined,
        items: cart.map(item => ({
          product_id: item.product.id,
          quantity: item.quantity,
          options: item.addons.map(addon => ({
            option_id: addon.option_id,
            value_id: addon.value_id,
          })),
        })),
      };

      console.log('Sending order payload:', JSON.stringify(payload, null, 2));

      await createPosOrder(payload);

      onOrderCreated();
      onClose();
    } catch (err) {
      setError('Failed to create order');
    } finally {
      setLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-white md:items-center md:justify-center md:bg-white/20 md:backdrop-blur-md">
      <div className="flex flex-col h-full w-full md:h-[90vh] md:w-[90vw] md:max-w-6xl md:rounded-2xl md:overflow-hidden bg-white">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-gray-200 md:p-6">
          <div className="flex items-center gap-4">
            <button
              onClick={() => setShowTableSelector(!showTableSelector)}
              className={cn(
                'px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                selectedTableId ? 'bg-amber-100 text-amber-900' : 'bg-gray-100 text-gray-700'
              )}
            >
              {selectedTableId ? `Meja ${tables.find(t => t.id === selectedTableId)?.code || selectedTableId}` : 'Dibawa Pulang'}
            </button>
            <span className="text-gray-600">•</span>
            <span className="text-sm text-gray-600">{cart.length} items</span>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 p-2"
          >
            <X className="w-6 h-6" />
          </button>
        </div>

        {/* Table Selector */}
        {showTableSelector && (
          <div className="border-b border-gray-200 p-4">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-semibold text-gray-900">Pilih Meja</h3>
              <button
                onClick={() => setShowTableSelector(false)}
                className="text-gray-400 hover:text-gray-600"
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
                <div className="text-xl mb-1">🚶</div>
                <div className="text-sm font-medium">Dibawa Pulang</div>
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
                  <div className="text-xl mb-1">🪑</div>
                  <div className="text-sm font-medium">{table.name || table.code}</div>
                  <div className="text-xs text-gray-500">{table.code}</div>
                </button>
              ))}
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Nama Pelanggan <span className="text-gray-400">(opsional)</span>
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
                className="w-full px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors text-sm"
              >
                Done
              </button>
            </div>
          </div>
        )}

        {/* Mobile Tabs */}
        <div className="flex md:hidden border-b border-gray-200">
          <button
            onClick={() => setActiveTab('menu')}
            className={cn(
              'flex-1 py-3 text-center text-sm font-medium transition-colors',
              activeTab === 'menu' ? 'text-amber-800 border-b-2 border-amber-400' : 'text-gray-600'
            )}
          >
            Select Products
          </button>
          <button
            data-cart-tab
            onClick={() => setActiveTab('cart')}
            className={cn(
              'flex-1 py-3 text-center text-sm font-medium transition-colors relative',
              activeTab === 'cart' ? 'text-amber-800 border-b-2 border-amber-400' : 'text-gray-600'
            )}
          >
            Keranjang ({cart.length})
          </button>
        </div>

        {/* Notification */}
        {notification?.visible && (
          <div className="absolute top-4 left-1/2 transform -translate-x-1/2 z-50 animate-in slide-in-from-top-2 fade-in duration-300">
            <div className="bg-green-600 text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 min-w-max border border-green-500">
              <div className="w-5 h-5 flex items-center justify-center bg-green-700 rounded-full">
                <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <span className="text-sm font-medium">{notification.message}</span>
            </div>
          </div>
        )}

        {/* Main Content */}
        <div className="flex flex-col md:flex-row flex-1 overflow-hidden">
          {/* Menu Panel */}
          <div className={cn(
            'flex-1 overflow-y-auto',
            activeTab === 'cart' ? 'hidden md:flex' : 'flex'
          )}>
            <div className="p-4">
              {/* Categories */}
              <div className="mb-4">
                <div className="flex gap-2 overflow-x-auto pb-2">
                  <button
                    onClick={() => setSelectedCategory(null)}
                    className={cn(
                      'px-3 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-colors',
                      !selectedCategory
                        ? 'bg-amber-400 text-[#111111]'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                    )}
                  >
                    Semua
                  </button>
                  {categories.map(category => (
                    <button
                      key={category.id}
                      onClick={() => setSelectedCategory(category.id)}
                      className={cn(
                        'px-3 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-colors',
                        selectedCategory === category.id
                          ? 'bg-amber-400 text-[#111111]'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                      )}
                    >
                      {category.name}
                    </button>
                  ))}
                </div>
              </div>

              {/* View Toggle */}
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setViewMode('grid')}
                    className={cn(
                      'p-2 rounded-lg transition-colors',
                      viewMode === 'grid'
                        ? 'bg-amber-100 text-amber-900'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                    )}
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                  </button>
                  <button
                    onClick={() => setViewMode('list')}
                    className={cn(
                      'p-2 rounded-lg transition-colors',
                      viewMode === 'list'
                        ? 'bg-amber-100 text-amber-900'
                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                    )}
                  >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                    </svg>
                  </button>
                </div>
                <div className="text-sm text-gray-500">
                  {filteredProducts.filter(isProductAvailable).length} products
                </div>
              </div>

              {/* Search and Filters */}
              <div className="mb-4 space-y-3">
                {/* Search */}
                <div className="relative">
                  <input
                    type="text"
                    placeholder="Cari menu..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-3 pr-10 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-sm"
                  />
                  <div className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                    🔍
                  </div>
                </div>

                {/* Price Sort */}
                <div className="flex gap-2">
                  <button
                    onClick={() => setPriceSort(priceSort === 'asc' ? 'none' : 'asc')}
                    className={cn(
                      'flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                      priceSort === 'asc'
                        ? 'bg-amber-400 text-[#111111]'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                    )}
                  >
                      💰 Termurah
                  </button>
                  <button
                    onClick={() => setPriceSort(priceSort === 'desc' ? 'none' : 'desc')}
                    className={cn(
                      'flex-1 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                      priceSort === 'desc'
                        ? 'bg-amber-400 text-[#111111]'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                    )}
                  >
                    💎 Termahal
                  </button>
                </div>
              </div>

              {/* Products Display */}
              {viewMode === 'grid' ? (
                /* Grid View */
                <div className="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 gap-1.5 overflow-hidden">
                  {filteredProducts.map(product => (
                    <div
                      key={product.id}
                      onClick={() => handleProductClick(product)}
                      className={cn(
                        "bg-white border border-gray-200 rounded-lg overflow-hidden transition-all duration-200 group select-none min-w-0 transform",
                        isProductAvailable(product)
                          ? "cursor-pointer hover:border-amber-400 hover:shadow-lg active:scale-95 active:bg-amber-50"
                          : "cursor-not-allowed opacity-60"
                      )}
                    >
                      <div className="aspect-[4/3] bg-gray-100 relative overflow-hidden">
                        {product.image_path ? (
                          <img
                            src={getImageUrl(product.image_path)}
                            alt={product.name}
                            className={cn(
                              'w-full h-full object-cover group-hover:scale-105 transition-transform duration-200',
                              !isProductAvailable(product) && 'grayscale'
                            )}
                            onError={(e) => { e.currentTarget.style.display = 'none'; }}
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-gray-400">
                            <div className="w-3 h-3 border-2 border-gray-300 rounded" />
                          </div>
                        )}
                        <div className={cn('absolute left-1 top-1 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold', getStockBadgeClass(product))}>
                          {getStockLabel(product)}
                        </div>
                        {/* Overlay + button */}
                        <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors duration-200 flex items-center justify-center">
                          <div className="w-5 h-5 bg-amber-400 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 shadow-lg">
                            <Plus className="w-2.5 h-2.5 text-white" />
                          </div>
                        </div>
                        {!isProductAvailable(product) && (
                          <div className="absolute inset-x-0 bottom-0 bg-red-500/90 text-white text-[10px] font-semibold text-center py-0.5">
                            Habis
                          </div>
                        )}
                      </div>
                      <div className="p-1">
                        <h4 className="text-gray-900 font-medium text-xs mb-0.5 line-clamp-1 leading-tight truncate">{product.name}</h4>
                        <div className="text-center">
                          <span className="text-amber-800 font-bold text-xs">
                            Rp {product.price.toLocaleString('id-ID')}
                          </span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                /* List View - Gojek Style */
                <div className="space-y-2">
                  {filteredProducts.map(product => (
                    <div
                      key={product.id}
                      onClick={() => handleProductClick(product)}
                      className={cn(
                        "bg-white border border-gray-200 rounded-lg overflow-hidden transition-all duration-200 group select-none transform",
                        isProductAvailable(product)
                          ? "cursor-pointer hover:border-amber-400 hover:shadow-md active:scale-95 active:bg-amber-50"
                          : "cursor-not-allowed opacity-60"
                      )}
                    >
                      <div className="flex items-center p-3">
                        {/* Product Image */}
                        <div className="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0 mr-3">
                          {product.image_path ? (
                            <img
                              src={getImageUrl(product.image_path)}
                              alt={product.name}
                              className={cn(
                                'w-full h-full object-cover group-hover:scale-105 transition-transform duration-200',
                                !isProductAvailable(product) && 'grayscale'
                              )}
                              onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                          ) : (
                            <div className="w-full h-full flex items-center justify-center text-gray-400">
                              <div className="w-6 h-6 border-2 border-gray-300 rounded" />
                            </div>
                          )}
                        </div>

                        {/* Product Details */}
                        <div className="flex-1 min-w-0">
                          <h4 className="text-gray-900 font-medium text-sm mb-1 line-clamp-2 leading-tight">{product.name}</h4>
                          {product.description && (
                            <p className="text-gray-600 text-xs mb-2 line-clamp-1">{product.description}</p>
                          )}
                          <div className="flex items-center justify-between">
                            <span className="text-amber-800 font-bold text-sm">
                              Rp {product.price.toLocaleString('id-ID')}
                            </span>
                            <span className={cn('text-[11px] font-semibold border px-2 py-0.5 rounded-full', getStockBadgeClass(product))}>
                              {getStockLabel(product)}
                            </span>
                            <span className="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">
                              {product.category?.name}
                            </span>
                          </div>
                        </div>

                        {/* Add Button */}
                        <div className="ml-3 flex-shrink-0">
                          <div className="w-8 h-8 bg-amber-400 rounded-full flex items-center justify-center opacity-100 group-hover:bg-amber-500 transition-colors duration-200 shadow-md">
                            <Plus className="w-4 h-4 text-white" />
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Cart Panel */}
          <div className={cn(
            'w-full md:w-80 lg:w-96 bg-gray-50 border-l border-gray-200',
            activeTab === 'menu' ? 'hidden md:flex' : 'flex'
          )}>
            <div className="flex flex-col h-full w-full">
              {/* Cart Items */}
              <div className="flex-1 overflow-y-auto p-4">
                <h4 className="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                  <ShoppingCart className="w-5 h-5" />
                  Your Order Summary
                </h4>

                {cart.length === 0 ? (
                    <div className="text-center py-8">
                      <div className="text-gray-400 mb-2">🛒</div>
                      <p className="text-gray-500 text-sm">Keranjang masih kosong nih</p>
                    </div>
                ) : (
                  <div className="space-y-3">
                    {cart.map((item, index) => (
                      <div key={`${item.product.id}-${index}`} className="p-3 bg-white rounded-lg border border-gray-200">
                        <div className="flex items-start gap-3">
                          <div className="flex-1 min-w-0">
                            <h5 className="font-medium text-gray-900 text-sm">{item.product.name}</h5>
                            <p className="text-xs text-gray-600">
                              Rp {item.product.price.toLocaleString('id-ID')}
                            </p>
                            {item.addons.length > 0 && (
                              <div className="mt-1 space-y-1">
                                {item.addons.map(addon => (
                                  <div key={addon.value_id} className="text-xs text-amber-800">
                                    + {addon.value_name} {addon.price_delta > 0 && `(+Rp ${addon.price_delta.toLocaleString('id-ID')})`}
                                  </div>
                                ))}
                              </div>
                            )}
                          </div>
                           <div className="flex items-center gap-1 flex-shrink-0">
                           <button
                             onClick={() => updateQuantity(item.product.id, item.quantity - 1, item.addons)}
                             className="w-8 h-8 flex items-center justify-center text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded transition-colors"
                           >
                             <Minus className="w-4 h-4" />
                           </button>
                           <span className="w-8 text-center text-sm font-medium text-gray-900">{item.quantity}</span>
                           <button
                             onClick={() => updateQuantity(item.product.id, item.quantity + 1, item.addons)}
                             className="w-8 h-8 flex items-center justify-center text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded transition-colors"
                           >
                             <Plus className="w-4 h-4" />
                           </button>
                           </div>
                        </div>
                        <div className="mt-2 flex justify-between items-center">
                          <span className="text-xs text-gray-500">
                            Rp {item.totalPrice.toLocaleString('id-ID')}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Cart Footer */}
              {cart.length > 0 && (
                <div className="border-t border-gray-200 p-4 space-y-4">
                  {/* Customer Information */}
                  <div className="border border-gray-200 rounded-xl p-4 mb-4">
                    <h3 className="font-semibold text-gray-800 mb-3 flex items-center gap-2">
                      <User className="w-4 h-4" />
                      Pelanggan (opsional)
                    </h3>

                    {/* Search member */}
                    {!selectedMember ? (
                      <div className="relative">
                        <input
                          type="text"
                          value={memberQuery}
                          onChange={e => searchMember(e.target.value)}
                          placeholder="Cari nama, nomor HP, atau email member..."
                          className="w-full border border-gray-300 rounded-lg px-3 py-2 text-gray-900 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                        />
                        {isSearching && (
                          <div className="absolute right-3 top-2.5 text-gray-400 text-xs">
                            Mencari...
                          </div>
                        )}

                        {/* Hasil pencarian */}
                        {memberResults.length > 0 && (
                          <div className="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-xl shadow-lg z-50 mt-1 overflow-hidden">
                            {memberResults.map(m => (
                              <button
                                key={m.id}
                                onClick={() => selectMember(m)}
                                className="w-full text-left px-4 py-3 hover:bg-blue-50 transition border-b border-gray-100 last:border-0"
                              >
                                <div className="font-medium text-gray-900">
                                  {m.name}
                                </div>
                                <div className="text-xs text-gray-500 flex gap-3 mt-0.5">
                                  {m.phone && <span>📱 {m.phone}</span>}
                                  <span>⭐ {m.total_points} poin</span>
                                  <span>🛍️ {m.total_orders}x beli</span>
                                </div>
                              </button>
                            ))}

                            {/* Tombol daftar member baru */}
                            <button
                              onClick={() => setShowRegisterMember(true)}
                              className="w-full text-left px-4 py-3 bg-blue-50 text-blue-600 text-sm font-medium"
                            >
                              + Daftarkan "{memberQuery}" sebagai member baru
                            </button>
                          </div>
                        )}

                        {/* Tidak ada hasil */}
                        {memberQuery.length >= 2 && memberResults.length === 0 && !isSearching && (
                          <div className="mt-2 text-sm text-gray-500">
                            Member tidak ditemukan.{' '}
                            <button
                              onClick={() => setShowRegisterMember(true)}
                              className="text-blue-600 underline"
                            >
                              Daftarkan sebagai member baru?
                            </button>
                          </div>
                        )}
                      </div>
                    ) : (
                      /* Member terpilih */
                      <div className="bg-blue-50 border border-blue-200 rounded-xl p-3">
                        <div className="flex items-center justify-between">
                          <div>
                            <div className="font-semibold text-gray-900 flex items-center gap-2">
                              <User className="w-4 h-4" />
                              {selectedMember.name}
                            </div>
                            <div className="text-xs text-gray-500 mt-0.5 flex gap-3">
                              {selectedMember.phone && (
                                <span>📱 {selectedMember.phone}</span>
                              )}
                              <span className="text-blue-600 font-medium">
                                ⭐ {selectedMember.total_points} poin
                              </span>
                              <span>🛍️ {selectedMember.total_orders}x beli</span>
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
                            }}
                            className="text-gray-400 hover:text-red-500 text-sm px-2 py-1 rounded"
                          >
                            ✕ Ganti
                          </button>
                        </div>

                        {/* Info poin yang akan didapat */}
                        {pointsToEarn > 0 && (
                          <div className="mt-2 text-xs text-green-600 bg-green-50 rounded-lg px-3 py-1.5">
                            ✨ This order will earn <strong>+{pointsToEarn} points</strong> for member
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

                  {/* Ringkasan harga dengan diskon */}
                  <div className="flex justify-between items-center">
                    <span className="font-semibold text-gray-900">Total Bayar</span>
                    <div className="text-right">
                      {selectedReward && discountAmount > 0 ? (
                        <div className="space-y-1">
                          <div className="text-sm text-gray-500 line-through">
                            Rp {totalPrice.toLocaleString('id-ID')}
                          </div>
                          <div className="font-bold text-gray-900 text-lg">
                            Rp {finalAmount.toLocaleString('id-ID')}
                          </div>
                          <div className="text-xs text-green-600">
                            Hemat Rp {discountAmount.toLocaleString('id-ID')}
                          </div>
                        </div>
                      ) : (
                        <span className="font-bold text-gray-900 text-lg">
                          Rp {totalPrice.toLocaleString('id-ID')}
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Customer Information */}
                  <div className="space-y-3">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Nama Pelanggan <span className="text-gray-400">(opsional)</span>
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

                  <textarea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="misalnya: gak pake kecap, tambah sambal ya..."
                    rows={2}
                    className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 text-sm focus:ring-2 focus:ring-amber-300 focus:border-amber-300 resize-none"
                  />

                  <button
                    onClick={handleCreateOrder}
                    disabled={loading}
                    className="w-full bg-[#111111] text-white py-3 px-4 rounded-lg font-medium hover:bg-[#2a241d] transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-base"
                  >
                    {loading ? 'Creating order...' : `Create Order • Rp ${totalPrice.toLocaleString('id-ID')}`}
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Error Message */}
        {error && (
          <div className="border-t border-gray-200 p-4 bg-red-50">
            <p className="text-red-800 text-sm">{error}</p>
          </div>
        )}

        {/* Add-on Selection Modal */}
        {showAddonModal && selectedProductForAddon && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-white/20 backdrop-blur-md">
            <div className="bg-white rounded-xl max-w-md w-full mx-4 max-h-[90vh] overflow-hidden">
              <div className="p-4 border-b border-gray-200">
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

              <div className="flex-1 overflow-y-auto p-4 max-h-96">
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

              <div className="border-t border-gray-200 p-4">
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

                        addToCart(selectedProductForAddon, addons);
                        setShowAddonModal(false);
                        setSelectedProductForAddon(null);
                        setSelectedAddons({});
                      }
                    }}
                    className="flex-1 px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors"
                  >
                    Add to Cart
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
