import { useEffect, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { AnimatePresence, m, useReducedMotion } from 'framer-motion';
import { ArrowRight, Menu, X } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { SITE_PAGES } from '@/data/siteNav';
import { EASE_CURTAIN, EASE_REVEAL } from './motion';

export function BrandMark({ className = 'h-8' }: { className?: string }) {
  const { brand, logoSrc } = useBrand();
  const name = brand.app_name || brand.business_name || 'Hellom';
  return logoSrc ? (
    <img src={logoSrc} alt={name} className={`${className} w-auto object-contain`} />
  ) : (
    <span className="text-2xl font-black text-[#F5F5F2]">Hell<span className="text-[#F6B400]">om</span></span>
  );
}

export default function SiteNavbar() {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const reduced = useReducedMotion();
  const isAuthenticated = Boolean(getToken() && getSessionUser());

  // Close the mobile menu once the route changes.
  useEffect(() => setOpen(false), [pathname]);

  // Over the home intro video the bar is fully transparent; it gains its backdrop once scrolled.
  const [atTop, setAtTop] = useState(true);
  useEffect(() => {
    const onScroll = () => setAtTop(window.scrollY < 24);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  const overIntro = pathname === '/' && atTop && !open;

  useEffect(() => {
    document.body.style.overflow = open ? 'hidden' : '';
    return () => {
      document.body.style.overflow = '';
    };
  }, [open]);

  return (
    <>
      <header
        className={`fixed inset-x-0 top-0 z-50 border-b transition-[background-color,border-color,backdrop-filter] duration-500 ${
          overIntro ? 'border-transparent bg-transparent' : 'border-white/[0.06] bg-[#050505]/60 backdrop-blur-xl'
        }`}
      >
        <div className="mx-auto flex h-20 max-w-[1500px] items-center justify-between px-5 md:px-10 lg:px-16">
          <Link to="/" className="flex min-h-11 items-center">
            <BrandMark />
            <span className="sr-only"> — ke Beranda</span>
          </Link>

          <nav aria-label="Navigasi utama" className="hidden items-center gap-8 xl:flex">
            {SITE_PAGES.map((page) => (
              <NavLink
                key={page.path}
                to={page.path}
                end={page.path === '/'}
                className={({ isActive }) =>
                  `link-draw relative py-2 text-[13px] font-semibold transition-colors ${isActive ? 'text-[#F5F5F2]' : 'text-[#A1A1A6] hover:text-[#F5F5F2]'}`
                }
              >
                {({ isActive }) => (
                  <>
                    {page.label}
                    <span
                      aria-hidden
                      className={`absolute -bottom-2 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-[#F6B400] shadow-[0_0_10px_#F6B400] transition-[opacity,transform] duration-300 ${isActive ? 'scale-100 opacity-100' : 'scale-0 opacity-0'}`}
                    />
                  </>
                )}
              </NavLink>
            ))}
          </nav>

          <div className="flex items-center gap-2">
            <Link
              to={isAuthenticated ? '/dashboard' : '/login'}
              className="hidden min-h-11 items-center rounded-lg px-4 text-[13px] font-bold text-[#A1A1A6] transition-colors hover:text-[#F5F5F2] md:inline-flex"
            >
              {isAuthenticated ? 'Dashboard' : 'Masuk'}
            </Link>
            <Link
              to="/kontak"
              className="hidden h-11 items-center gap-3 rounded-lg border border-[#F6B400]/45 px-5 text-[13px] font-bold text-[#F5F5F2] transition-colors hover:bg-[#F6B400]/10 md:inline-flex"
            >
              Ngobrol Yuk <ArrowRight className="h-3.5 w-3.5 text-[#F6B400]" />
            </Link>
            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              aria-expanded={open}
              aria-controls="menu-seluler"
              aria-label={open ? 'Tutup menu' : 'Buka menu'}
              className="flex h-11 w-11 items-center justify-center rounded-lg text-[#F5F5F2] xl:hidden"
            >
              {open ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
            </button>
          </div>
        </div>
      </header>

      <AnimatePresence>
        {open ? (
          <m.div
            id="menu-seluler"
            key="menu"
            className="fixed inset-0 z-40 bg-[#050505] px-5 pb-10 pt-28 xl:hidden"
            initial={reduced ? { opacity: 0 } : { opacity: 0, y: '-4%' }}
            animate={reduced ? { opacity: 1 } : { opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: reduced ? 0.15 : 0.45, ease: EASE_CURTAIN }}
          >
            <nav aria-label="Navigasi seluler" className="flex h-full flex-col">
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
