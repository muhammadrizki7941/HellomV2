import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Clock } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import useSeo from '@/hooks/useSeo';
import { getImageUrl, getPublicInsights } from '@/lib/hellomApi';
import PageHero from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';

type ArticleTeaser = {
  id: number;
  title: string;
  slug: string;
  thumbnail?: string | null;
  excerpt?: string | null;
  category?: string | null;
  published_at?: string | null;
  read_time?: number | null;
  is_featured?: boolean;
};

const formatDate = (date?: string | null) =>
  date ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(date)) : '';

function Thumb({ src, alt, className }: { src?: string | null; alt: string; className: string }) {
  return src ? (
    <img src={getImageUrl(src)} alt={alt} width={640} height={400} loading="lazy" decoding="async" className={className} />
  ) : (
    <div className={`${className} bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.2),transparent_38%),#111]`} />
  );
}

export default function WawasanPage() {
  const { brand } = useBrand();
  const brandName = brand.business_name || brand.app_name || 'Hellom';
  const [items, setItems] = useState<ArticleTeaser[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [activeCategory, setActiveCategory] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);

  useSeo({
    title: `Wawasan & Artikel | ${brandName}`,
    description: `Artikel, tips, dan wawasan seputar digital, branding, dan produktivitas dari ${brandName}.`,
    url: '/wawasan',
    type: 'website',
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'Blog',
      name: `Wawasan ${brandName}`,
      url: typeof window !== 'undefined' ? `${window.location.origin}/wawasan` : '/wawasan',
    },
  });

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getPublicInsights({ page, per_page: 12, category: activeCategory || undefined })
      .then((res) => {
        if (cancelled) return;
        const data = res as { items?: ArticleTeaser[]; categories?: string[]; pagination?: { last_page?: number } };
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
    <>
      <PageHero
        eyebrow="Wawasan"
        lines={['Berbagi wawasan seputar', <span key="w">digital, branding, dan <span className="font-serif italic text-[#F6B400]">produktivitas.</span></span>]}
        intro="Catatan praktis dari pengalaman membangun brand, produk, dan sistem untuk bisnis."
        compact
      />

      <section className="px-5 py-14 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          {categories.length > 0 ? (
            <div role="group" aria-label="Filter kategori artikel" className="scrollbar-hide -mx-5 flex gap-2 overflow-x-auto px-5">
              {['', ...categories].map((category) => (
                <button
                  key={category || 'semua'}
                  type="button"
                  aria-pressed={activeCategory === category}
                  onClick={() => selectCategory(category)}
                  className={`h-11 shrink-0 rounded-full px-5 text-sm font-semibold transition-colors ${activeCategory === category ? 'bg-[#F6B400] text-black' : 'border border-white/[0.12] text-[#D6D6D8] hover:border-white/30'}`}
                >
                  {category || 'Semua'}
                </button>
              ))}
            </div>
          ) : null}

          {loading && items.length === 0 ? (
            <p className="mt-12 text-sm text-[#A1A1A6]" aria-busy="true">Memuat artikel...</p>
          ) : items.length === 0 ? (
            <p className="mt-12 text-sm text-[#A1A1A6]">Belum ada artikel yang dipublikasikan.</p>
          ) : (
            <>
              {featured ? (
                <Reveal className="mt-10">
                  <Link
                    to={`/wawasan/${featured.slug}`}
                    className="group grid overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] transition-colors hover:border-[#F6B400]/40 md:grid-cols-2"
                  >
                    <div className="aspect-[16/10] overflow-hidden">
                      <Thumb src={featured.thumbnail} alt={featured.title} className="h-full w-full object-cover" />
                    </div>
                    <div className="flex flex-col justify-center p-7 md:p-10">
                      {featured.category ? <span className="text-xs font-semibold uppercase tracking-wide text-[#F6B400]">{featured.category}</span> : null}
                      <h2 className="mt-3 font-display text-2xl font-medium leading-tight md:text-4xl">{featured.title}</h2>
                      {featured.excerpt ? <p className="mt-4 text-sm leading-7 text-[#A1A1A6]">{featured.excerpt}</p> : null}
                      <p className="mt-6 flex items-center gap-4 text-xs text-[#A1A1A6]">
                        <span>{formatDate(featured.published_at)}</span>
                        <span className="inline-flex items-center gap-1"><Clock aria-hidden className="h-3.5 w-3.5" /> {featured.read_time || 5} menit baca</span>
                      </p>
                    </div>
                  </Link>
                </Reveal>
              ) : null}

              <ul className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {rest.map((article, index) => (
                  <Reveal as="li" key={article.id} delay={(index % 3) * 0.06}>
                    <Link
                      to={`/wawasan/${article.slug}`}
                      className="group flex h-full flex-col overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] transition-colors hover:border-[#F6B400]/40"
                    >
                      <div className="aspect-[16/10] overflow-hidden">
                        <Thumb src={article.thumbnail} alt={article.title} className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-[1.03]" />
                      </div>
                      <div className="flex flex-1 flex-col p-5">
                        {article.category ? <span className="text-xs font-semibold uppercase tracking-wide text-[#F6B400]">{article.category}</span> : null}
                        <h3 className="mt-2 text-base font-semibold leading-6">{article.title}</h3>
                        {article.excerpt ? <p className="mt-2 line-clamp-3 text-sm text-[#A1A1A6]">{article.excerpt}</p> : null}
                        <p className="mt-auto flex items-center gap-3 pt-4 text-xs text-[#A1A1A6]">
                          <span>{formatDate(article.published_at)}</span>
                          <span className="inline-flex items-center gap-1"><Clock aria-hidden className="h-3.5 w-3.5" /> {article.read_time || 5} menit</span>
                        </p>
                      </div>
                    </Link>
                  </Reveal>
                ))}
              </ul>

              {page < lastPage ? (
                <div className="mt-12 flex justify-center">
                  <button
                    type="button"
                    disabled={loading}
                    onClick={() => setPage((current) => current + 1)}
                    className="inline-flex min-h-12 items-center gap-3 rounded-lg border border-[#F6B400]/35 px-7 text-sm font-bold transition-colors hover:bg-[#F6B400]/10 disabled:opacity-60"
                  >
                    {loading ? 'Memuat...' : 'Muat lebih banyak'}
                    <ArrowRight aria-hidden className="h-4 w-4 text-[#F6B400]" />
                  </button>
                </div>
              ) : null}
            </>
          )}
        </div>
      </section>
    </>
  );
}
