import { useEffect, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { AnimatePresence, m, useReducedMotion } from 'framer-motion';
import { ArrowRight, Menu, X } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { SITE_PAGES } from '@/data/siteNav';
import { EASE_CURTAIN, EASE_REVEAL } from './motion';
import PublicBannerSlot from './PublicBannerSlot';
import './site-navbar.css';

export function BrandMark({ className = 'h-8' }: { className?: string }) {
  const { brand, logoSrc } = useBrand();
  const name = brand.app_name || brand.business_name || 'Hellom';
  return (
    <span className="inline-flex max-w-[220px] flex-col items-start gap-1">
      {logoSrc ? (
        <img src={logoSrc} alt={name} className={`${className} max-w-full w-auto object-contain object-left`} />
      ) : (
        <span className="text-2xl font-black text-[#F5F5F2]">{name}</span>
      )}
      <span className="text-[10px] font-medium leading-tight tracking-wide text-[#A1A1A6]">Your Creative Bussiness Partner</span>
    </span>
  );
}

export default function SiteNavbar() {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const reduced = useReducedMotion();
  const isAuthenticated = Boolean(getToken() && getSessionUser());

  // Close the mobile menu once the route changes.
  useEffect(() => setOpen(false), [pathname]);

  // Give the floating glass panel a little more contrast once the page scrolls.
  const [atTop, setAtTop] = useState(true);
  useEffect(() => {
    const onScroll = () => setAtTop(window.scrollY < 24);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    document.body.style.overflow = open ? 'hidden' : '';
    return () => {
      document.body.style.overflow = '';
    };
  }, [open]);

  return (
    <>
      <header
        className={`site-navbar-shell ${!atTop || open ? 'is-scrolled' : ''}`}
      >
        <div className="site-navbar-glass">
          <Link to="/" className="site-navbar-brand flex min-h-11 items-center">
            <BrandMark />
            <span className="sr-only"> — ke Beranda</span>
          </Link>

          <nav aria-label="Navigasi utama" className="site-navbar-links hidden items-center xl:flex">
            {SITE_PAGES.map((page) => (
              <NavLink
                key={page.path}
                to={page.path}
                end={page.path === '/'}
                className={({ isActive }) =>
                  `site-navbar-link ${isActive ? 'is-active' : ''}`
                }
              >
                {page.label}
              </NavLink>
            ))}
          </nav>

          <div className="site-navbar-actions flex items-center gap-2">
            <Link
              to={isAuthenticated ? '/dashboard' : '/login'}
              className="site-navbar-login hidden md:inline-flex"
            >
              {isAuthenticated ? 'Dashboard' : 'Masuk'}
            </Link>
            <Link
              to="/kontak"
              className="site-navbar-contact hidden md:inline-flex"
            >
              Ngobrol Yuk <ArrowRight className="h-3.5 w-3.5 text-[#F6B400]" />
            </Link>
            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              aria-expanded={open}
              aria-controls="menu-seluler"
              aria-label={open ? 'Tutup menu' : 'Buka menu'}
              className="site-navbar-toggle flex h-11 w-11 items-center justify-center xl:hidden"
            >
              {open ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
            </button>
          </div>
        </div>
        <div className="site-navbar-banner">
          <PublicBannerSlot position="header" compact />
        </div>
      </header>

      <AnimatePresence>
        {open ? (
          <m.div
            id="menu-seluler"
            key="menu"
            className="site-navbar-mobile fixed z-40 px-5 py-6 xl:hidden"
            initial={reduced ? { opacity: 0 } : { opacity: 0, y: '-4%' }}
            animate={reduced ? { opacity: 1 } : { opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: reduced ? 0.15 : 0.45, ease: EASE_CURTAIN }}
          >
            <nav aria-label="Navigasi seluler" className="flex min-h-full flex-col">
              <ul className="space-y-1">
                {SITE_PAGES.map((page, index) => (
                  <li key={page.path} className="overflow-hidden">
                    <m.div
                      initial={reduced ? false : { y: '100%' }}
                      animate={{ y: 0 }}
                      transition={{ duration: 0.6, ease: EASE_REVEAL, delay: 0.08 + index * 0.04 }}
                    >
                      <NavLink
                        to={page.path}
                        end={page.path === '/'}
                        className={({ isActive }) =>
                          `flex min-h-12 items-baseline gap-4 py-1 font-display text-4xl ${isActive ? 'text-[#F6B400]' : 'text-[#F5F5F2]'}`
                        }
                      >
                        <span className="text-xs font-sans text-[#A1A1A6]">{String(index + 1).padStart(2, '0')}</span>
                        {page.label}
                      </NavLink>
                    </m.div>
                  </li>
                ))}
              </ul>
              <div className="mt-auto flex gap-3 pt-8">
                <Link to={isAuthenticated ? '/dashboard' : '/login'} className="flex h-12 flex-1 items-center justify-center rounded-lg border border-white/[0.12] text-sm font-bold">
                  {isAuthenticated ? 'Dashboard' : 'Masuk'}
                </Link>
                <Link to="/kontak" className="flex h-12 flex-1 items-center justify-center rounded-lg bg-[#F6B400] text-sm font-bold text-black">
                  Ngobrol Yuk
                </Link>
              </div>
            </nav>
          </m.div>
        ) : null}
      </AnimatePresence>
    </>
  );
}
