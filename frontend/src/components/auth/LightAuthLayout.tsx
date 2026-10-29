import { useState, type InputHTMLAttributes, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowUpRight, Eye, EyeOff, Store, ShoppingBag, LayoutGrid } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import './light-auth.css';

export function LightAuthLayout({ children, aside, variant = 'register' }: {
  children: ReactNode; aside?: ReactNode; wide?: boolean; variant?: 'register' | 'login' | 'recovery';
}) {
  const { brand } = useBrand();
  return (
    <div className="light-auth min-h-[100svh] bg-[#faf9f6] font-sans text-zinc-900" style={{ colorScheme: 'light' }}>
      <header className="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-4 px-5 py-5 sm:px-10 lg:px-14">
        <Link to="/" aria-label="Hellom — beranda" className="flex min-h-11 items-center gap-2 text-2xl font-black tracking-tight">
          <img src="/assets/hellom.png" width={40} height={40} alt="" className="h-10 w-10 object-contain mix-blend-multiply" />
          hellom<span className="-ml-2 text-amber-500">.</span>
        </Link>
        <div className="flex items-center gap-5 text-sm text-zinc-600">
          <Link to="/" className="hidden min-h-11 items-center gap-1.5 hover:text-zinc-900 sm:inline-flex"><ArrowLeft size={15} /> Beranda</Link>
          {aside}
        </div>
      </header>
      <main className="mx-auto grid w-full max-w-[1320px] items-center gap-7 px-5 pb-8 sm:px-10 lg:grid-cols-[1.1fr_1fr] lg:gap-16 lg:px-12 lg:py-6">
        <aside className="auth-illustration relative overflow-hidden rounded-[28px] border border-amber-100 bg-[#fffcf4] lg:sticky lg:top-8 lg:self-start">
          <div className="px-6 pt-6 sm:px-9 sm:pt-8">
            <span className="inline-flex items-center gap-2 rounded-full border border-amber-200/70 bg-white/80 px-3 py-1.5 text-[11px] font-semibold tracking-wide text-amber-900"><span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> YOUR CREATIVE BUSINESS PARTNER</span>
            <h2 className="mt-5 max-w-md text-3xl font-semibold leading-[1.16] tracking-tight sm:text-4xl lg:text-[44px]">Ide besar dimulai<br />dari <span className="text-amber-600">langkah kecil.</span></h2>
            <p className="mt-4 max-w-sm text-sm leading-6 text-zinc-600">Dari toko pertama hingga bisnis yang terus tumbuh. Kelola semuanya bersama Hellom.</p>
          </div>
          <img src="/images/auth/hellom-business.png" alt="Maskot Hellom membantu berjualan, mengelola toko, dan berbelanja" width={1536} height={1024} fetchPriority="high" className="mt-4 block w-full mix-blend-multiply" />
          <div className="grid grid-cols-3 gap-2 px-5 pb-6 sm:px-8">
            {[{ Icon: Store, label: 'POS Kasir' }, { Icon: ShoppingBag, label: 'Produk digital' }, { Icon: LayoutGrid, label: 'Halaman toko' }].map(({ Icon, label }) => (
              <div key={label} className="flex flex-col items-center gap-2 rounded-2xl border border-white bg-white/70 px-2 py-3 text-center text-xs font-medium text-zinc-700"><Icon size={18} className="text-amber-700" />{label}</div>
            ))}
          </div>
        </aside>
        <section className="auth-form-enter mx-auto w-full max-w-[480px] rounded-[28px] border border-zinc-200/70 bg-white p-6 shadow-[0_18px_65px_-35px_rgba(65,45,0,0.22)] sm:p-9" aria-label={variant === 'login' ? 'Masuk akun' : variant === 'recovery' ? 'Pemulihan akun' : 'Pendaftaran akun'}>
          <div className="-mx-2 mb-5 flex items-center gap-3 rounded-2xl bg-[#fffcf4] p-3 lg:hidden">
            <img src="/images/auth/hellom-business.png" alt="Ilustrasi maskot Hellom" width={1536} height={1024} className="w-[46%] rounded-lg mix-blend-multiply" />
            <p className="text-sm font-semibold leading-5 text-zinc-700">Teman kreatif<br />untuk setiap<br /><span className="text-amber-700">langkah usahamu.</span></p>
          </div>
          <div className="mb-5 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700"><span className="h-px w-5 bg-amber-500" />{variant === 'login' ? 'Selamat datang kembali' : variant === 'recovery' ? 'Kami bantu kembali masuk' : 'Ruang baru untuk usahamu'}</div>
          {children}
        </section>
      </main>
      <footer className="mx-auto flex max-w-[1320px] flex-wrap items-center justify-center gap-x-6 gap-y-2 px-5 py-6 text-xs text-zinc-500 sm:justify-between sm:px-12">
        <span>{brand.footer_text || `© ${new Date().getFullYear()} Hellom. Your creative business partner.`}</span>
        <Link to="/terms" className="inline-flex min-h-11 items-center gap-1 hover:text-zinc-800">Syarat & ketentuan <ArrowUpRight size={13} /></Link>
      </footer>
    </div>
  );
}

const inputClass = 'h-12 w-full rounded-xl border border-zinc-200 bg-zinc-50/60 px-4 text-base text-zinc-900 placeholder:text-zinc-400 transition focus:border-amber-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-amber-100 aria-[invalid=true]:border-rose-400 disabled:opacity-60';
type FieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: ReactNode; error?: string | null; id: string };

export function LightAuthField({ label, hint, error, id, ...props }: FieldProps) {
  return <div><label htmlFor={id} className="mb-2 block text-sm font-medium text-zinc-700">{label}</label>
    <input {...props} id={id} aria-invalid={error ? true : undefined} aria-describedby={error || hint ? `${id}-help` : undefined} className={inputClass} />
    {(error || hint) && <p id={`${id}-help`} className={`mt-1.5 text-xs leading-5 ${error ? 'text-rose-700' : 'text-zinc-500'}`}>{error || hint}</p>}
  </div>;
}

export function LightPasswordField({ label, id, error, hint, ...props }: FieldProps) {
  const [show, setShow] = useState(false);
  return <div><label htmlFor={id} className="mb-2 block text-sm font-medium text-zinc-700">{label}</label>
    <div className="relative"><input {...props} id={id} type={show ? 'text' : 'password'} aria-invalid={error ? true : undefined} aria-describedby={error || hint ? `${id}-help` : undefined} className={`${inputClass} pr-12`} />
      <button type="button" onClick={() => setShow(!show)} aria-label={show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'} aria-pressed={show} className="absolute right-1 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-lg text-zinc-500 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-amber-500">{show ? <EyeOff size={19} /> : <Eye size={19} />}</button>
    </div>{(error || hint) && <p id={`${id}-help`} className={`mt-1.5 text-xs leading-5 ${error ? 'text-rose-700' : 'text-zinc-500'}`}>{error || hint}</p>}
  </div>;
}

export function LightAuthAlert({ children }: { children: ReactNode }) {
  return <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{children}</p>;
}

export const lightAuthButton = 'flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-amber-400 px-4 py-3 text-sm font-bold text-zinc-950 shadow-sm transition hover:bg-amber-300 active:translate-y-px focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600 disabled:cursor-not-allowed disabled:opacity-60';
