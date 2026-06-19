import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Clock, User } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import useSeo from '@/hooks/useSeo';
import { Footer } from '@/components/landing/Footer';
import { getImageUrl, getPublicInsightBySlug } from '@/lib/hellomApi';

type ArticleDetail = {
  id: number;
  title: string;
  slug: string;
  meta_title?: string | null;
  meta_description?: string | null;
  meta_keywords?: string | null;
  og_image?: string | null;
  author?: string | null;
  thumbnail?: string | null;
  content?: string | null;
  excerpt?: string | null;
  category?: string | null;
  published_at?: string | null;
  updated_at?: string | null;
  read_time?: number | null;
};

type RelatedArticle = {
  id: number;
  title: string;
  slug: string;
  thumbnail?: string | null;
  category?: string | null;
  published_at?: string | null;
  read_time?: number | null;
};

const formatDate = (date?: string | null) => {
  if (!date) return '';
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(date));
};

export default function InsightDetailPage() {
  const { slug } = useParams();
  const { brand, logoSrc } = useBrand();
  const brandName = brand.business_name || brand.app_name || 'Hellom';

  const [article, setArticle] = useState<ArticleDetail | null>(null);
  const [related, setRelated] = useState<RelatedArticle[]>([]);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    if (!slug) return;
    let cancelled = false;
    setLoading(true);
    setNotFound(false);

    getPublicInsightBySlug(slug)
      .then((res) => {
        if (cancelled) return;
        const data = res as { article?: ArticleDetail; related?: RelatedArticle[] };
        if (!data.article) {
          setNotFound(true);
          return;
        }
        setArticle(data.article);
        setRelated(data.related || []);
      })
      .catch(() => {
        if (!cancelled) setNotFound(true);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  const seoTitle = article ? `${article.meta_title || article.title} | ${brandName}` : `Artikel | ${brandName}`;
  const seoDescription = article?.meta_description || article?.excerpt || '';
  const rawSeoImage = article?.og_image || article?.thumbnail || brand.logo_url;
  const seoImage = rawSeoImage ? getImageUrl(rawSeoImage) : undefined;
  const canonicalUrl = `/insights/${slug || ''}`;

  useSeo({
    title: seoTitle,
    description: seoDescription,
    url: canonicalUrl,
    image: seoImage,
    type: 'article',
    keywords: article?.meta_keywords,
    author: article?.author || brandName,
    publishedTime: article?.published_at,
    modifiedTime: article?.updated_at,
    index: Boolean(article) && !notFound,
    jsonLd: article
      ? {
          '@context': 'https://schema.org',
          '@type': 'BlogPosting',
          headline: article.title,
          description: seoDescription,
          image: seoImage || undefined,
          datePublished: article.published_at || undefined,
          dateModified: article.updated_at || article.published_at || undefined,
          author: { '@type': 'Person', name: article.author || brandName },
          publisher: {
            '@type': 'Organization',
            name: brandName,
            logo: brand.logo_url ? { '@type': 'ImageObject', url: getImageUrl(brand.logo_url) } : undefined,
          },
          mainEntityOfPage:
            typeof window !== 'undefined' ? `${window.location.origin}${canonicalUrl}` : canonicalUrl,
        }
      : null,
  });

  return (
    <div className="min-h-screen bg-[#050505] text-[#F5F5F2]">
      <header className="border-b border-white/[0.08] px-6 py-4">
        <div className="mx-auto flex max-w-3xl items-center justify-between gap-4">
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
            to="/insights"
            className="inline-flex items-center gap-2 rounded-lg border border-white/10 px-3 py-2 text-sm font-semibold text-zinc-300 hover:bg-white/5"
          >
            <ArrowLeft className="h-4 w-4" />
            Semua Artikel
          </Link>
        </div>
      </header>

      <main className="px-6 py-12">
        <div className="mx-auto max-w-3xl">
          {loading ? (
            <p className="text-sm text-zinc-500">Memuat artikel...</p>
          ) : notFound || !article ? (
            <div className="space-y-4 py-16 text-center">
              <h1 className="font-display text-3xl font-medium">Artikel tidak ditemukan</h1>
              <p className="text-sm text-zinc-400">Artikel yang kamu cari mungkin sudah dihapus atau belum dipublikasikan.</p>
              <Link to="/insights" className="inline-flex items-center gap-2 rounded-lg border border-[#F6B400]/35 px-5 py-3 text-sm font-bold hover:bg-[#F6B400]/10">
                Lihat artikel lain
              </Link>
            </div>
          ) : (
            <article>
              {article.category ? (
                <span className="text-xs font-semibold uppercase tracking-[0.2em] text-[#F6B400]">{article.category}</span>
              ) : null}
              <h1 className="mt-3 font-display text-3xl font-medium leading-tight md:text-4xl">{article.title}</h1>

              <div className="mt-5 flex flex-wrap items-center gap-4 text-xs text-[#8B8B90]">
                <span className="inline-flex items-center gap-1.5"><User className="h-3.5 w-3.5" /> {article.author || brandName}</span>
                <span>{formatDate(article.published_at)}</span>
                <span className="inline-flex items-center gap-1.5"><Clock className="h-3.5 w-3.5" /> {article.read_time || 5} min read</span>
              </div>

              {article.thumbnail ? (
                <img
                  src={getImageUrl(article.thumbnail)}
                  alt={article.title}
                  className="mt-8 aspect-[16/9] w-full rounded-2xl border border-white/[0.08] object-cover"
                />
              ) : null}

              {article.excerpt ? (
                <p className="mt-8 text-lg leading-relaxed text-[#D6D6D8]">{article.excerpt}</p>
              ) : null}

              {article.content ? (
                <div
                  className="article-content mt-8 text-[15px] leading-relaxed text-[#C9C9CC] [&_a]:text-[#F6B400] [&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:border-[#F6B400]/50 [&_blockquote]:pl-4 [&_blockquote]:text-[#B6B6B8] [&_h2]:mt-8 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-medium [&_h2]:text-white [&_h3]:mt-6 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-white [&_img]:my-6 [&_img]:rounded-xl [&_li]:mt-1 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6"
                  dangerouslySetInnerHTML={{ __html: article.content }}
                />
              ) : null}

              <div className="mt-12 border-t border-white/[0.08] pt-6">
                <Link to="/insights" className="inline-flex items-center gap-2 text-sm font-semibold text-[#F6B400] hover:underline">
                  <ArrowLeft className="h-4 w-4" /> Kembali ke semua artikel
                </Link>
              </div>

              {related.length > 0 ? (
                <section className="mt-14">
                  <h2 className="font-display text-xl font-medium text-white">Artikel terkait</h2>
                  <div className="mt-5 grid gap-5 sm:grid-cols-3">
                    {related.map((item) => (
                      <Link
                        key={item.id}
                        to={`/insights/${item.slug}`}
                        className="group flex flex-col overflow-hidden rounded-xl border border-white/[0.08] bg-white/[0.03] transition hover:border-[#F6B400]/40"
                      >
                        <div className="aspect-[16/10] w-full overflow-hidden">
                          {item.thumbnail ? (
                            <img src={getImageUrl(item.thumbnail)} alt={item.title} className="h-full w-full object-cover transition group-hover:scale-[1.03]" />
                          ) : (
                            <div className="h-full w-full bg-[radial-gradient(circle_at_45%_20%,rgba(246,180,0,.2),transparent_38%),#111]" />
                          )}
                        </div>
                        <div className="p-4">
                          <h3 className="text-sm font-semibold leading-5 text-white">{item.title}</h3>
                          <p className="mt-2 text-xs text-[#8B8B90]">{formatDate(item.published_at)}</p>
                        </div>
                      </Link>
                    ))}
                  </div>
                </section>
              ) : null}
            </article>
          )}
        </div>
      </main>

      <Footer brand={brand} logoSrc={logoSrc} />
    </div>
  );
}
