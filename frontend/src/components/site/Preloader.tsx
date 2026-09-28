import { useEffect, useRef } from 'react';
import { m } from 'framer-motion';
import { BrandMark } from './SiteNavbar';
import { EASE_CURTAIN } from './motion';

export const PRELOADER_SESSION_KEY = 'hellom_site_preloaded';
/** Seconds until first-screen reveals start: they begin while the preloader lifts (visible ≈0.8s total). */
export const PRELOADER_DURATION = 0.6;

/** First visit per browser session only: logo + gold progress line, then lifts away. */
export default function Preloader({ onDone }: { onDone: () => void }) {
  // Latest callback without restarting the timer when the parent re-renders.
  const onDoneRef = useRef(onDone);
  onDoneRef.current = onDone;

  useEffect(() => {
    try {
      window.sessionStorage.setItem(PRELOADER_SESSION_KEY, '1');
    } catch {
      /* private mode: show it again next time, harmless */
    }
    const id = window.setTimeout(() => onDoneRef.current(), 560);
    return () => window.clearTimeout(id);
  }, []);

  return (
    <m.div
      className="fixed inset-0 z-[70] flex flex-col items-center justify-center bg-[#050505]"
      exit={{ y: '-100%' }}
      transition={{ duration: 0.25, ease: EASE_CURTAIN }}
      role="status"
      aria-label="Memuat Hellom"
    >
      <m.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
        <BrandMark className="h-10" />
      </m.div>
      <div className="mt-8 h-px w-40 overflow-hidden bg-white/[0.10]">
        <m.div
          className="h-full w-full origin-left bg-[#F6B400]"
          initial={{ scaleX: 0 }}
          animate={{ scaleX: 1 }}
          transition={{ duration: 0.5, ease: EASE_CURTAIN }}
        />
      </div>
    </m.div>
  );
}
