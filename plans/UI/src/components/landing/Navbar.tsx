import type { BrandSettings } from '@/hooks/useBrand';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { Menu, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { NAV_LINKS } from '../../lib/landingData';
import { usePromoBanners } from '@/components/auth';

interface NavbarProps {
  brand: BrandSettings;
  logoSrc: string | null;
}

export const Navbar = ({ brand, logoSrc }: NavbarProps) => {
  const [open, setOpen] = useState(false);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const { items: promoItems } = usePromoBanners('header');

  useEffect(() => {
    const syncSessionState = () => {
      const hasToken = Boolean(getToken());
      const hasUser = Boolean(getSessionUser());
      setIsAuthenticated(hasToken && hasUser);
    };

    syncSessionState();
    window.addEventListener('storage', syncSessionState);

    return () => window.removeEventListener('storage', syncSessionState);
  }, []);

  const businessName = brand.app_name || brand.business_name || 'Hellom';
  const primaryHref = isAuthenticated ? '/dashboard' : '/login';
  const secondaryHref = isAuthenticated ? '/dashboard' : '/register';
  const activePromo = useMemo(() => {
    const now = Date.now();
    return promoItems.find((item) => {
      if (!item.isActive) return false;
      if (!item.expiresAt) return true;
      return new Date(item.expiresAt).getTime() > now;
    });
  }, [promoItems]);
  const promoText = activePromo?.title || 'Hellomspace menyediakan produk digital, aplikasi bisnis, dan layanan custom dalam satu platform yang siap digunakan.';
  const promoLink = activePromo?.ctaLink || '/register?app=landing_builder&plan=free';
  const promoCta = activePromo?.ctaText || 'Mulai Sekarang';

  return (
    <>
      <div className="bg-gradient-to-r from-yellow-400 to-amber-500 text-black">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-2 px-4 py-2.5 sm:flex-nowrap sm:justify-between sm:px-6 lg:px-8">
          <p className="text-center text-xs font-semibold tracking-tight sm:text-sm">
            {promoText}
          </p>
          <Link
            to={promoLink}
            className="rounded-full border border-black/10 bg-black/10 px-3 py-1 text-[11px] font-semibold text-black transition-transform hover:-translate-y-0.5"
          >
            <span>{`${promoCta} ->`}</span>
          </Link>
        </div>
      </div>

      <nav className="sticky top-0 z-50 border-b border-white/5 bg-black/85 backdrop-blur-xl">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="flex h-16 items-center justify-between">
            <Link to="/" className="flex items-center gap-3">
              {logoSrc ? (
                <img src={logoSrc} alt={businessName} className="h-8 w-auto object-contain" />
              ) : (
                <span className="text-lg font-bold tracking-tight text-white sm:text-xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                  Hell<span className="text-yellow-400">OM</span>
                </span>
              )}
            </Link>

            <div className="hidden items-center gap-1 md:flex">
              {NAV_LINKS.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  className="rounded-lg px-4 py-2 text-sm font-medium text-zinc-400 transition-colors hover:bg-white/5 hover:text-white"
                >
                  {link.label}
                </a>
              ))}
            </div>

            <div className="hidden items-center gap-3 md:flex">
              <Link to={primaryHref} className="rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-zinc-300 transition-colors hover:border-white/20 hover:text-white">
                {isAuthenticated ? 'Dashboard' : 'Masuk'}
              </Link>
              <Link
                to={secondaryHref}
                className="rounded-full bg-yellow-400 px-5 py-2.5 text-sm font-semibold text-black transition-all hover:scale-105 hover:bg-yellow-300 active:scale-95"
              >
                {isAuthenticated ? 'Buka dashboard ->' : 'Mulai sekarang ->'}
              </Link>
            </div>

            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              className="rounded-lg border border-white/10 p-2 text-zinc-300 md:hidden"
              aria-label="Toggle menu"
            >
              {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>
          </div>
        </div>

        {open && (
          <div className="border-t border-white/5 bg-black/95 backdrop-blur-xl md:hidden">
            <div className="space-y-2 px-4 py-4">
              {NAV_LINKS.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  onClick={() => setOpen(false)}
                  className="block rounded-lg px-4 py-3 text-sm font-medium text-zinc-400 transition-colors hover:bg-white/5 hover:text-white"
                >
                  {link.label}
                </a>
              ))}
              <div className="space-y-2 border-t border-white/5 pt-4">
                <Link to={primaryHref} className="block rounded-lg px-4 py-3 text-sm font-semibold text-zinc-300 transition-colors hover:bg-white/5 hover:text-white">
                  {isAuthenticated ? 'Dashboard' : 'Masuk'}
                </Link>
                <Link
                  to={secondaryHref}
                  className="block rounded-lg bg-yellow-400 px-4 py-3 text-center text-sm font-semibold text-black"
                >
                  {isAuthenticated ? 'Buka dashboard ->' : 'Mulai sekarang ->'}
                </Link>
              </div>
            </div>
          </div>
        )}
      </nav>
    </>
  );
};
