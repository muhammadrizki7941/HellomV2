import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Store } from 'lucide-react';
import { getPublicBanners, getImageUrl } from '@/lib/hellomApi';
import type { PublicBanner } from '@/services/api/landing';
import { safeBannerLink } from '@/lib/banner';

export default function DashboardBanner() {
  const [banners, setBanners] = useState<PublicBanner[]>([]);
  const [index, setIndex] = useState(0);
  const [loadError, setLoadError] = useState(false);
  useEffect(() => {
    let active = true;
    getPublicBanners({ position: 'dashboard' })
      .then(items => { if (active) setBanners(items); })
      .catch(() => { if (active) setLoadError(true); });
    return () => { active = false; };
  }, []);
  const banner = banners[index];
  const href = safeBannerLink(banner?.link);
  const gradientColor = (value?: string | null) => value && /^#[\da-f]{6}$/i.test(value) ? value : null;
  const from = gradientColor(banner?.background_from);
  const to = gradientColor(banner?.background_to);
  return <section aria-label="Informasi dashboard" className="overflow-hidden rounded-3xl bg-zinc-900 shadow-sm">
    <div className="relative flex min-h-44 items-center gap-4 p-5 sm:min-h-52 sm:p-8" style={from && to ? { backgroundImage: `linear-gradient(120deg, ${from}, ${to})` } : undefined}>
      <div className="relative z-10 min-w-0 flex-1 text-white">
        {banner?.badge && <p className="mb-2 text-xs font-bold text-amber-300">{banner.badge}</p>}
        <h2 className="max-w-md text-xl font-bold leading-tight sm:text-3xl">{banner?.title || <>Kelola bisnismu<br /><span className="text-amber-400">lebih mudah.</span></>}</h2>
        <p className="mt-2 max-w-md text-sm leading-6 text-zinc-300">{banner?.subtitle || 'Semua alat yang Anda butuhkan untuk mengembangkan bisnis, dalam satu tempat.'}</p>
        {href ? <a href={href} className="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl bg-amber-400 px-4 text-sm font-bold text-black">{banner?.cta_text || 'Selengkapnya'} <ArrowRight className="h-4 w-4" /></a> : !banner && <Link to="/dashboard/products" className="mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-bold text-amber-300">Jelajahi aplikasi <ArrowRight className="h-4 w-4" /></Link>}
      </div>
      {banner?.image_url ? <img src={getImageUrl(banner.image_url)} alt="" className="hidden w-1/3 max-w-xs rounded-2xl object-contain sm:block" /> : <Store aria-hidden className="hidden h-28 w-28 shrink-0 text-amber-300 sm:block" />}
    </div>
    {banner?.image_url && <img src={getImageUrl(banner.image_url)} alt="" className="max-h-52 w-full object-contain sm:hidden" />}
    {banners.length > 1 && <div className="flex justify-center gap-2 pb-3">{banners.map((b, i) => <button key={b.id} aria-label={`Banner ${i + 1}`} aria-pressed={i === index} onClick={() => setIndex(i)} className="flex h-8 w-8 items-center justify-center"><span className={`h-2 w-2 rounded-full ${i === index ? 'bg-amber-400' : 'bg-zinc-500'}`} /></button>)}</div>}
    {loadError && <p role="status" className="px-5 pb-3 text-xs text-zinc-400">Banner belum dapat dimuat. Konten bawaan tetap ditampilkan.</p>}
  </section>;
}
