import { ArrowRight, LayoutTemplate } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { getImageUrl, getPublicProducts, getSessionUser, getToken } from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';

const tabs = ['Semua', 'Berlangganan', 'Sekali Beli', 'Gratis'] as const;

type PublicProduct = {
  id: number;
  slug: string;
  name: string;
  tagline?: string | null;
  category: string;
  type: string;
  price: number;
  thumbnail_url?: string | null;
};

const resolveTypeLabel = (type: string) => {
  if (type === 'free') return 'Gratis';
  if (type === 'subscription_locked') return 'Berlangganan';
  return 'Sekali Beli';
};

export const SaasSection = () => {
  const [activeTab, setActiveTab] = useState<(typeof tabs)[number]>('Semua');
  const [products, setProducts] = useState<PublicProduct[]>([]);
  const isAuthenticated = Boolean(getToken() && getSessionUser());

  useEffect(() => {
    const load = async () => {
      try {
        const items = await getPublicProducts();
        setProducts(items as PublicProduct[]);
      } catch {
        setProducts([]);
      }
    };

    void load();
  }, []);

  const visibleProducts = useMemo(() => {
    if (activeTab === 'Semua') return products;
    return products.filter((product) => resolveTypeLabel(product.type) === activeTab);
  }, [activeTab, products]);

  return (
    <section className="border-y border-white/5 bg-zinc-900/30 py-12 sm:py-24" id="produk">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-8 flex flex-col gap-4 md:mb-12 md:flex-row md:items-end md:justify-between">
          <div>
            <div className="mb-3 inline-flex rounded-lg border border-yellow-400/20 bg-yellow-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-yellow-400 sm:mb-4">
              Produk Unggulan
            </div>
            <h2 className="text-2xl font-bold tracking-tight text-white sm:text-4xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
              Katalog yang langsung menjelaskan value
            </h2>
          </div>

          <div className="flex w-full items-center gap-4 overflow-x-auto pb-2 md:w-auto md:pb-0">
            <div className="flex flex-shrink-0 gap-1 rounded-xl border border-white/5 bg-zinc-800/50 p-1">
              {tabs.map((tab) => (
                <button
                  key={tab}
                  type="button"
                  onClick={() => setActiveTab(tab)}
                  className={`whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold transition-all sm:px-4 sm:text-sm ${
                    activeTab === tab ? 'bg-yellow-400 text-black' : 'text-zinc-400 hover:bg-white/5 hover:text-white'
                  }`}
                >
                  {tab}
                </button>
              ))}
            </div>
            <Link
              to={isAuthenticated ? '/dashboard/products' : '/login'}
              onClick={() => {
                if (!isAuthenticated) {
                  localStorage.setItem('hellom_intended_url', '/dashboard/products');
                }
              }}
              className="hidden whitespace-nowrap text-sm font-semibold text-yellow-400 transition-colors hover:text-yellow-300 sm:inline"
            >
              Lihat semua -&gt;
            </Link>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-3">
          {visibleProducts.map((product) => {
            const typeLabel = resolveTypeLabel(product.type);
            const isFree = product.type === 'free' || product.price === 0;
            const priceLabel = isFree ? 'Gratis' : `Rp ${Number(product.price || 0).toLocaleString('id-ID')}`;
            const thumbnail = getImageUrl(product.thumbnail_url || '');
            const detailUrl = `/dashboard/products/${product.slug}/checkout`;
            const href = isAuthenticated ? detailUrl : '/login';
            const cta = isFree ? 'Mulai Sekarang' : 'Beli Sekarang';

            return (
            <article
              key={product.id}
              className="group overflow-hidden rounded-xl border border-white/5 bg-black transition-all duration-300 hover:-translate-y-1 hover:border-yellow-400/20 sm:rounded-2xl"
            >
              <div className="relative flex h-28 items-center justify-center overflow-hidden border-b border-white/5 bg-gradient-to-br from-zinc-900 to-zinc-950 sm:h-40">
                {thumbnail ? (
                  <img
                    src={thumbnail}
                    alt={product.name}
                    className="absolute inset-0 h-full w-full object-cover opacity-60"
                  />
                ) : null}
                <LayoutTemplate className="absolute h-20 w-20 text-white/5 blur-[1px] sm:h-28 sm:w-28" />
                <div className="relative flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-yellow-400 sm:h-20 sm:w-20">
                  <LayoutTemplate className="h-7 w-7 sm:h-10 sm:w-10" />
                </div>
              </div>

              <div className="space-y-3 p-3 sm:space-y-4 sm:p-6">
                <div className="flex flex-wrap gap-1.5 sm:gap-2">
                  <span className="rounded-md border px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wider sm:rounded-lg sm:px-3 sm:py-1 sm:text-xs bg-yellow-400/10 border-yellow-400/20 text-yellow-400">
                    {typeLabel}
                  </span>
                  <span className="rounded-md border px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wider sm:rounded-lg sm:px-3 sm:py-1 sm:text-xs bg-white/5 border-white/10 text-zinc-300">
                    {product.category}
                  </span>
                </div>

                <div className="space-y-1 sm:space-y-2">
                  <h3 className="line-clamp-2 text-xs font-bold leading-tight text-white sm:text-lg" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                    {product.name}
                  </h3>
                  <p className="hidden text-sm leading-relaxed text-zinc-400 sm:block">{product.tagline || 'Produk digital premium untuk bisnis.'}</p>
                </div>

                <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-between sm:gap-0 sm:pt-2">
                  <div>
                    <div className="flex items-baseline gap-1">
                      <span
                        className={`text-sm font-bold sm:text-xl ${isFree ? 'text-blue-400' : 'text-yellow-400'}`}
                        style={{ fontFamily: 'Space Grotesk, sans-serif' }}
                      >
                        {priceLabel}
                      </span>
                    </div>
                  </div>

                  <Link
                    to={href}
                    onClick={() => {
                      if (!isAuthenticated) {
                        savePendingCheckoutIntent({
                          kind: 'digital_product',
                          product_id: product.id,
                          product_slug: product.slug,
                          return_to: detailUrl,
                        });
                      }
                    }}
                    className={`group/btn inline-flex w-full items-center justify-center gap-1 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold transition-all sm:w-auto sm:px-4 sm:py-2.5 sm:text-sm ${
                      isFree
                        ? 'border border-yellow-400/20 bg-white/5 text-yellow-400 hover:border-yellow-400/40 hover:bg-white/10'
                        : 'bg-yellow-400 text-black hover:bg-yellow-300'
                    }`}
                  >
                    <span className="hidden sm:inline">{cta}</span>
                    <span className="sm:hidden">{isFree ? 'Mulai' : 'Beli'}</span>
                    <ArrowRight className="h-3 w-3 transition-transform group-hover/btn:translate-x-1 sm:h-4 sm:w-4" />
                  </Link>
                </div>
              </div>
            </article>
          );
          })}
        </div>
      </div>
    </section>
  );
};
