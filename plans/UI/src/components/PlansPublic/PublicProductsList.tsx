import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, ShoppingBag } from 'lucide-react';
import { getImageUrl, getPublicProducts, getSessionUser, getToken } from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';

type Product = {
  id: number;
  slug: string;
  name: string;
  tagline?: string | null;
  category: string;
  type: string;
  price?: number | null;
  thumbnail_url?: string | null;
  tech_stack?: string[] | null;
  tags?: string[] | null;
  is_published?: boolean;
};

export default function PublicProductsList() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const isAuthenticated = Boolean(getToken() && getSessionUser());

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await getPublicProducts();
      setProducts((data || []) as Product[]);
    } catch (e) {
      setError((e as Error).message || 'Gagal memuat produk');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <p className="text-xs font-bold uppercase tracking-[0.3em] text-yellow-500">Produk Digital</p>
        <h2 className="mt-3 text-3xl font-bold text-zinc-950">Semua produk yang bisa dipakai atau dibeli</h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-zinc-600">
          POS, Landing Page Builder, template, ekstensi, dan produk digital lain dari backend akan tampil di sini.
        </p>
      </div>
      {error && <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{error}</div>}
      {loading ? (
        <div className="text-sm text-zinc-500">Memuat...</div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {products.map((p) => {
            const img = getImageUrl(p.thumbnail_url || '');
            const detailUrl = `/dashboard/products/${p.slug}/checkout`;
            const isFree = p.type === 'free' || Number(p.price || 0) <= 0;

            return (
              <article key={p.id} className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                {img ? (
                  <img src={img} alt={p.name} className="h-44 w-full object-cover" />
                ) : (
                  <div className="flex h-44 w-full items-center justify-center bg-zinc-100 text-zinc-300">
                    <ShoppingBag className="h-10 w-10" />
                  </div>
                )}
                <div className="p-4">
                  <div className="flex flex-wrap gap-2">
                    <span className="rounded-full bg-yellow-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-yellow-700">{p.category}</span>
                    <span className="rounded-full bg-zinc-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-zinc-600">{isFree ? 'Gratis' : 'Berbayar'}</span>
                  </div>
                  <h3 className="mt-3 text-lg font-bold text-zinc-950">{p.name}</h3>
                  <p className="mt-1 min-h-10 text-sm leading-5 text-zinc-600">{p.tagline ?? 'Produk digital premium untuk bisnis.'}</p>
                  <div className="mt-4 flex items-center justify-between gap-3">
                    <span className="font-bold text-zinc-950">{isFree ? 'Gratis' : `Rp ${Number(p.price || 0).toLocaleString('id-ID')}`}</span>
                    <Link
                      to={isAuthenticated ? detailUrl : '/login'}
                      onClick={() => {
                        if (!isAuthenticated) {
                          savePendingCheckoutIntent({
                            kind: 'digital_product',
                            product_id: p.id,
                            product_slug: p.slug,
                            return_to: detailUrl,
                          });
                        }
                      }}
                      className="inline-flex items-center gap-2 rounded-lg bg-zinc-950 px-3 py-2 text-xs font-bold text-white hover:bg-zinc-800"
                    >
                      {isFree ? 'Aktifkan' : 'Beli'} <ArrowRight className="h-3.5 w-3.5" />
                    </Link>
                  </div>
                </div>
              </article>
            );
          })}
        </div>
      )}
    </div>
  );
}
