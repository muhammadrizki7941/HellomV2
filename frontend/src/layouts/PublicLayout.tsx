import { Suspense, useEffect } from 'react';
import { useLocation, useNavigate, useOutlet } from 'react-router-dom';
import { LazyMotion, domAnimation, m, useReducedMotion } from 'framer-motion';
import SiteNavbar from '@/components/site/SiteNavbar';
import SiteFooter from '@/components/site/SiteFooter';
import HellomLoading from '@/components/HellomLoading';
import {
  DestinationTitleContext,
  RevealDelayContext,
  hasFinePointer,
} from '@/components/site/motion';
import { curtainTitleFor, pathForLegacyHash } from '@/data/siteNav';
import { prefetchSitePages } from '@/pages/site';

// Lenis instance shared with the scroll-to-top that runs after a page exit.
type LenisLike = { scrollTo: (target: number, options?: { immediate?: boolean }) => void; destroy: () => void };
let lenis: LenisLike | null = null;

export default function PublicLayout() {
  const location = useLocation();
  const navigate = useNavigate();
  const reduced = Boolean(useReducedMotion());
  const outlet = useOutlet();
  const title = curtainTitleFor(location.pathname);

  // Old single-page anchors (/#about, /#apps, …) now live on their own routes.
  useEffect(() => {
    if (location.pathname !== '/' || !location.hash) return;
    const target = pathForLegacyHash(location.hash);
    if (target && target !== '/') navigate(target, { replace: true });
  }, [location.pathname, location.hash, navigate]);

  useEffect(() => {
    prefetchSitePages();
  }, []);

  // Smooth scrolling on desktop only (touch devices keep native momentum scroll).
  useEffect(() => {
    if (reduced || !hasFinePointer()) return;
    let cancelled = false;
    void import('lenis').then(({ default: Lenis }) => {
      if (cancelled) return;
      lenis = new Lenis({ autoRaf: true, lerp: 0.12 });
    });
    return () => {
      cancelled = true;
      lenis?.destroy();
      lenis = null;
    };
  }, [reduced]);

  useEffect(() => {
    if (lenis) lenis.scrollTo(0, { immediate: true });
    else window.scrollTo(0, 0);
  }, [location.pathname]);

  return (
    <LazyMotion features={domAnimation} strict>
      <div className="relative min-h-screen overflow-x-clip bg-[#050505] text-[#F5F5F2]">
        <div aria-hidden className="bg-grain pointer-events-none fixed inset-0 z-0 opacity-[0.035]" />
        <div aria-hidden className="pointer-events-none fixed inset-0 z-0 bg-[radial-gradient(circle_at_72%_6%,rgba(246,180,0,.16),transparent_30%),radial-gradient(circle_at_15%_0%,rgba(255,204,71,.06),transparent_24%)]" />

        <a href="#konten" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[80] focus:rounded-lg focus:bg-[#F6B400] focus:px-4 focus:py-2 focus:text-black">
          Langsung ke konten
        </a>
        <SiteNavbar />

        <main id="konten" className="relative z-10">
          <DestinationTitleContext.Provider value={title}>
            <RevealDelayContext.Provider value={0.05}>
              <m.div key={location.pathname} initial={reduced ? false : { opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.18 }}>
                <Suspense fallback={<HellomLoading mode="inline" label="Menyiapkan halaman…" />}>{outlet}</Suspense>
              </m.div>
            </RevealDelayContext.Provider>
          </DestinationTitleContext.Provider>
        </main>

        <SiteFooter />
      </div>
    </LazyMotion>
  );
}
