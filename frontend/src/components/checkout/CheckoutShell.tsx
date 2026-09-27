import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ShieldCheck } from 'lucide-react';
import useBrand from '@/hooks/useBrand';

// Minimal public page frame for guest checkout pages (dark theme, same palette as the landing page).
export default function CheckoutShell({ children }: { children: ReactNode }) {
  const { brand, logoSrc } = useBrand();

  return (
    <div className="min-h-screen bg-[#050505] text-[#F5F5F2]">
      <header className="border-b border-white/[0.06]">
        <div className="mx-auto flex h-16 max-w-5xl items-center justify-between px-4">
          <Link to="/" className="flex items-center gap-3">
            {logoSrc ? (
              <img src={logoSrc} alt={brand.app_name} className="h-8 w-auto max-w-[120px] object-contain" />
            ) : (
              <span className="text-lg font-bold">{brand.app_name}</span>
            )}
          </Link>
          <span className="inline-flex items-center gap-2 text-xs text-[#8B8B90]">
            <ShieldCheck className="h-4 w-4 text-[#F6B400]" /> Checkout aman
          </span>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-8 sm:py-12">{children}</main>
      <footer className="border-t border-white/[0.06] py-6 text-center text-xs text-[#8B8B90]">
        {brand.footer_text || brand.app_name}
      </footer>
    </div>
  );
}

export const panelClass =
  'rounded-[24px] border border-white/[0.08] bg-white/[0.035] p-5 shadow-[0_24px_80px_rgba(0,0,0,0.35)] sm:p-7';

export const inputClass =
  'h-[52px] w-full rounded-2xl border border-white/[0.10] bg-black/35 px-4 text-[15px] text-white placeholder:text-white/35 focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/15';

export const primaryButtonClass =
  'flex h-[52px] w-full items-center justify-center gap-2 rounded-2xl bg-[#F6B400] text-sm font-bold text-[#050505] transition hover:bg-[#FFCC47] disabled:cursor-not-allowed disabled:opacity-50';

export const formatRupiah = (value: number) => `Rp ${Number(value || 0).toLocaleString('id-ID')}`;
