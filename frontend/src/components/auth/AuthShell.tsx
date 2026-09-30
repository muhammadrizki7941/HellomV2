import { useState } from 'react';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import useBrand from '@/hooks/useBrand';
import { BRAND_LOGO_PATH } from '@/lib/branding';

// Shared frame of the account pages (register, invitation, reset password): Hellom logo,
// one centered column on near-black, gold accent. Deliberately plain.
export function AuthShell({ children, wide = false, aside }: { children: ReactNode; wide?: boolean; aside?: ReactNode }) {
  const { brand, logoSrc } = useBrand();

  return (
    <div className="flex min-h-[100svh] flex-col bg-[#050505] text-[#F5F5F2]">
      <header className="flex items-center justify-between px-6 py-5">
        <Link to="/" className="inline-flex min-h-11 items-center gap-2.5" aria-label={`${brand.app_name || 'Hellom'} — beranda`}>
          <img src={logoSrc || BRAND_LOGO_PATH} alt="" className="h-8 w-8 rounded-lg object-contain" draggable={false} />
          <span className="text-lg font-black tracking-tight">Hell<span className="text-[#F6B400]">om</span></span>
        </Link>
        {aside}
      </header>
      <main className="flex flex-1 items-start justify-center px-5 pb-12 pt-4 sm:items-center">
        <section className={cn('w-full', wide ? 'max-w-md' : 'max-w-sm')}>{children}</section>
      </main>
      <footer className="px-6 pb-6 text-center text-xs text-white/35">{brand.footer_text || `© ${new Date().getFullYear()} Hellom`}</footer>
    </div>
  );
}

export const authInputClass =
  'h-12 w-full rounded-xl border border-white/[0.12] bg-white/[0.03] px-4 text-base text-white placeholder:text-white/30 transition focus:border-[#F6B400] focus:outline-none focus:ring-4 focus:ring-[#F6B400]/20 read-only:cursor-default read-only:text-white/70 aria-[invalid=true]:border-rose-400/60';

export function AuthField({ label, hint, error, id, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: ReactNode; error?: string | null; id: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-2 block text-sm font-medium text-white/80">{label}</label>
      <input id={id} aria-invalid={error ? true : undefined} className={authInputClass} {...props} />
      {error ? <p className="mt-1.5 text-xs text-rose-300">{error}</p> : hint ? <p className="mt-1.5 text-xs text-white/45">{hint}</p> : null}
    </div>
  );
}

export function PasswordField({ label, id, error, hint, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; id: string; error?: string | null; hint?: ReactNode }) {
  const [show, setShow] = useState(false);

  return (
    <div>
      <label htmlFor={id} className="mb-2 block text-sm font-medium text-white/80">{label}</label>
      <div className="relative">
        <input id={id} type={show ? 'text' : 'password'} aria-invalid={error ? true : undefined} className={`${authInputClass} pr-12`} {...props} />
        <button type="button" onClick={() => setShow(!show)} aria-label={show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
          className="absolute right-1 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-white/50 hover:text-white">
          {show ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
        </button>
      </div>
      {error ? <p className="mt-1.5 text-xs text-rose-300">{error}</p> : hint ? <p className="mt-1.5 text-xs text-white/45">{hint}</p> : null}
    </div>
  );
}

export function AuthAlert({ tone = 'error', children }: { tone?: 'error' | 'success' | 'info'; children: ReactNode }) {
  return (
    <p role={tone === 'error' ? 'alert' : 'status'} className={cn('rounded-xl border px-4 py-3 text-sm',
      tone === 'error' && 'border-rose-400/30 bg-rose-500/10 text-rose-200',
      tone === 'success' && 'border-emerald-400/30 bg-emerald-500/10 text-emerald-200',
      tone === 'info' && 'border-[#F6B400]/25 bg-[#F6B400]/[0.07] text-[#F5F5F2]')}>
      {children}
    </p>
  );
}

export const authPrimaryButton =
  'flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#F6B400] text-base font-bold text-[#050505] transition-colors hover:bg-[#FFCC47] focus:outline-none focus-visible:ring-4 focus-visible:ring-[#F6B400]/40 disabled:cursor-not-allowed disabled:opacity-60';
