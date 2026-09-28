import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowDown, ArrowRight } from 'lucide-react';
import MagneticLink, { primaryCta } from './MagneticLink';
import { Reveal, RevealText } from './Reveal';
import { prefersReducedMotion } from './motion';

/**
 * Intro video (landscape 16:9) in  frontend/public/assets/intro/  (see docs).
 * Missing files are fine: the section falls back to the poster, then to a dark gradient.
 */
const INTRO = {
  video: '/assets/intro/intro-landscape.mp4',
  poster: '/assets/intro/intro-landscape.webp',
} as const;

/** Autoplay only when motion is welcome and the visitor is not saving data. */
function canAutoplay(): boolean {
  if (typeof window === 'undefined' || prefersReducedMotion()) return false;
  const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection;
  return !connection?.saveData;
}

/**
 * Intro at the top of the home page.
 * - Desktop/tablet: the video fills the screen behind the content.
 * - Phone: the video stays landscape — a cinematic 16:9 frame in the upper part of the
 *   screen with edges fading into black, and the title card below it.
 * The video layer never takes pointer events, so everything in front stays clickable.
 */
export default function IntroVideo({ nextSectionId }: { nextSectionId: string }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const sectionRef = useRef<HTMLElement>(null);
  const [failed, setFailed] = useState(false);
  const [playing, setPlaying] = useState(false);
  const autoplay = canAutoplay();

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
  }, [autoplay, failed]);

  const scrollToNext = () => {
    document.getElementById(nextSectionId)?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
  };

  return (
    <section
      ref={sectionRef}
      aria-label="Perkenalan Hellom"
      className="relative flex min-h-[100svh] flex-col overflow-hidden border-b border-white/[0.08] bg-[#050505] md:h-[100svh] md:min-h-[560px]"
    >
      {/* Background layer — decorative, never interactive.
          Phone: in the flow at the top (so it can never overlap the text); md+: behind everything. */}
      <div aria-hidden className="pointer-events-none relative mt-[max(6rem,13svh)] select-none md:absolute md:inset-0 md:mt-0">
        {/* Phone: cinematic 16:9 frame; md+: full-bleed */}
        <div
          className="relative aspect-video w-full bg-[radial-gradient(circle_at_60%_40%,rgba(246,180,0,.14),transparent_55%),#0b0b0e]
            [mask-image:linear-gradient(to_bottom,transparent,black_8%,black_92%,transparent)]
            md:absolute md:inset-0 md:aspect-auto md:bg-[radial-gradient(circle_at_60%_30%,rgba(246,180,0,.16),transparent_45%),linear-gradient(180deg,#0b0b0e,#050505)] md:[mask-image:none]"
        >
          {!failed ? (
            <video
              ref={videoRef}
              className={`h-full w-full object-cover transition-opacity duration-[1200ms] ease-out ${playing || !autoplay ? 'opacity-100' : 'opacity-0'}`}
              src={INTRO.video}
              poster={INTRO.poster}
              autoPlay={autoplay}
              muted
              loop
              playsInline
              preload={autoplay ? 'auto' : 'none'}
              disablePictureInPicture
              tabIndex={-1}
              onPlaying={() => setPlaying(true)}
              onError={() => setFailed(true)}
            />
          ) : null}
        </div>
        {/* Phone: hairlines framing the shot like a widescreen cut (outside the fade mask) */}
        <span className="absolute inset-x-[10%] -top-3 h-px bg-gradient-to-r from-transparent via-[#F6B400]/40 to-transparent md:hidden" />
        <span className="absolute inset-x-[10%] -bottom-3 h-px bg-gradient-to-r from-transparent via-[#F6B400]/40 to-transparent md:hidden" />
        {/* md+: light scrim so the text reads without muddying the footage */}
        <div className="absolute inset-0 hidden bg-[linear-gradient(180deg,rgba(5,5,5,.55)_0%,rgba(5,5,5,0)_28%,rgba(5,5,5,0)_52%,rgba(5,5,5,.88)_100%)] md:block" />
      </div>

      {/* Foreground — fully interactive */}
      <div className="relative z-10 mt-auto w-full px-5 pb-10 pt-10 md:px-10 md:pb-16 md:pt-0 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <Reveal onMount>
            <p className="mb-5 text-[11px] font-bold uppercase tracking-[0.4em] text-[#F6B400]">Partner kreatif untuk bisnismu</p>
          </Reveal>
          <RevealText
            lines={['Bangun brand,', 'sistem, dan', <span key="p" className="text-[#F6B400]">produk digitalmu.</span>]}
            className="max-w-4xl font-display text-[2.6rem] font-medium leading-[1.02] text-[#F5F5F2] sm:text-7xl lg:text-8xl"
          />
          <Reveal onMount delay={0.3} className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center md:mt-9">
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
