import type { BrandSettings } from '@/hooks/useBrand';
import { Link } from 'react-router-dom';
import { getSessionUser, getToken } from '@/lib/hellomApi';

interface FooterProps {
  brand: BrandSettings;
  logoSrc: string | null;
}

export const Footer = ({ brand, logoSrc }: FooterProps) => {
  const brandName = brand.app_name || brand.business_name || 'Hellom';
  const isAuthenticated = Boolean(getToken() && getSessionUser());

  return (
    <footer className="border-t border-white/5 bg-zinc-950 px-6 py-14">
      <div className="mx-auto grid max-w-7xl gap-10 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div>
          <Link to="/" className="flex items-center gap-3">
            {logoSrc ? (
              <img src={logoSrc} alt={brandName} className="h-9 w-auto object-contain" />
            ) : (
              <span className="text-xl font-bold tracking-tight text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Hell<span className="text-yellow-400">OM</span>
              </span>
            )}
          </Link>
          <p className="mt-4 max-w-sm text-sm leading-relaxed text-zinc-400">
            Platform digital untuk menyediakan aplikasi, produk digital, dan layanan custom dengan presentasi yang jelas dan siap digunakan.
          </p>
          <div className="mt-5 flex gap-2 text-xs text-zinc-500">
            <span className="rounded border border-white/10 px-3 py-1">Bayar via QRIS</span>
            <span className="rounded border border-white/10 px-3 py-1">Produk digital</span>
            <span className="rounded border border-white/10 px-3 py-1">SaaS</span>
          </div>
        </div>

        <div>
          <h4 className="text-sm font-bold uppercase tracking-wider text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Produk</h4>
          <div className="mt-4 space-y-3 text-sm text-zinc-400">
            <a href="#produk" className="block hover:text-white">POS / Kasir Digital</a>
            <a href="#produk" className="block hover:text-white">Landing Page Builder</a>
            <a href="#produk" className="block hover:text-white">Template & Source Code</a>
            <a href="#produk" className="block hover:text-white">Kursus & aset download</a>
          </div>
        </div>

        <div>
          <h4 className="text-sm font-bold uppercase tracking-wider text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Akun</h4>
          <div className="mt-4 space-y-3 text-sm text-zinc-400">
            <Link to={isAuthenticated ? '/dashboard' : '/login'} className="block hover:text-white">Masuk</Link>
            <Link to={isAuthenticated ? '/dashboard' : '/register'} className="block hover:text-white">Mulai Sekarang</Link>
            <Link to="/dashboard" className="block hover:text-white">Dashboard</Link>
            <a href="#harga" className="block hover:text-white">Harga</a>
          </div>
        </div>

        <div>
          <h4 className="text-sm font-bold uppercase tracking-wider text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Kontak</h4>
          <div className="mt-4 space-y-3 text-sm text-zinc-400">
            {brand.support_email ? <div>{brand.support_email}</div> : <div>support@hellom.app</div>}
            {brand.support_phone ? <div>{brand.support_phone}</div> : <div>WhatsApp support aktif</div>}
            <a href="#cta" className="block hover:text-white">Mulai konsultasi</a>
          </div>
        </div>
      </div>

      <div className="mx-auto mt-10 flex max-w-7xl flex-col gap-3 border-t border-white/5 pt-6 text-xs text-zinc-500 md:flex-row md:items-center md:justify-between">
        <p>{brand.footer_text || '© 2026 Hellom. Digital products, SaaS, dan layanan untuk bisnis modern.'}</p>
        <div className="flex gap-2">
          <span className="rounded border border-white/10 px-3 py-1">Subscription</span>
          <span className="rounded border border-white/10 px-3 py-1">One-time buy</span>
          <span className="rounded border border-white/10 px-3 py-1">Lisensi fleksibel</span>
        </div>
      </div>
    </footer>
  );
};
