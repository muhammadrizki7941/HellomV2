import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle, Download, Lock, ShoppingBag } from 'lucide-react';
import { getConsumerProducts, getImageUrl, purchaseProduct } from '@/lib/hellomApi';

type Product = {
  id: number;
  slug: string;
  name: string;
  tagline?: string | null;
  category: string;
  type: string;
  price: number;
  thumbnail_url?: string | null;
  tech_stack?: string[] | null;
  tags?: string[] | null;
  is_purchased?: boolean;
  purchase?: { payment_status?: string } | null;
};

const categoryFilters = [
  { label: 'Semua', value: 'all' },
  { label: 'Source Code', value: 'source_code' },
  { label: 'Aplikasi', value: 'application' },
  { label: 'Template', value: 'template' },
  { label: 'Ebook', value: 'ebook' },
  { label: 'Kursus', value: 'course' },
];

const formatPrice = (price: number) => `Rp ${price.toLocaleString('id-ID')}`;

export default function ConsumerProductCatalog() {
  const navigate = useNavigate();
  const [products, setProducts] = useState<Product[]>([]);
  const [activeFilter, setActiveFilter] = useState('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadProducts = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await getConsumerProducts();
      setProducts((response || []) as Product[]);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat katalog produk');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadProducts();
  }, []);

  const filteredProducts = useMemo(() => {
    if (activeFilter === 'all') return products;
    return products.filter((product) => product.category === activeFilter);
  }, [activeFilter, products]);

  const handlePurchase = async (product: Product) => {
    if (product.is_purchased) {
      navigate(`/dashboard/products/${product.slug}`);
      return;
    }

    if (product.type === 'free') {
      try {
        await purchaseProduct(product.id);
        await loadProducts();
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Gagal mengaktifkan produk');
      }
      return;
    }

    navigate(`/dashboard/products/${product.slug}`);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Katalog Produk</h1>
          <p className="text-sm text-zinc-600">Temukan produk digital terbaik untuk bisnis kamu.</p>
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {categoryFilters.map((filter) => (
          <button
            key={filter.value}
            onClick={() => setActiveFilter(filter.value)}
            className={`px-4 py-2 rounded-full text-xs font-semibold border transition ${
              activeFilter === filter.value
                ? 'bg-zinc-900 text-white border-zinc-900'
                : 'bg-white text-zinc-600 border-zinc-200 hover:border-zinc-300'
            }`}
          >
            {filter.label}
          </button>
        ))}
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {error}
        </div>
      )}

      {loading ? (
        <div className="text-sm text-zinc-500">Memuat katalog...</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          {filteredProducts.map((product) => {
            const isFree = product.type === 'free' || product.price === 0;
            const isPurchased = Boolean(product.is_purchased);
            const thumbnail = getImageUrl(product.thumbnail_url || '');

            return (
              <div key={product.id} className="bg-white border border-zinc-200 rounded-2xl p-5 shadow-sm">
                <div className="relative">
                  {thumbnail ? (
                    <img
                      src={thumbnail}
                      alt={product.name}
                      className="w-full h-40 object-cover rounded-xl border border-zinc-100"
                      loading="lazy"
                    />
                  ) : (
                    <div className="w-full h-40 rounded-xl border border-zinc-100 bg-gradient-to-br from-zinc-100 to-zinc-50" />
                  )}
                  {isPurchased && (
                    <span className="absolute top-3 right-3 bg-emerald-600 text-white text-xs px-2 py-1 rounded-full inline-flex items-center gap-1">
                      <CheckCircle className="w-3 h-3" /> Dimiliki
                    </span>
                  )}
                </div>

                <div className="mt-4">
                  <span className="text-xs uppercase tracking-wide text-zinc-500">{product.category}</span>
                  <h3 className="text-lg font-semibold text-zinc-900 mt-1">{product.name}</h3>
                  <p className="text-sm text-zinc-600 mt-1">{product.tagline || '-'}</p>

                  <div className="flex flex-wrap gap-2 mt-3">
                    {(product.tech_stack || []).slice(0, 3).map((tag) => (
                      <span key={tag} className="text-xs bg-zinc-100 text-zinc-600 px-2 py-1 rounded-full">{tag}</span>
                    ))}
                    {(product.tech_stack || []).length > 3 && (
                      <span className="text-xs bg-zinc-100 text-zinc-600 px-2 py-1 rounded-full">
                        +{(product.tech_stack || []).length - 3} lagi
                      </span>
                    )}
                  </div>

                  <div className="mt-4 flex items-center justify-between">
                    <span className={`text-sm font-semibold ${isFree ? 'text-emerald-600' : 'text-zinc-900'}`}>
                      {isFree ? 'GRATIS' : formatPrice(product.price)}
                    </span>
                  </div>

                  <button
                    onClick={() => void handlePurchase(product)}
                    disabled={product.type === 'subscription_locked'}
                    className={`mt-4 w-full inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition ${
                      product.type === 'subscription_locked'
                        ? 'bg-zinc-100 text-zinc-400 cursor-not-allowed'
                        : isPurchased
                          ? 'bg-emerald-600 text-white'
                          : 'bg-zinc-900 text-white hover:bg-zinc-800'
                    }`}
                  >
                    {isPurchased ? (
                      <>
                        <Download className="w-4 h-4" /> Download / Lihat
                      </>
                    ) : product.type === 'subscription_locked' ? (
                      <>
                        <Lock className="w-4 h-4" /> Butuh Langganan
                      </>
                    ) : isFree ? (
                      <>
                        <CheckCircle className="w-4 h-4" /> Aktifkan Gratis
                      </>
                    ) : (
                      <>
                        <ShoppingBag className="w-4 h-4" /> Beli Sekarang
                      </>
                    )}
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
