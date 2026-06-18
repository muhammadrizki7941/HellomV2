import { type ReactNode, useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import { Footer } from '@/components/landing/Footer';

const LEGAL_NAV = [
  { to: '/faq', label: 'FAQ' },
  { to: '/refund-policy', label: 'Kebijakan Refund' },
  { to: '/terms', label: 'Syarat & Ketentuan' },
  { to: '/contact', label: 'Kontak' },
];

interface LegalPageLayoutProps {
  title: string;
  description?: string;
  lastUpdated?: string;
  children: ReactNode;
}

export default function LegalPageLayout({ title, description, lastUpdated, children }: LegalPageLayoutProps) {
  const { brand, logoSrc } = useBrand();
  const location = useLocation();
  const brandName = brand.app_name || brand.business_name || 'Hellom';

  useEffect(() => {
    window.scrollTo({ top: 0 });
  }, [location.pathname]);

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100">
      <header className="border-b border-white/5 bg-zinc-950/80 px-6 py-4 backdrop-blur">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4">
          <Link to="/" className="flex items-center gap-3">
            {logoSrc ? (
              <img src={logoSrc} alt={brandName} className="h-8 w-auto object-contain" />
            ) : (
              <span className="text-lg font-bold tracking-tight text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Hell<span className="text-yellow-400">OM</span>
              </span>
            )}
          </Link>
          <Link
            to="/"
            className="inline-flex items-center gap-2 rounded-lg border border-white/10 px-3 py-2 text-sm font-semibold text-zinc-300 hover:bg-white/5"
          >
            <ArrowLeft className="h-4 w-4" />
            Kembali ke Beranda
          </Link>
        </div>
      </header>

      <main className="px-6 py-12">
        <div className="mx-auto max-w-4xl">
          <nav className="mb-8 flex flex-wrap gap-2">
            {LEGAL_NAV.map((item) => {
              const active = location.pathname === item.to;
              return (
                <Link
                  key={item.to}
                  to={item.to}
                  className={`rounded-full border px-4 py-1.5 text-sm font-semibold transition ${
                    active
                      ? 'border-yellow-400 bg-yellow-400 text-zinc-900'
                      : 'border-white/10 text-zinc-300 hover:bg-white/5'
                  }`}
                >
                  {item.label}
                </Link>
              );
            })}
          </nav>

          <h1 className="text-3xl font-bold tracking-tight text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            {title}
          </h1>
          {description ? <p className="mt-3 text-base leading-relaxed text-zinc-400">{description}</p> : null}
          {lastUpdated ? <p className="mt-2 text-xs uppercase tracking-wide text-zinc-500">Terakhir diperbarui: {lastUpdated}</p> : null}

          <div className="legal-content mt-8 space-y-8 text-sm leading-relaxed text-zinc-300">
            {children}
          </div>
        </div>
      </main>

      <Footer brand={brand} logoSrc={logoSrc} />
    </div>
  );
}

interface LegalSectionProps {
  heading: string;
  children: ReactNode;
}

export function LegalSection({ heading, children }: LegalSectionProps) {
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-bold text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
        {heading}
      </h2>
      <div className="space-y-3 text-zinc-300">{children}</div>
    </section>
  );
}
