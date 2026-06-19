import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Clock } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import useSeo from '@/hooks/useSeo';
import { Footer } from '@/components/landing/Footer';
import { getImageUrl, getPublicInsights } from '@/lib/hellomApi';

type ArticleTeaser = {
  id: number;
  title: string;
  slug: string;
  thumbnail?: string | null;
  excerpt?: string | null;
  category?: string | null;
  author?: string | null;
  published_at?: string | null;
  read_time?: number | null;
  is_featured?: boolean;
};

const formatDate = (date?: string | null) => {
  if (!date) return '';
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(date));
};

export default function InsightsPage() {
  const { brand, logoSrc } = useBrand();
  const brandName = brand.business_name || brand.app_name || 'Hellom';

  const [items, setItems] = useState<ArticleTeaser[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [activeCategory, setActiveCategory] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);

  useSeo({
    title: `Insights & Artikel | ${brandName}`,
    description: `Kumpulan artikel, tips, dan insight seputar digital, branding, dan produktivitas dari ${brandName}.`,
    url: '/insights',
    type: 'website',
    image: brand.logo_url ? getImageUrl(brand.logo_url) : undefined,
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'Blog',
      name: `Insights ${brandName}`,
      url: typeof window !== 'undefined' ? `${window.location.origin}/insights` : '/insights',
    },
  });

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    getPublicInsights({ page, per_page: 12, category: activeCategory || undefined })
      .then((res) => {
        if (cancelled) return;
        const data = res as {
          items?: ArticleTeaser[];
          categories?: string[];
          pagination?: { current_page?: number; last_page?: number };
        };
        setItems((prev) => (page > 1 ? [...prev, ...(data.items || [])] : data.items || []));
        if (data.categories) setCategories(data.categories);
        setLastPage(data.pagination?.last_page || 1);
      })
      .catch(() => {
        if (!cancelled) setItems((prev) => (page > 1 ? prev : []));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page, activeCategory]);

  const featured = useMemo(() => items.find((item) => item.is_featured) || items[0], [items]);
  const rest = useMemo(() => items.filter((item) => item.id !== featured?.id), [items, featured]);

  const selectCategory = (category: string) => {
    setActiveCategory(category);
    setPage(1);
  };

  return (
    <div className="min-h-screen bg-[#050505] text-[#F5F5F2]">
      <header className="border-b border-white/[0.08] px-6 py-4">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4">
          <Link to="/" className="flex items-center gap-3">
            {logoSrc ? (
              <img src={logoSrc} alt={brandName} className="h-8 w-auto object-contain" />
            ) : (
              <span className="text-lg font-bold tracking-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Hell<span className="text-[#F6B400]">OM</span>
              </span>
            )}
          </Link>
          <Link
            to="/"
            className="inline-flex items-center gap-2 rounded-lg border border-white/10 px-3 py-2 text-sm font-semibold text-zinc-300 hover:bg-white/5"
          >
            <ArrowLeft className="h-4 w-4" />
            Kembali ke Beranda
          </Link>
        </div>
      </header>

      <main className="px-6 py-14">
        <div className="mx-auto max-w-6xl">
          <p className="text-[10px] font-bold uppercase tracking-[0.46em] text-[#F6B400]">Insights</p>
          <h1 className="mt-4 max-w-3xl font-display text-4xl font-medium leading-tight md:text-5xl">
            Berbagi <span className="font-serif italic text-[#F6B400]">insight</span> seputar digital, branding, dan produktivitas.
          </h1>

          {categories.length > 0 ? (
            <div className="mt-8 flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => selectCategory('')}
                className={`rounded-full border px-4 py-1.5 text-sm font-semibold transition ${
                  activeCategory === '' ? 'border-[#F6B400] bg-[#F6B400] text-black' : 'border-white/10 text-zinc-300 hover:bg-white/5'
                }`}
              >
                Semua
              </button>
              {categories.map((category) => (
                <button
                  key={category}
                  type="button"
                  onClick={() => selectCategory(category)}
                  className={`rounded-full border px-4 py-1.5 text-sm font-semibold transition ${
                    activeCategory === category ? 'border-[#F6B400] bg-[#F6B400] text-black' : 'border-white/10 text-zinc-300 hover:bg-white/5'
                  }`}
                >
                  {category}
                </button>
              ))}
            </div>
          ) : null}

          {loading && items.length === 0 ? (
            <p className="mt-12 text-sm text-zinc-500">Memuat artikel...</p>
          ) : items.length === 0 ? (
            <p className="mt-12 text-sm text-zinc-500">Belum ada artikel yang dipublikasikan.</p>
          ) : (
            <>
              {featured ? (
                <Link
                  to={`/insights/${featured.slug}`}
                  className="mt-10 grid gap-6 overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] transition hover:border-[#F6B400]/40 md:grid-cols-2"
                >
                  <div className="aspect-[16/10] w-full overflow-hidden md:aspect-auto">
                    {featured.thumbnail ? (
                      <img src={getImageUrl(featured.thumbnail)} alt={featured.title} className="h-full w-full object-cover" />
                    ) : (
                      <div className="h-full w-full min-h-[220px] bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.24),transparent_38%),#111]" />
                    )}
                  </div>
                  <div className="flex flex-col justify-center p-7">
                    {featured.category ? <span className="text-xs font-semibold uppercase tracking-wide text-[#F6B400]">{featured.category}</span> : null}
                    <h2 className="mt-3 font-display text-2xl font-medium leading-tight md:text-3xl">{featured.title}</h2>
                    {featured.excerpt ? <p className="mt-3 text-sm leading-relaxed text-[#B6B6B8]">{featured.excerpt}</p> : null}
                    <div className="mt-5 flex items-center gap-4 text-xs text-[#8B8B90]">
                      <span>{formatDate(featured.published_at)}</span>
                      <span className="inline-flex items-center gap-1"><Clock className="h-3.5 w-3.5" /> {featured.read_time || 5} min read</span>
                    </div>
                  </div>
                </Link>
              ) : null}

              <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {rest.map((article) => (
                  <Link
                    key={article.id}
                    to={`/insights/${article.slug}`}
                    className="group flex flex-col overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] transition hover:border-[#F6B400]/40"
                  >
                    <div className="aspect-[16/10] w-full overflow-hidden">
                      {article.thumbnail ? (
                        <img src={getImageUrl(article.thumbnail)} alt={article.title} className="h-full w-full object-cover transition group-hover:scale-[1.03]" />
                      ) : (
                        <div className="h-full w-full bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.2),transparent_38%),#111]" />
                      )}
                    </div>
                    <div className="flex flex-1 flex-col p-5">
                      {article.category ? <span className="text-xs font-semibold uppercase tracking-wide text-[#F6B400]">{article.category}</span> : null}
                      <h3 className="mt-2 text-base font-semibold leading-6 text-white">{article.title}</h3>
                      {article.excerpt ? <p className="mt-2 line-clamp-3 text-sm text-[#8B8B90]">{article.excerpt}</p> : null}
                      <div className="mt-4 flex items-center gap-3 text-xs text-[#8B8B90]">
                        <span>{formatDate(article.published_at)}</span>
                        <span className="inline-flex items-center gap-1"><Clock className="h-3.5 w-3.5" /> {article.read_time || 5} min</span>
                      </div>
                    </div>
                  </Link>
                ))}
              </div>

              {page < lastPage ? (
                <div className="mt-12 flex justify-center">
                  <button
                    type="button"
                    disabled={loading}
                    onClick={() => setPage((current) => current + 1)}
                    className="inline-flex items-center gap-3 rounded-lg border border-[#F6B400]/35 px-7 py-3 text-sm font-bold transition hover:bg-[#F6B400]/10 disabled:opacity-60"
                  >
                    {loading ? 'Memuat...' : 'Muat lebih banyak'}
                    <ArrowRight className="h-4 w-4 text-[#F6B400]" />
                  </button>
                </div>
              ) : null}
            </>
          )}
        </div>
      </main>

      <Footer brand={brand} logoSrc={logoSrc} />
    </div>
  );
}
