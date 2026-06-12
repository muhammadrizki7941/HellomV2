import type { BrandSettings } from '@/hooks/useBrand';
import { ArrowRight } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { getImageUrl, getPublicBanners, getSessionUser, getToken } from '@/lib/hellomApi';
import { HERO_CONTENT, STATS_DATA } from '../../lib/landingData';

interface HeroSectionProps {
  brand: BrandSettings;
}

type HeroBanner = {
  id: number;
  title: string;
  subtitle?: string | null;
  image_url?: string | null;
  media_type?: 'image' | 'video' | null;
  video_url?: string | null;
  link?: string | null;
};

const fallbackHeroCards: HeroBanner[] = [
  {
    id: 1,
    title: 'POS Restoran Nusantara',
    subtitle: 'Direkomendasikan',
    media_type: 'image',
    link: '/produk?search=pos',
  },
  {
    id: 2,
    title: 'Template Menu Digital QRIS',
    subtitle: 'Terbaru',
    media_type: 'image',
    link: '/produk?search=qris',
  },
  {
    id: 3,
    title: 'Aplikasi Loyalitas Pelanggan',
    subtitle: 'Lifetime',
    media_type: 'image',
    link: '/produk?search=loyalty',
  },
];

const heroCardStyles = [
  {
    container: 'absolute right-48 top-0 w-64 rotate-3 rounded-2xl border border-white/10 bg-zinc-900/80 p-5 shadow-2xl backdrop-blur-xl transition-transform duration-300 hover:rotate-0',
    badge: 'border-yellow-400/20 bg-yellow-400/10 text-yellow-400',
  },
  {
    container: 'absolute right-52 top-48 w-64 -rotate-2 rounded-2xl border border-white/10 bg-zinc-900/60 p-5 shadow-2xl backdrop-blur-xl transition-transform duration-300 hover:rotate-0',
    badge: 'border-emerald-400/20 bg-emerald-400/10 text-emerald-400',
  },
  {
    container: 'absolute right-0 top-8 w-80 rounded-2xl border border-purple-400/30 bg-gradient-to-br from-zinc-900 to-black p-6 shadow-2xl shadow-purple-400/10 transition-transform duration-300 hover:scale-105',
    badge: 'border-purple-400/30 bg-purple-400/10 text-purple-400',
  },
];

const resolveMediaUrl = (url?: string | null) => {
  if (!url) return '';
  return getImageUrl(url);
};

const getYouTubeEmbedUrl = (url: string) => {
  const match = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/))([A-Za-z0-9_-]+)/i);
  return match ? `https://www.youtube.com/embed/${match[1]}` : url;
};

export const HeroSection = ({ brand }: HeroSectionProps) => {
  const title = HERO_CONTENT.headingLines;
  const businessName = brand.app_name || brand.business_name || 'Hellom';
  const [heroCards, setHeroCards] = useState<HeroBanner[]>(fallbackHeroCards);
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  const primaryHref = isAuthenticated ? '/dashboard' : '/register';
  const handlePrimaryClick = () => {
    if (!isAuthenticated) {
      localStorage.setItem('hellom_intended_url', '/dashboard');
    }
  };

  useEffect(() => {
    let active = true;

    const load = async () => {
      try {
        const items = await getPublicBanners({ position: 'hero' });
        const mapped = (items as HeroBanner[]).filter((item) => item && item.title);
        if (active && mapped.length > 0) {
          setHeroCards(mapped.slice(0, 3));
        }
      } catch {
        if (active) {
          setHeroCards(fallbackHeroCards);
        }
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, []);

  const visibleHeroCards = useMemo(() => {
    if (heroCards.length >= 3) return heroCards.slice(0, 3);
    if (heroCards.length === 0) return fallbackHeroCards;
    return [...heroCards, ...fallbackHeroCards].slice(0, 3);
  }, [heroCards]);

  return (
    <section className="relative overflow-hidden bg-black pb-16 pt-16 sm:pb-32 sm:pt-28">
      <div className="pointer-events-none absolute inset-0 overflow-hidden">
        <div className="absolute left-1/2 top-0 h-[600px] w-[600px] -translate-x-1/2 rounded-full bg-yellow-400/10 blur-3xl" />
        <div className="absolute right-0 top-40 h-[400px] w-[400px] rounded-full bg-amber-500/5 blur-3xl" />
      </div>

      <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
          <div className="space-y-8">
            <div className="inline-flex items-center gap-2 rounded-full border border-yellow-400/20 bg-yellow-400/10 px-4 py-2 backdrop-blur-sm">
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-yellow-400 opacity-75"></span>
                <span className="relative inline-flex h-2 w-2 rounded-full bg-yellow-400"></span>
              </span>
              <span className="text-xs font-semibold uppercase tracking-wider text-yellow-400">
                {brand.tagline || 'Solusi Digital UMKM Indonesia'}
              </span>
            </div>

            <div className="space-y-4 sm:space-y-6">
              <h1
                className="text-4xl font-bold leading-tight tracking-tight text-white sm:text-6xl lg:text-7xl"
                style={{ fontFamily: 'Space Grotesk, sans-serif' }}
              >
                {title[0]} <span className="block text-yellow-400 italic">{title[1]}</span>
                <span className="block">{title[2]}</span>
              </h1>

              <p className="max-w-xl text-base leading-relaxed text-zinc-300 sm:text-lg lg:text-xl">
                {HERO_CONTENT.description}
              </p>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:gap-4">
              <Link
                to={primaryHref}
                onClick={handlePrimaryClick}
                className="group inline-flex items-center justify-center gap-2 rounded-xl bg-yellow-400 px-6 py-3.5 text-sm font-semibold text-black shadow-lg shadow-yellow-400/20 transition-all hover:scale-105 hover:bg-yellow-300 active:scale-95 sm:px-8 sm:py-4 sm:text-base"
              >
                {HERO_CONTENT.ctaPrimary}
                <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1 sm:h-5 sm:w-5" />
              </Link>
              <a
                href="#produk"
                className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-6 py-3.5 text-sm font-semibold text-white transition-all hover:border-white/20 hover:bg-white/10 sm:px-8 sm:py-4 sm:text-base"
              >
                {HERO_CONTENT.ctaSecondary}
              </a>
            </div>

            <div className="grid grid-cols-3 gap-3 pt-6 sm:gap-6 sm:pt-8">
              <div className="space-y-1">
                <div className="text-2xl font-bold text-white sm:text-3xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>{STATS_DATA[0].num}</div>
                <div className="text-xs text-zinc-400 sm:text-sm">{STATS_DATA[0].label}</div>
              </div>
              <div className="space-y-1">
                <div className="text-2xl font-bold text-white sm:text-3xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>{STATS_DATA[1].num}</div>
                <div className="text-xs text-zinc-400 sm:text-sm">{STATS_DATA[1].label}</div>
              </div>
              <div className="space-y-1">
                <div className="text-2xl font-bold text-white sm:text-3xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>{STATS_DATA[2].num}</div>
                <div className="text-xs text-zinc-400 sm:text-sm">{STATS_DATA[2].label}</div>
              </div>
            </div>
          </div>

          <div className="relative hidden h-[500px] lg:block">
            {visibleHeroCards.map((card, index) => {
              const style = heroCardStyles[index] || heroCardStyles[0];
              const isVideo = card.media_type === 'video' || (!!card.video_url && !card.image_url);
              const mediaUrl = resolveMediaUrl(isVideo ? card.video_url : card.image_url);
              const isYouTube = Boolean(mediaUrl) && /youtube\.com|youtu\.be/i.test(mediaUrl);
              const badgeLabel = isVideo ? 'Video' : card.subtitle || 'Promo';

              return (
                <div key={card.id ?? index} className={style.container}>
                  <div className={`mb-2 inline-flex rounded-lg border px-2 py-0 text-xs font-semibold uppercase tracking-wider ${style.badge}`}>
                    {badgeLabel}
                  </div>
                  <h3 className="mb-3 text-xl font-bold text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                    {card.title}
                  </h3>
                  {mediaUrl ? (
                    <div className="mb-4 overflow-hidden rounded-xl border border-white/10 bg-black/40">
                      {isVideo ? (
                        isYouTube ? (
                          <iframe
                            title={card.title}
                            src={getYouTubeEmbedUrl(mediaUrl)}
                            className="h-36 w-full"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                          />
                        ) : (
                          <video
                            src={mediaUrl}
                            className="h-36 w-full object-cover"
                            muted
                            loop
                            playsInline
                            autoPlay
                          />
                        )
                      ) : (
                        <img src={mediaUrl} alt={card.title} className="h-36 w-full object-cover" />
                      )}
                    </div>
                  ) : null}
                  {card.link ? (
                    <a
                      href={card.link}
                      className="block mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-yellow-300 transition-colors hover:text-yellow-200"
                    >
                      Lihat Detail
                      <ArrowRight className="h-3.5 w-3.5" />
                    </a>
                  ) : null}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
};
