import { type ElementType, type ReactNode, useEffect, useState } from 'react';
import { m, useReducedMotion } from 'framer-motion';
import { EASE_REVEAL, LINE_STAGGER, useRevealDelay } from './motion';

type HeadingTag = 'h1' | 'h2' | 'h3' | 'p';

/**
 * Hero title revealed line by line: each line slides up from behind a mask
 * (overflow hidden, translateY 100% → 0), staggered.
 */
export function RevealText({
  lines,
  as = 'h1',
  className = '',
  delay = 0,
  inView = false,
}: {
  lines: ReactNode[];
  as?: HeadingTag;
  className?: string;
  delay?: number;
  /** Play when scrolled into view (once) instead of on mount — for titles below the fold. */
  inView?: boolean;
}) {
  const Tag = as as ElementType;
  const pageDelay = useRevealDelay();
  const baseDelay = (inView ? 0 : pageDelay) + delay;
  const reduced = useReducedMotion();
  const shown = reduced ? { opacity: 1 } : { y: '0%' };

  return (
    <Tag className={className}>
      {lines.map((line, index) => (
        <span key={index} className="block overflow-hidden pb-[0.08em]">
          <m.span
            className="block"
            initial={reduced ? { opacity: 0 } : { y: '100%' }}
            {...(inView
              ? { whileInView: shown, viewport: { once: true, margin: '-10% 0px' } }
              : { animate: shown })}
            transition={reduced
              ? { duration: 0.15 }
              : { duration: 0.9, ease: EASE_REVEAL, delay: baseDelay + index * LINE_STAGGER }}
          >
            {line}
          </m.span>
        </span>
      ))}
    </Tag>
  );
}

/**
 * Fade + rise (24px). `onMount` plays right away (first screen, after the curtain);
 * otherwise it plays once when the element scrolls into view.
 */
export function Reveal({
  children,
  className = '',
  delay = 0,
  onMount = false,
  as = 'div',
}: {
  children: ReactNode;
  className?: string;
  delay?: number;
  onMount?: boolean;
  as?: 'div' | 'section' | 'li' | 'article';
}) {
  const reduced = useReducedMotion();
  const pageDelay = useRevealDelay();
  // Sections already on screen when the page arrives wait for the hero like everything
  // else; once the page has settled, scroll reveals start without that extra delay.
  const [arrivalDelay, setArrivalDelay] = useState(pageDelay);
  useEffect(() => {
    if (onMount || arrivalDelay === 0) return;
    const id = window.setTimeout(() => setArrivalDelay(0), (pageDelay + 0.3) * 1000);
    return () => window.clearTimeout(id);
  }, [arrivalDelay, onMount, pageDelay]);
  const Component = m[as];
  const hidden = reduced ? { opacity: 0 } : { opacity: 0, y: 24 };
  const shown = reduced ? { opacity: 1 } : { opacity: 1, y: 0 };
  const transition = reduced
    ? { duration: 0.15 }
    : { duration: 0.8, ease: EASE_REVEAL, delay: (onMount ? pageDelay : arrivalDelay) + delay };

  return onMount ? (
    <Component className={className} initial={hidden} animate={shown} transition={transition}>
      {children}
    </Component>
  ) : (
    <Component
      className={className}
      initial={hidden}
      whileInView={shown}
      viewport={{ once: true, margin: '-10% 0px' }}
      transition={transition}
    >
      {children}
    </Component>
  );
}
