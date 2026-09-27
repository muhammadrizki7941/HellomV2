import type { BrandSettings } from '@/hooks/useBrand';
import { Link } from 'react-router-dom';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { formatCompanyAddress } from '@/lib/companyInfo';

interface FooterProps {
  brand: BrandSettings;
  logoSrc: string | null;
}

export const Footer = ({ brand, logoSrc }: FooterProps) => {
  const brandName = brand.app_name || brand.business_name || 'Hellom';
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  const companyAddress = formatCompanyAddress();

  return (
    <footer className="border-t border-white/5 bg-zinc-950 px-6 py-14">
      <div className="mx-auto grid max-w-7xl gap-10 md:grid-cols-[1.4fr_1fr_1fr_1fr_1.2fr]">
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
          <h4 className="text-sm font-bold uppercase tracking-wider text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Bantuan & Legal</h4>
          <div className="mt-4 space-y-3 text-sm text-zinc-400">
            <Link to="/faq" className="block hover:text-white">FAQ</Link>
            <Link to="/refund-policy" className="block hover:text-white">Kebijakan Refund</Link>
            <Link to="/terms" className="block hover:text-white">Syarat & Ketentuan</Link>
            <Link to="/contact" className="block hover:text-white">Kontak</Link>
          </div>
        </div>

        <div>
          <h4 className="text-sm font-bold uppercase tracking-wider text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Kontak</h4>
          <div className="mt-4 space-y-3 text-sm text-zinc-400">
            {brand.support_email ? (
              <a href={`mailto:${brand.support_email}`} className="block hover:text-white">{brand.support_email}</a>
            ) : <div>support@hellomspace.com</div>}
            {brand.support_phone ? <div>{brand.support_phone}</div> : <div>WhatsApp support aktif</div>}
            {companyAddress ? <div className="leading-relaxed">{companyAddress}</div> : null}
            <Link to="/contact" className="block hover:text-white">Mulai konsultasi</Link>
          </div>
        </div>
      </div>

      <div className="mx-auto mt-10 flex max-w-7xl flex-col gap-3 border-t border-white/5 pt-6 text-xs text-zinc-500 md:flex-row md:items-center md:justify-between">
        <p>{brand.footer_text || '© 2026 Hellom. Digital products, SaaS, dan layanan untuk bisnis modern.'}</p>
        <div className="flex flex-wrap gap-4">
          <Link to="/faq" className="hover:text-white">FAQ</Link>
          <Link to="/refund-policy" className="hover:text-white">Refund</Link>
          <Link to="/terms" className="hover:text-white">Syarat & Ketentuan</Link>
          <Link to="/contact" className="hover:text-white">Kontak</Link>
        </div>
      </div>
    </footer>
  );
};
