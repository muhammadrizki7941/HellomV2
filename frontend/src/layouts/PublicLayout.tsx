import { Suspense, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate, useOutlet } from 'react-router-dom';
import { AnimatePresence, LazyMotion, domAnimation, m, useReducedMotion } from 'framer-motion';
import SiteNavbar from '@/components/site/SiteNavbar';
import SiteFooter from '@/components/site/SiteFooter';
import Preloader, { PRELOADER_DURATION, PRELOADER_SESSION_KEY } from '@/components/site/Preloader';
import {
  CURTAIN_DURATION,
  DestinationTitleContext,
  EASE_CURTAIN,
  RevealDelayContext,
  hasFinePointer,
} from '@/components/site/motion';
import { curtainTitleFor, pathForLegacyHash } from '@/data/siteNav';
import { prefetchSitePages } from '@/pages/site';

// Lenis instance shared with the scroll-to-top that runs after a page exit.
type LenisLike = { scrollTo: (target: number, options?: { immediate?: boolean }) => void; destroy: () => void };
let lenis: LenisLike | null = null;

/** Keeps rendering the route it was mounted with while AnimatePresence plays its exit. */
function FrozenOutlet() {
  const outlet = useOutlet();
  const [frozen] = useState(outlet);
  // Own boundary so a loading page chunk never unmounts the navbar/footer.
  return <Suspense fallback={<div className="min-h-screen" />}>{frozen}</Suspense>;
}

// The very first page render never shows the curtain (the preloader covers first visits).
let firstPageRendered = false;

function PageFrame({ title, reduced }: { title: string; reduced: boolean }) {
  const destination = useContext(DestinationTitleContext);
  const [isFirst] = useState(() => {
    const first = !firstPageRendered;
    firstPageRendered = true;
    return first;
  });

  if (reduced) {
    return (
      <m.div initial={isFirst ? false : { opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }}>
        <FrozenOutlet />
      </m.div>
    );
  }

  return (
    // The wrapper only exists so AnimatePresence waits for the curtain to close.
    <m.div exit={{ opacity: 1 }} transition={{ duration: CURTAIN_DURATION }}>
      <FrozenOutlet />
      <m.div
        aria-hidden
        className="pointer-events-none fixed inset-0 z-[60] flex items-center justify-center border-t border-[#F6B400]/60 bg-[#050505]"
        initial={{ y: isFirst ? '-100%' : '0%' }}
        animate={{ y: '-100%', transition: { duration: CURTAIN_DURATION, ease: EASE_CURTAIN, delay: 0.05 } }}
        exit={{ y: ['100%', '0%'], transition: { duration: CURTAIN_DURATION, ease: EASE_CURTAIN } }}
      >
        <span className="block overflow-hidden">
          <m.span
            className="block font-display text-5xl font-medium text-[#F5F5F2] md:text-8xl"
            initial={{ y: isFirst ? '100%' : '0%', opacity: isFirst ? 0 : 1 }}
            animate={{ y: '-60%', opacity: 0, transition: { duration: 0.35, ease: EASE_CURTAIN } }}
            exit={{ y: ['60%', '0%'], opacity: [0, 1], transition: { duration: 0.4, ease: EASE_CURTAIN, delay: 0.15 } }}
          >
            {/* Exiting frame shows where we are going; entering frame shows its own title. */}
            {destination || title}
          </m.span>
        </span>
      </m.div>
    </m.div>
  );
}

export default function PublicLayout() {
  const location = useLocation();
  const navigate = useNavigate();
  const reduced = Boolean(useReducedMotion());
  const [showPreloader, setShowPreloader] = useState(() => {
    try {
      return !reduced && window.sessionStorage.getItem(PRELOADER_SESSION_KEY) !== '1';
    } catch {
      return false;
    }
  });
  const preloaderShownRef = useRef(showPreloader);
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

  // Scroll to the top only after the old page has left (the curtain covers the jump).
  const onExitComplete = useCallback(() => {
    if (lenis) lenis.scrollTo(0, { immediate: true });
    else window.scrollTo(0, 0);
  }, []);

  // First screen reveals wait for the preloader (first visit) or the lifting curtain.
  const revealDelay = reduced
    ? 0
    : !firstPageRendered
      ? (preloaderShownRef.current ? PRELOADER_DURATION : 0.1)
      : 0.45;

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
            <RevealDelayContext.Provider value={revealDelay}>
              <AnimatePresence mode="wait" onExitComplete={onExitComplete}>
                <PageFrame key={location.pathname} title={title} reduced={reduced} />
              </AnimatePresence>
            </RevealDelayContext.Provider>
          </DestinationTitleContext.Provider>
        </main>

        <SiteFooter />

        <AnimatePresence>
          {showPreloader ? <Preloader key="preloader" onDone={() => setShowPreloader(false)} /> : null}
        </AnimatePresence>
      </div>
    </LazyMotion>
  );
}
