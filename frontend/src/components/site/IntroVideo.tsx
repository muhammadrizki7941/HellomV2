import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowDown, ArrowRight } from 'lucide-react';
import MagneticLink, { primaryCta } from './MagneticLink';
import { Reveal, RevealText } from './Reveal';
import { prefersReducedMotion } from './motion';

/**
 * Intro video files. Put them in  frontend/public/assets/intro/  (see docs):
 * - portrait  : shown on phones held upright (9:16)
 * - landscape : shown everywhere else (16:9)
 * Missing files are fine: the section falls back to the poster, then to the
 * other orientation, then to a plain dark gradient.
 */
const INTRO = {
  portrait: { video: '/assets/intro/intro-portrait.mp4', poster: '/assets/intro/intro-portrait.webp' },
  landscape: { video: '/assets/intro/intro-landscape.mp4', poster: '/assets/intro/intro-landscape.webp' },
} as const;

type Orientation = keyof typeof INTRO;

const PORTRAIT_QUERY = '(orientation: portrait) and (max-width: 1024px)';

function currentOrientation(): Orientation {
  return typeof window !== 'undefined' && window.matchMedia(PORTRAIT_QUERY).matches ? 'portrait' : 'landscape';
}

/** Autoplay only when motion is welcome and the visitor is not saving data. */
function canAutoplay(): boolean {
  if (typeof window === 'undefined' || prefersReducedMotion()) return false;
  const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection;
  return !connection?.saveData;
}

/**
 * Full-screen intro at the top of the home page: a clean background video with the
 * content layered on top. The video never takes pointer events, so every button and
 * link in front of it stays clickable.
 */
export default function IntroVideo({ nextSectionId }: { nextSectionId: string }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const sectionRef = useRef<HTMLElement>(null);
  const [orientation, setOrientation] = useState<Orientation>(currentOrientation);
  const [failed, setFailed] = useState<Record<Orientation, boolean>>({ portrait: false, landscape: false });
  const [playing, setPlaying] = useState(false);
  const autoplay = canAutoplay();

  // Portrait file if it exists, otherwise the landscape one (object-cover crops it).
  const source: Orientation | null = !failed[orientation]
    ? orientation
    : !failed[orientation === 'portrait' ? 'landscape' : 'portrait']
      ? (orientation === 'portrait' ? 'landscape' : 'portrait')
      : null;

  // Follow device rotation.
  useEffect(() => {
    const media = window.matchMedia(PORTRAIT_QUERY);
    const onChange = () => setOrientation(currentOrientation());
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
  }, []);

  // A new file fades in again once it actually plays.
  useEffect(() => setPlaying(false), [source]);

  // Play only while the intro is on screen; pause to save battery/CPU otherwise.
  useEffect(() => {
    const video = videoRef.current;
    const section = sectionRef.current;
    if (!video || !section || !autoplay) return;
    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) void video.play().catch(() => undefined);
      else video.pause();
    }, { threshold: 0.1 });
    observer.observe(section);
    return () => observer.disconnect();
  }, [autoplay, source]);

  const scrollToNext = () => {
    document.getElementById(nextSectionId)?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
  };

  return (
    <section
      ref={sectionRef}
      aria-label="Perkenalan Hellom"
      className="relative flex h-[100svh] min-h-[560px] items-end overflow-hidden border-b border-white/[0.08]"
    >
      {/* Background layer — decorative, never interactive */}
      <div aria-hidden className="pointer-events-none absolute inset-0 select-none bg-[radial-gradient(circle_at_60%_30%,rgba(246,180,0,.16),transparent_45%),linear-gradient(180deg,#0b0b0e,#050505)]">
        {source ? (
          <video
            key={source}
            ref={videoRef}
            className={`h-full w-full object-cover transition-opacity duration-[1200ms] ease-out ${playing || !autoplay ? 'opacity-100' : 'opacity-0'}`}
            src={INTRO[source].video}
            poster={INTRO[source].poster}
            autoPlay={autoplay}
            muted
            loop
            playsInline
            preload={autoplay ? 'auto' : 'none'}
            disablePictureInPicture
            tabIndex={-1}
            onPlaying={() => setPlaying(true)}
            onError={() => setFailed((current) => ({ ...current, [source]: true }))}
          />
        ) : null}
        {/* Light scrim: keeps text readable without muddying the footage */}
        <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(5,5,5,.55)_0%,rgba(5,5,5,0)_28%,rgba(5,5,5,0)_52%,rgba(5,5,5,.88)_100%)]" />
      </div>

      {/* Foreground — fully interactive */}
      <div className="relative z-10 w-full px-5 pb-12 md:px-10 md:pb-16 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <Reveal onMount>
            <p className="mb-5 text-[11px] font-bold uppercase tracking-[0.4em] text-[#F6B400]">Partner kreatif untuk bisnismu</p>
          </Reveal>
          <RevealText
            lines={['Bangun brand,', 'sistem, dan', <span key="p" className="text-[#F6B400]">produk digitalmu.</span>]}
            className="max-w-4xl font-display text-[2.9rem] font-medium leading-[1.02] text-[#F5F5F2] sm:text-7xl lg:text-8xl"
          />
          <Reveal onMount delay={0.3} className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
            <MagneticLink to="/aplikasi" className={primaryCta}>
              Lihat Aplikasi <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
            </MagneticLink>
            <Link
              to="/kontak"
              className="inline-flex h-14 items-center justify-center gap-3 rounded-lg border border-white/25 bg-black/20 px-8 text-sm font-bold text-[#F5F5F2] backdrop-blur-sm transition-colors hover:border-[#F6B400]"
            >
              Ngobrol dengan kami
            </Link>
          </Reveal>
        </div>
      </div>

      <button
        type="button"
        onClick={scrollToNext}
        className="absolute bottom-6 right-5 z-10 hidden min-h-11 items-center gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-[#F5F5F2]/80 transition-colors hover:text-[#F6B400] md:right-10 md:inline-flex lg:right-16"
      >
        Gulir <ArrowDown aria-hidden className="h-4 w-4" />
      </button>
    </section>
  );
}
