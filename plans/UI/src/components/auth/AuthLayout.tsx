import type { ReactNode } from 'react';
import type { BrandSettings } from '@/hooks/useBrand';
import { AuthLeftPanel } from './AuthLeftPanel';
import { PromoBanner } from './PromoBanner';
import { usePromoBanners } from './usePromoBanners';

type AuthLayoutProps = {
  brand: BrandSettings;
  logoSrc?: string | null;
  variant: 'login' | 'register';
  children: ReactNode;
  footerText?: string | null;
};

export const AuthLayout = ({ brand, logoSrc, variant, children, footerText }: AuthLayoutProps) => {
  const { items } = usePromoBanners('header');

  return (
    <div className="relative min-h-screen overflow-hidden bg-[#050505] text-[#F5F5F2]">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -top-40 left-1/2 h-[560px] w-[560px] -translate-x-1/2 rounded-full bg-[#F6B400]/12 blur-[130px]" />
        <div className="absolute bottom-0 right-0 h-[460px] w-[460px] rounded-full bg-[#FFCC47]/8 blur-[150px]" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_15%_10%,rgba(246,180,0,.10),transparent_30%),linear-gradient(180deg,transparent,rgba(0,0,0,.55))]" />
      </div>

      <div className="relative mx-auto w-full max-w-[1440px] px-4 py-8 sm:px-6 lg:px-10 lg:py-12">
        <div className="mb-8 flex items-center justify-between">
          <a href="/" className="text-2xl font-black tracking-normal text-white">
            Hell<span className="text-[#F6B400]">om</span>
          </a>
          <a href="/" className="rounded-lg border border-white/[0.10] px-4 py-2 text-xs font-bold text-white/70 transition hover:border-[#F6B400]/40 hover:text-white">
            Back Home
          </a>
        </div>

        <div className="lg:hidden">
          <PromoBanner items={items} variant={variant} />
        </div>

        <div className="mt-6 grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
          <AuthLeftPanel brand={brand} logoSrc={logoSrc} variant={variant} />
          <div className="flex justify-center lg:justify-end">
            <div className="w-full max-w-[480px]">
              {children}
            </div>
          </div>
        </div>

        <div className="mt-8 text-center text-xs text-white/50 lg:text-left">
          {footerText || '© 2026 Hellom. All rights reserved.'}
        </div>
      </div>
    </div>
  );
};
