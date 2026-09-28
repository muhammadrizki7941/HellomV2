import { Link } from 'react-router-dom';
import useBrand from '@/hooks/useBrand';
import { COMPANY_INFO, formatCompanyAddress } from '@/lib/companyInfo';
import { SITE_PAGES } from '@/data/siteNav';
import { BrandMark } from './SiteNavbar';

export default function SiteFooter() {
  const { brand } = useBrand();
  const name = brand.app_name || brand.business_name || 'Hellom';
  const email = brand.support_email || COMPANY_INFO.fallbackEmail;
  const phone = brand.support_phone || COMPANY_INFO.fallbackPhone;
  const address = formatCompanyAddress();

  return (
    <footer className="relative z-10 border-t border-white/[0.08] px-5 py-12 md:px-10 lg:px-16">
      <div className="mx-auto max-w-[1500px] space-y-10">
        <div className="grid gap-10 md:grid-cols-[1.2fr_1fr_1fr]">
          <div>
            <BrandMark />
            <p className="mt-4 text-sm text-[#A1A1A6]">Partner kreatif untuk bisnismu.</p>
            {address ? <p className="mt-2 max-w-xs text-xs leading-relaxed text-[#A1A1A6]">{address}</p> : null}
          </div>
          <nav aria-label="Navigasi footer">
            <p className="mb-4 text-[11px] font-bold uppercase tracking-[0.3em] text-[#F6B400]">Jelajahi</p>
            <ul className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm text-[#A1A1A6]">
              {SITE_PAGES.map((page) => (
                <li key={page.path}>
                  <Link to={page.path} className="link-draw inline-flex min-h-8 items-center hover:text-[#F5F5F2]">{page.label}</Link>
                </li>
              ))}
            </ul>
          </nav>
          <div>
            <p className="mb-4 text-[11px] font-bold uppercase tracking-[0.3em] text-[#F6B400]">Kontak</p>
            <ul className="space-y-2 text-sm text-[#A1A1A6]">
              <li><a href={`mailto:${email}`} className="link-draw hover:text-[#F5F5F2]">{email}</a></li>
              {phone ? <li>{phone}</li> : null}
              <li>{COMPANY_INFO.operatingHours}</li>
            </ul>
          </div>
        </div>
        <div className="flex flex-col items-center justify-between gap-4 border-t border-white/[0.08] pt-6 md:flex-row">
          <div className="flex flex-wrap justify-center gap-5 text-xs text-[#A1A1A6]">
            <Link to="/faq" className="link-draw hover:text-[#F5F5F2]">FAQ</Link>
            <Link to="/refund-policy" className="link-draw hover:text-[#F5F5F2]">Kebijakan Refund</Link>
            <Link to="/terms" className="link-draw hover:text-[#F5F5F2]">Syarat &amp; Ketentuan</Link>
          </div>
          <p className="text-xs text-[#A1A1A6]">© {new Date().getFullYear()} {name}. Semua hak dilindungi.</p>
        </div>
      </div>
    </footer>
  );
}
