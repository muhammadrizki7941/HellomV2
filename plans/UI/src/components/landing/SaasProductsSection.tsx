import { useEffect, useState } from 'react';
import { getImageUrl, getPublicProducts } from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';
import { SectionLabel } from './SectionLabel';

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

export const SaasProductsSection = () => {
  const [products, setProducts] = useState<PublicProduct[]>([]);

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

  const handleLockedClick = (product: PublicProduct, isFree: boolean) => {
    if (typeof window === 'undefined') return;
    const returnTo = `/dashboard/products/${product.slug}/checkout`;
    savePendingCheckoutIntent({
      kind: 'digital_product',
      product_id: product.id,
      product_slug: product.slug,
      return_to: returnTo,
      ...(isFree ? {} : { payment_flow: undefined }),
    });
  };

  return (
    <section className="py-20 px-6">
      <div className="max-w-7xl mx-auto">
        <SectionLabel>produk</SectionLabel>
        <h2 className="font-display text-3xl md:text-4xl font-bold text-brand-text mb-12">
          Yang paling sering
          <br />
          <span className="text-brand-accent">dibutuhin.</span>
        </h2>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {products.map((product) => {
            const isFree = product.type === 'free' || product.price === 0;
            const priceLabel = isFree ? 'GRATIS' : `Rp ${Number(product.price || 0).toLocaleString('id-ID')}`;
            const thumbnail = getImageUrl(product.thumbnail_url || '');

            return (
              <div
                key={product.id}
                className="bg-brand-bg2 border border-white/[0.08] rounded-2xl p-8 hover:border-brand-accent/30 transition relative overflow-hidden"
              >
                {!isFree && (
                  <div className="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 hover:opacity-100 transition">
                    <span className="text-xs font-bold text-white bg-black/60 px-3 py-2 rounded-full">
                      Login untuk akses
                    </span>
                  </div>
                )}
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold px-3 py-1 rounded-full bg-brand-accent/10 text-brand-accent">
                    {product.category}
                  </span>
                  <span className="text-xs font-bold text-brand-text">{priceLabel}</span>
                </div>
                <h3 className="font-display text-2xl font-bold text-brand-text mt-4 mb-3">
                  {product.name}
                </h3>
                <p className="text-brand-muted mb-6">{product.tagline || 'Produk digital premium untuk bisnis.'}</p>
                {thumbnail ? (
                  <img src={thumbnail} alt={product.name} className="w-full h-36 object-cover rounded-xl mb-5" />
                ) : (
                  <div className="w-full h-36 rounded-xl mb-5 bg-white/5" />
                )}
                <a
                  href="/register"
                  onClick={() => handleLockedClick(product, isFree)}
                  className="text-brand-accent hover:underline font-semibold"
                >
                  {isFree ? 'Coba Gratis' : 'Beli Sekarang'}
                </a>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
};
