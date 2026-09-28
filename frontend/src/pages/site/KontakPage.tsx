import { ArrowRight, Clock, Mail, MapPin, MessageCircle, Phone } from 'lucide-react';
import useBrand from '@/hooks/useBrand';
import { COMPANY_INFO, formatCompanyAddress } from '@/lib/companyInfo';
import MagneticLink, { primaryCta, secondaryCta } from '@/components/site/MagneticLink';
import PageHero, { SectionLabel, usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';

const toWhatsappLink = (value: string) => {
  const digits = value.replace(/\D/g, '');
  if (!digits) return '';
  return `https://wa.me/${digits.startsWith('0') ? `62${digits.slice(1)}` : digits}`;
};

// Business details below are also what payment gateways check during merchant
// verification (formerly /contact) — keep them complete and accurate.
export default function KontakPage() {
  const { brand } = useBrand();
  const brandName = brand.business_name || brand.app_name || COMPANY_INFO.legalName;
  const email = brand.support_email || COMPANY_INFO.fallbackEmail;
  const phone = brand.support_phone || COMPANY_INFO.fallbackPhone;
  const whatsapp = COMPANY_INFO.whatsapp || phone;
  const whatsappLink = whatsapp ? toWhatsappLink(whatsapp) : '';
  const address = formatCompanyAddress();
  usePageMeta('Kontak', `Hubungi ${brandName}: WhatsApp, email, alamat, dan jam operasional.`, '/kontak');

  const socials = [
    brand.social_instagram ? { label: 'Instagram', href: brand.social_instagram } : null,
    brand.social_facebook ? { label: 'Facebook', href: brand.social_facebook } : null,
    brand.social_tiktok ? { label: 'TikTok', href: brand.social_tiktok } : null,
  ].filter((item): item is { label: string; href: string } => Boolean(item));

  const cards = [
    { icon: MapPin, title: 'Alamat', body: <><p>{brandName}</p><p className="mt-1 text-[#A1A1A6]">{address || 'Alamat akan diperbarui.'}</p></> },
    { icon: Clock, title: 'Jam operasional', body: <p>{COMPANY_INFO.operatingHours}</p> },
    { icon: Mail, title: 'Email', body: <a href={`mailto:${email}`} className="link-draw">{email}</a> },
    { icon: Phone, title: 'Telepon / WhatsApp', body: <p>{whatsapp || 'Nomor akan diperbarui.'}</p> },
  ];

  return (
    <>
      <PageHero
        eyebrow="Kontak"
        lines={['Yuk, ngobrol soal', <span key="k" className="text-[#F6B400]">rencanamu.</span>]}
        intro={`Punya pertanyaan, mau mulai proyek, atau butuh bantuan soal transaksi? Tim ${brandName} siap bantu.`}
      >
        <div className="flex flex-col gap-4 sm:flex-row">
          {whatsappLink ? (
            <MagneticLink href={whatsappLink} external className={primaryCta}>
              <MessageCircle className="h-4 w-4" /> Chat via WhatsApp
            </MagneticLink>
          ) : null}
          <a href={`mailto:${email}`} className={secondaryCta}>
            Kirim email <ArrowRight className="h-4 w-4" />
          </a>
        </div>
      </PageHero>

      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto grid max-w-[1500px] gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {cards.map((card, index) => {
            const Icon = card.icon;
            return (
              <Reveal key={card.title} delay={index * 0.06} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-6">
                <Icon aria-hidden className="h-6 w-6 text-[#F6B400]" />
                <h2 className="mt-5 text-sm font-bold uppercase tracking-[0.2em] text-[#A1A1A6]">{card.title}</h2>
                <div className="mt-3 text-base leading-7">{card.body}</div>
              </Reveal>
            );
          })}
        </div>
      </section>

      <section className="px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto grid max-w-[1500px] gap-12 md:grid-cols-2">
          <Reveal>
            <SectionLabel>Informasi usaha</SectionLabel>
            <p className="max-w-xl text-base leading-8 text-[#C9C9CC]">
              {brandName} adalah penyedia produk digital dan aplikasi bisnis yang beroperasi melalui {COMPANY_INFO.domain}. Untuk kerja sama,
              dukungan teknis, atau pertanyaan seputar transaksi, hubungi kami lewat kanal di atas pada jam operasional.
            </p>
          </Reveal>
          {socials.length > 0 ? (
            <Reveal delay={0.1}>
              <SectionLabel>Media sosial</SectionLabel>
              <div className="flex flex-wrap gap-3">
                {socials.map((social) => (
                  <a key={social.label} href={social.href} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center rounded-full border border-white/[0.12] px-5 text-sm font-semibold hover:border-[#F6B400]">
                    {social.label}
                  </a>
                ))}
              </div>
            </Reveal>
          ) : null}
        </div>
      </section>
    </>
  );
}
