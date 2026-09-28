import { useRef, type PointerEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { m, useMotionValue, useReducedMotion, useSpring } from 'framer-motion';
import { hasFinePointer } from './motion';

const MAX_OFFSET = 6; // px — a hint of pull, not a gimmick

/**
 * Primary call-to-action with a subtle magnetic pull toward the cursor.
 * Desktop (fine pointer) only; disabled for reduced motion.
 */
export default function MagneticLink({
  to,
  href,
  children,
  className = '',
  external = false,
}: {
  to?: string;
  href?: string;
  children: ReactNode;
  className?: string;
  external?: boolean;
}) {
  const ref = useRef<HTMLSpanElement>(null);
  const reduced = useReducedMotion();
  const x = useSpring(useMotionValue(0), { stiffness: 220, damping: 18, mass: 0.4 });
  const y = useSpring(useMotionValue(0), { stiffness: 220, damping: 18, mass: 0.4 });
  const enabled = !reduced && hasFinePointer();

  const onMove = (event: PointerEvent) => {
    if (!enabled || !ref.current) return;
    const rect = ref.current.getBoundingClientRect();
    const dx = (event.clientX - (rect.left + rect.width / 2)) / (rect.width / 2);
    const dy = (event.clientY - (rect.top + rect.height / 2)) / (rect.height / 2);
    x.set(dx * MAX_OFFSET);
    y.set(dy * MAX_OFFSET);
  };
  const reset = () => {
    x.set(0);
    y.set(0);
  };

  const inner = (
    <m.span ref={ref} style={enabled ? { x, y } : undefined} className="inline-flex">
      <span className={className}>{children}</span>
    </m.span>
  );

  if (href) {
    return (
      <a href={href} onPointerMove={onMove} onPointerLeave={reset} className="inline-flex" {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
        {inner}
      </a>
    );
  }

  return (
    <Link to={to ?? '/'} onPointerMove={onMove} onPointerLeave={reset} className="inline-flex">
      {inner}
    </Link>
  );
}

export const primaryCta =
  'group inline-flex h-14 items-center justify-center gap-3 rounded-lg bg-[#F6B400] px-8 text-sm font-bold text-black shadow-[0_0_32px_rgba(246,180,0,.22)] transition-colors hover:bg-[#FFCC47] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#F6B400] focus-visible:ring-offset-2 focus-visible:ring-offset-[#050505]';

export const secondaryCta =
  'inline-flex h-14 items-center justify-center gap-3 rounded-lg border border-white/[0.12] bg-white/[0.03] px-8 text-sm font-bold text-[#F5F5F2] transition-colors hover:border-[#F6B400] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#F6B400]';
