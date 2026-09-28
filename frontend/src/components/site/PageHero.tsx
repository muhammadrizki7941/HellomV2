import type { ReactNode } from 'react';
import useBrand from '@/hooks/useBrand';
import useSeo from '@/hooks/useSeo';
import { Reveal, RevealText } from './Reveal';

export const SectionLabel = ({ children }: { children: ReactNode }) => (
  <p className="mb-5 text-[11px] font-bold uppercase tracking-[0.4em] text-[#F6B400]">{children}</p>
);

/** Page title + meta description (+ canonical/OG) for a public page. */
export function usePageMeta(title: string, description: string, path: string) {
  const { brand } = useBrand();
  const brandName = brand.app_name || brand.business_name || 'Hellom';
  useSeo({
    title: path === '/' ? `${brandName} | ${title}` : `${title} | ${brandName}`,
    description,
    url: path,
    type: 'website',
  });
}

/** Standard hero for inner pages: eyebrow, masked title lines, intro, optional actions. */
export default function PageHero({
  eyebrow,
  lines,
  intro,
  children,
  compact = false,
}: {
  eyebrow: string;
  lines: ReactNode[];
  intro?: ReactNode;
  children?: ReactNode;
  compact?: boolean;
}) {
  return (
    <section className={`border-b border-white/[0.08] px-5 md:px-10 lg:px-16 ${compact ? 'pb-12 pt-32 md:pt-36' : 'pb-16 pt-36 md:pb-24 md:pt-44'}`}>
      <div className="mx-auto max-w-[1500px]">
        <Reveal onMount>
          <SectionLabel>{eyebrow}</SectionLabel>
        </Reveal>
        <RevealText
          lines={lines}
          className={`max-w-5xl font-display font-medium leading-[1.02] text-[#F5F5F2] ${compact ? 'text-4xl md:text-6xl' : 'text-5xl md:text-7xl lg:text-8xl'}`}
        />
        {intro ? (
          <Reveal onMount delay={0.25}>
            <p className="mt-7 max-w-2xl text-base leading-8 text-[#A1A1A6]">{intro}</p>
          </Reveal>
        ) : null}
        {children ? (
          <Reveal onMount delay={0.35} className="mt-9">
            {children}
          </Reveal>
        ) : null}
      </div>
    </section>
  );
}

/** Mid-grey that still passes WCAG AA (≥ 4.5:1) on #050505. */
export const MUTED_TEXT = 'text-[#A1A1A6]';
