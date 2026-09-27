import { useEffect, useMemo, useState } from 'react';
import { ArrowRight, Clock } from 'lucide-react';

export type PromoBanner = {
  id: string | number;
  title?: string;
  subtitle?: string;
  ctaText?: string;
  ctaLink?: string;
  imageUrl?: string;
  badge?: string;
  isActive: boolean;
  expiresAt?: string;
  backgroundFrom?: string;
  backgroundTo?: string;
};

type PromoBannerProps = {
  items: PromoBanner[];
  variant: 'login' | 'register';
  className?: string;
};

const fallbackBanner: PromoBanner = {
  id: 'fallback',
  title: 'Buat akun sekarang & dapatkan Landing Page Gratis!',
  subtitle: 'Bangun bisnis online kamu lebih mudah bersama Hellom.',
  ctaText: 'Daftar Gratis Sekarang',
  ctaLink: '/register?app=landing_builder&plan=free',
  badge: 'Promo Terbatas',
  isActive: true,
  backgroundFrom: '#111111',
  backgroundTo: '#0B0B0C',
};

const pickActiveBanner = (items: PromoBanner[]) => {
  const now = Date.now();
  return (
    items.find((item) => {
      if (!item.isActive) return false;
      if (!item.expiresAt) return true;
      return new Date(item.expiresAt).getTime() > now;
    }) || fallbackBanner
  );
};

const formatCountdown = (expiresAt: string) => {
  const diff = new Date(expiresAt).getTime() - Date.now();
  if (diff <= 0) return null;
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  const remainingHours = hours % 24;
  const remainingMinutes = minutes % 60;

  if (days > 0) return `${days} hari ${remainingHours} jam`;
  if (hours > 0) return `${hours} jam ${remainingMinutes} menit`;
  return `${remainingMinutes} menit`;
};

export const PromoBanner = ({ items, variant, className }: PromoBannerProps) => {
  const [tick, setTick] = useState(0);
  const banner = useMemo(() => pickActiveBanner(items), [items]);
  const countdown = banner.expiresAt ? formatCountdown(banner.expiresAt) : null;
  const ctaText = banner.ctaText || (variant === 'register' ? 'Daftar Gratis Sekarang' : 'Lihat Promo');
  const ctaLink = banner.ctaLink || (variant === 'register' ? '/register' : '/register');
  const backgroundFrom = banner.backgroundFrom || '#111111';
  const backgroundTo = banner.backgroundTo || '#0B0B0C';
  const hasImage = Boolean(banner.imageUrl);

  useEffect(() => {
    if (!banner.expiresAt) return undefined;
    const timer = window.setInterval(() => setTick((value) => value + 1), 60000);
    return () => window.clearInterval(timer);
  }, [banner.expiresAt]);

  return (
    <div
      className={`overflow-hidden rounded-2xl border border-white/10 bg-black/60 p-4 shadow-[0_20px_40px_rgba(0,0,0,0.35)] ${className || ''}`}
      style={{
        background: `linear-gradient(135deg, ${backgroundFrom} 0%, ${backgroundTo} 100%)`,
      }}
    >
      <div className={`grid gap-4 ${hasImage ? 'sm:grid-cols-[1fr_160px]' : ''}`}>
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center rounded-full bg-[#FACC15]/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-[#FACC15]">
              {banner.badge || 'Promo Terbatas'}
            </span>
            {countdown ? (
              <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-3 py-1 text-[11px] font-semibold text-white/80">
                <Clock className="h-3 w-3" />
                Berakhir dalam {countdown}
              </span>
            ) : null}
          </div>
          <p className="text-base font-semibold text-white sm:text-lg" style={{ fontFamily: 'Inter, "Plus Jakarta Sans", "Geist", sans-serif' }}>
            {banner.title}
          </p>
          {banner.subtitle ? <p className="text-xs text-white/70 sm:text-sm">{banner.subtitle}</p> : null}
          <a
            href={ctaLink}
            className="inline-flex items-center gap-2 rounded-full bg-[#FACC15] px-4 py-2 text-xs font-semibold text-[#111111] transition-transform duration-200 hover:-translate-y-0.5"
          >
            {ctaText}
            <ArrowRight className="h-3.5 w-3.5" />
          </a>
        </div>
        {hasImage ? (
          <div className="overflow-hidden rounded-xl border border-white/10 bg-black/40">
            <img
              src={banner.imageUrl}
              alt={banner.title || 'Promo'}
              loading="lazy"
              className="h-full w-full object-cover"
            />
          </div>
        ) : null}
      </div>
    </div>
  );
};
