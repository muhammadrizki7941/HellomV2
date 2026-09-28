import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowDown, ArrowRight } from 'lucide-react';
import MagneticLink, { primaryCta } from './MagneticLink';
import { Reveal, RevealText } from './Reveal';
import { prefersReducedMotion } from './motion';

/**
 * Intro video (landscape 16:9) in  frontend/public/assets/intro/  (see docs).
 * Missing files are fine: the section falls back to the poster, then to a dark frame.
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
 * Intro at the top of the home page, laid out like a film title card on every screen:
 * the video plays whole inside a 16:9 frame (edges fading into black, thin gold
 * hairlines) and the title + actions sit below it, so nothing covers the footage or
 * its burned-in subtitles. On desktop the frame is sized from the viewport height so
 * frame and title card always fit on one screen. The video never takes pointer events.
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
      className="relative flex min-h-[100svh] flex-col overflow-hidden border-b border-white/[0.08] bg-[#050505]"
    >
      {/* Soft ambient glow behind the frame */}
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_50%_32%,rgba(246,180,0,.10),transparent_55%)]" />

      {/* Film frame — decorative, never interactive */}
      <div
        aria-hidden
        className="pointer-events-none relative mx-auto mt-[max(6rem,13svh)] w-full select-none md:mt-24
          md:w-[clamp(24rem,calc((100svh_-_34rem)*1.7778),min(88vw,76rem))] xl:w-[clamp(28rem,calc((100svh_-_27.5rem)*1.7778),min(88vw,76rem))]"
      >
        <div
          className="relative aspect-video w-full bg-[radial-gradient(circle_at_60%_40%,rgba(246,180,0,.14),transparent_55%),#0b0b0e]
            [mask-image:linear-gradient(to_bottom,transparent,black_8%,black_92%,transparent)]"
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
        {/* Hairlines framing the shot like a widescreen cut (outside the fade mask) */}
        <span className="absolute inset-x-[10%] -top-3 h-px bg-gradient-to-r from-transparent via-[#F6B400]/40 to-transparent" />
        <span className="absolute inset-x-[10%] -bottom-3 h-px bg-gradient-to-r from-transparent via-[#F6B400]/40 to-transparent" />
      </div>

      {/* Title card — fully interactive */}
      <div className="relative z-10 mt-auto w-full px-5 pb-10 pt-10 md:px-10 md:pb-14 lg:px-16">
        <div className="mx-auto flex max-w-[1500px] flex-col gap-8 xl:flex-row xl:items-end xl:justify-between">
          <div>
            <Reveal onMount>
              <p className="mb-5 text-[11px] font-bold uppercase tracking-[0.4em] text-[#F6B400]">Partner kreatif untuk bisnismu</p>
            </Reveal>
            <RevealText
              lines={['Bangun brand,', 'sistem, dan', <span key="p" className="text-[#F6B400]">produk digitalmu.</span>]}
              className="max-w-4xl font-display text-[2.6rem] font-medium leading-[1.02] text-[#F5F5F2] sm:text-5xl lg:text-6xl"
            />
          </div>
          <Reveal onMount delay={0.3} className="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center">
            <MagneticLink to="/aplikasi" className={primaryCta}>
              Lihat Aplikasi <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
            </MagneticLink>
            <Link
              to="/kontak"
              className="inline-flex h-14 items-center justify-center gap-3 rounded-lg border border-white/25 bg-black/20 px-8 text-sm font-bold text-[#F5F5F2] transition-colors hover:border-[#F6B400]"
            >
              Ngobrol dengan kami
            </Link>
          </Reveal>
        </div>
      </div>

      <button
        type="button"
        onClick={scrollToNext}
        className="absolute bottom-4 left-1/2 z-10 hidden min-h-11 -translate-x-1/2 items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.3em] text-[#F5F5F2]/70 transition-colors hover:text-[#F6B400] xl:inline-flex"
      >
        Gulir <ArrowDown aria-hidden className="h-4 w-4" />
      </button>
    </section>
  );
}
