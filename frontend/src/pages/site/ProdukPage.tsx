import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ShoppingCart } from 'lucide-react';
import { getImageUrl, getSessionUser, getToken } from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';
import PageHero, { usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';
import useSiteData, { formatProductPrice, type SiteProduct } from '@/components/site/useSiteData';

export default function ProdukPage() {
  const { products, content, loaded } = useSiteData();
  const [category, setCategory] = useState('');
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  const section = content.products || {};
  usePageMeta(
    'Produk Digital',
    'Template, ekstensi, dan produk digital siap pakai dari Hellom. Sekali beli, akses selamanya.',
    '/produk'
  );

  const categories = useMemo(
    () => Array.from(new Set(products.map((p) => p.category).filter((c): c is string => Boolean(c)))),
    [products]
  );
  const visible = category ? products.filter((p) => p.category === category) : products;

  // Same destinations as before: logged-in buyers use the dashboard checkout,
  // guests use the no-login checkout for paid products, free products need an account.
  const productLink = (product: SiteProduct) => {
    const detailUrl = `/dashboard/products/${product.slug}/checkout`;
    const isFree = product.type === 'free' || Number(product.price || 0) <= 0;
    return {
      to: isAuthenticated ? detailUrl : isFree ? '/login' : `/produk/${product.slug}/checkout`,
      isFree,
      onClick: () => {
        if (!isAuthenticated && isFree) {
          savePendingCheckoutIntent({ kind: 'digital_product', product_id: product.id, product_slug: product.slug, return_to: detailUrl });
        }
      },
    };
  };

  return (
    <>
      <PageHero
        eyebrow="Produk"
        lines={section.heading
          ? [section.heading]
          : ['Produk digital premium', <span key="h" className="text-[#F6B400]">untuk hasil maksimal.</span>]}
        intro={section.description || 'Template, ekstensi, dan produk digital siap pakai. Sekali beli, akses selamanya — bisa langsung checkout tanpa daftar.'}
      />

      <section className="px-5 py-16 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          {categories.length > 1 ? (
            <div role="group" aria-label="Filter kategori produk" className="scrollbar-hide -mx-5 mb-10 flex gap-2 overflow-x-auto px-5">
              {[{ key: '', label: 'Semua' }, ...categories.map((c) => ({ key: c, label: c }))].map((chip) => (
                <button
                  key={chip.key || 'semua'}
                  type="button"
                  aria-pressed={category === chip.key}
                  onClick={() => setCategory(chip.key)}
                  className={`h-11 shrink-0 rounded-full px-5 text-sm font-semibold transition-colors ${category === chip.key ? 'bg-[#F6B400] text-black' : 'border border-white/[0.12] text-[#D6D6D8] hover:border-white/30'}`}
                >
                  {chip.label}
                </button>
              ))}
            </div>
          ) : null}

          {!loaded ? (
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" aria-busy="true">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="aspect-[0.78] rounded-2xl border border-white/[0.06] bg-white/[0.03]" />
              ))}
            </div>
          ) : visible.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-white/[0.12] p-10 text-center text-[#A1A1A6]">
              Produk akan tampil di sini setelah katalog diisi.
            </p>
          ) : (
            <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
              {visible.map((product, index) => {
                const link = productLink(product);
                return (
                  <Reveal as="li" key={product.id} delay={(index % 4) * 0.06}>
                    <Link
                      to={link.to}
                      onClick={link.onClick}
                      className="group block h-full overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] transition-colors hover:border-[#F6B400]/45"
                    >
                      <div className="aspect-[1.15] overflow-hidden bg-[#0E0E11]">
                        {product.thumbnail_url ? (
                          <img
                            src={getImageUrl(product.thumbnail_url)}
                            alt={product.name}
                            width={460}
                            height={400}
                            loading="lazy"
                            decoding="async"
                            className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                          />
                        ) : (
                          <div className="h-full w-full bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.18),transparent_35%),#111]" />
                        )}
                      </div>
                      <div className="p-5">
                        <p className="text-[11px] uppercase tracking-[0.25em] text-[#F6B400]">{product.category || 'Produk digital'}</p>
                        <h2 className="mt-3 font-display text-lg">{product.name}</h2>
                        {product.tagline ? <p className="mt-2 line-clamp-2 text-sm leading-6 text-[#A1A1A6]">{product.tagline}</p> : null}
                        <div className="mt-5 flex items-center justify-between gap-3">
                          <span className="text-sm font-semibold text-[#F6B400]">{formatProductPrice(product)}</span>
                          <span className="inline-flex min-h-11 items-center gap-2 text-xs font-bold">
                            {link.isFree ? 'Aktifkan gratis' : 'Beli sekarang'}
                            <ShoppingCart aria-hidden className="h-4 w-4 text-[#F6B400]" />
                          </span>
                        </div>
                      </div>
                    </Link>
                  </Reveal>
                );
              })}
            </ul>
          )}
        </div>
      </section>
    </>
  );
}
