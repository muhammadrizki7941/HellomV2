import { useEffect, useState } from 'react';
import { ArrowRight } from 'lucide-react';
import { getImageUrl, getPublicBanners } from '@/lib/hellomApi';
import type { PublicBanner } from '@/services/api/landing';
import { safeBannerLink } from '@/lib/banner';

type PublicBannerSlotProps = {
  position: 'header' | 'hero';
  compact?: boolean;
};

export default function PublicBannerSlot({ position, compact = false }: PublicBannerSlotProps) {
  const [banner, setBanner] = useState<PublicBanner | null>(null);

  useEffect(() => {
    let active = true;
    getPublicBanners({ position })
      .then(items => { if (active) setBanner(items[0] || null); })
      .catch(() => { if (active) setBanner(null); });
    return () => { active = false; };
  }, [position]);

  if (!banner) return null;

  const link = safeBannerLink(banner.link);
  const from = banner.background_from && /^#[\da-f]{6}$/i.test(banner.background_from) ? banner.background_from : '#151515';
  const to = banner.background_to && /^#[\da-f]{6}$/i.test(banner.background_to) ? banner.background_to : '#29200e';
  const image = banner.image_url ? getImageUrl(banner.image_url) : '';

  return (
    <section
      aria-label="Informasi dan promosi"
      className={`relative overflow-hidden border border-white/10 text-[#F5F5F2] ${compact ? 'mx-auto flex max-w-[1500px] items-center justify-between gap-3 rounded-xl px-3 py-2 md:px-4' : 'mx-auto flex max-w-[1500px] items-center justify-between gap-5 rounded-2xl px-5 py-5 md:px-8 md:py-7'}`}
      style={{ backgroundImage: `linear-gradient(115deg, ${from}, ${to})` }}
    >
      <div className="relative z-10 min-w-0 flex-1">
        {banner.badge && <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-[#F6B400]">{banner.badge}</p>}
        <h2 className={`font-semibold ${compact ? 'text-xs sm:text-sm' : 'text-lg sm:text-2xl'}`}>{banner.title}</h2>
        {!compact && banner.subtitle && <p className="mt-1 max-w-2xl text-sm text-white/70">{banner.subtitle}</p>}
        {link && (
          <a href={link} className={`inline-flex items-center gap-1 font-semibold text-[#F6B400] hover:text-white ${compact ? 'ml-2 text-[10px] sm:text-xs' : 'mt-3 text-xs'}`}>
            {banner.cta_text || 'Selengkapnya'} <ArrowRight className="h-3.5 w-3.5" />
          </a>
        )}
      </div>
      {image && <img src={image} alt="" className={`relative z-10 shrink-0 rounded-lg object-cover ${compact ? 'h-9 w-14 sm:h-11 sm:w-16' : 'h-16 w-24 sm:h-24 sm:w-36'}`} />}
    </section>
  );
}
