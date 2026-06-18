import { Clock, Mail, MapPin, MessageCircle, Phone } from 'lucide-react';
import LegalPageLayout, { LegalSection } from '@/components/legal/LegalPageLayout';
import useBrand from '@/hooks/useBrand';
import { COMPANY_INFO, formatCompanyAddress } from '@/lib/companyInfo';

const toWhatsappLink = (value: string) => {
  const digits = value.replace(/\D/g, '');
  if (!digits) return '';
  const normalized = digits.startsWith('0') ? `62${digits.slice(1)}` : digits;
  return `https://wa.me/${normalized}`;
};

export default function ContactPage() {
  const { brand } = useBrand();
  const brandName = brand.business_name || brand.app_name || COMPANY_INFO.legalName;
  const email = brand.support_email || COMPANY_INFO.fallbackEmail;
  const phone = brand.support_phone || COMPANY_INFO.fallbackPhone;
  const whatsapp = COMPANY_INFO.whatsapp || phone;
  const whatsappLink = whatsapp ? toWhatsappLink(whatsapp) : '';
  const address = formatCompanyAddress();

  const socials = [
    brand.social_instagram ? { label: 'Instagram', href: brand.social_instagram } : null,
    brand.social_facebook ? { label: 'Facebook', href: brand.social_facebook } : null,
    brand.social_tiktok ? { label: 'TikTok', href: brand.social_tiktok } : null,
  ].filter((item): item is { label: string; href: string } => Boolean(item));

  return (
    <LegalPageLayout
      title="Hubungi Kami"
      description={`Punya pertanyaan atau butuh bantuan? Tim ${brandName} siap membantu Anda.`}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="rounded-2xl border border-white/10 bg-white/5 p-5">
          <div className="flex items-center gap-2 text-yellow-400">
            <MapPin className="h-5 w-5" />
            <span className="text-sm font-bold uppercase tracking-wide">Alamat</span>
          </div>
          <p className="mt-3 text-zinc-200">{brandName}</p>
          <p className="mt-1 text-zinc-400">{address || 'Alamat akan diperbarui.'}</p>
        </div>

        <div className="rounded-2xl border border-white/10 bg-white/5 p-5">
          <div className="flex items-center gap-2 text-yellow-400">
            <Clock className="h-5 w-5" />
            <span className="text-sm font-bold uppercase tracking-wide">Jam Operasional</span>
          </div>
          <p className="mt-3 text-zinc-200">{COMPANY_INFO.operatingHours}</p>
        </div>

        <div className="rounded-2xl border border-white/10 bg-white/5 p-5">
          <div className="flex items-center gap-2 text-yellow-400">
            <Mail className="h-5 w-5" />
            <span className="text-sm font-bold uppercase tracking-wide">Email</span>
          </div>
          <a href={`mailto:${email}`} className="mt-3 block text-zinc-200 hover:text-yellow-400">{email}</a>
        </div>

        <div className="rounded-2xl border border-white/10 bg-white/5 p-5">
          <div className="flex items-center gap-2 text-yellow-400">
            <Phone className="h-5 w-5" />
            <span className="text-sm font-bold uppercase tracking-wide">Telepon / WhatsApp</span>
          </div>
          {whatsapp ? (
            <div className="mt-3 space-y-2">
              <p className="text-zinc-200">{whatsapp}</p>
              {whatsappLink ? (
                <a
                  href={whatsappLink}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-2 rounded-lg bg-yellow-400 px-3 py-2 text-sm font-semibold text-zinc-900 hover:bg-yellow-300"
                >
                  <MessageCircle className="h-4 w-4" />
                  Chat via WhatsApp
                </a>
              ) : null}
            </div>
          ) : (
            <p className="mt-3 text-zinc-400">Nomor akan diperbarui.</p>
          )}
        </div>
      </div>

      {socials.length > 0 ? (
        <LegalSection heading="Media Sosial">
          <div className="flex flex-wrap gap-3">
            {socials.map((social) => (
              <a
                key={social.label}
                href={social.href}
                target="_blank"
                rel="noreferrer"
                className="rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-zinc-200 hover:bg-white/5"
              >
                {social.label}
              </a>
            ))}
          </div>
        </LegalSection>
      ) : null}

      <LegalSection heading="Informasi Usaha">
        <p>
          {brandName} merupakan penyedia produk digital dan aplikasi bisnis yang beroperasi melalui{' '}
          {COMPANY_INFO.domain}. Untuk kebutuhan kerja sama, dukungan teknis, atau pertanyaan seputar transaksi,
          silakan hubungi kami melalui kanal di atas pada jam operasional yang tertera.
        </p>
      </LegalSection>
    </LegalPageLayout>
  );
}
