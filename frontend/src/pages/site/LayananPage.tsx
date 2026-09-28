import { ArrowRight, BriefcaseBusiness, Cpu, Layers3, PenTool, Sparkles, Zap } from 'lucide-react';
import MagneticLink, { primaryCta } from '@/components/site/MagneticLink';
import PageHero, { SectionLabel, usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';
import useSiteData, { FALLBACK_SERVICES } from '@/components/site/useSiteData';

const ICONS = { PenTool, Layers3, Cpu, Zap, BriefcaseBusiness, Sparkles };

const STEPS = [
  { title: 'Ngobrol', text: 'Kita bahas tujuan, kebutuhan, dan anggaranmu.' },
  { title: 'Rancang', text: 'Konsep, alur, dan tampilan disusun lalu kamu review.' },
  { title: 'Bangun', text: 'Dikerjakan bertahap dengan update rutin.' },
  { title: 'Dampingi', text: 'Serah terima, pelatihan singkat, dan dukungan setelah rilis.' },
];

export default function LayananPage() {
  const { content } = useSiteData();
  const services = content.services?.length ? content.services : FALLBACK_SERVICES;
  usePageMeta(
    'Layanan',
    'Layanan Hellom: branding & identitas, desain web & UI, produk digital, otomasi sistem, serta konsultasi strategi.',
    '/layanan'
  );

  return (
    <>
      <PageHero
        eyebrow="Layanan"
        lines={['Solusi kreatif untuk', <span key="d">kebutuhan <span className="text-[#F6B400]">digitalmu.</span></span>]}
        intro="Dari identitas brand sampai sistem yang bikin operasional lebih ringan — pilih yang kamu butuhkan, kami kerjakan dengan rapi."
      />

      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto grid max-w-[1500px] gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {services.map((service, index) => {
            const Icon = ICONS[(service.icon || 'Sparkles') as keyof typeof ICONS] || Sparkles;
            return (
              <Reveal as="article" key={service.id} delay={(index % 3) * 0.08} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-8 transition-colors hover:border-[#F6B400]/45">
                <Icon className="mb-8 h-7 w-7 text-[#F6B400]" />
                <h2 className="font-display text-2xl">{service.title}</h2>
                <p className="mt-4 text-sm leading-7 text-[#A1A1A6]">{service.short_description}</p>
              </Reveal>
            );
          })}
        </div>
      </section>

      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <Reveal>
            <SectionLabel>Alur kerja</SectionLabel>
            <h2 className="max-w-2xl font-display text-4xl font-medium leading-tight md:text-5xl">Empat langkah, tanpa drama.</h2>
          </Reveal>
          <ol className="mt-12 grid gap-8 md:grid-cols-4">
            {STEPS.map((step, index) => (
              <Reveal as="li" key={step.title} delay={index * 0.08} className="border-t border-[#F6B400]/40 pt-6">
                <p className="text-xs text-[#A1A1A6]">Langkah {index + 1}</p>
                <h3 className="mt-3 font-display text-2xl">{step.title}</h3>
                <p className="mt-3 text-sm leading-7 text-[#A1A1A6]">{step.text}</p>
              </Reveal>
            ))}
          </ol>
          <Reveal className="mt-14">
            <MagneticLink to="/kontak" className={primaryCta}>
              Konsultasikan kebutuhanmu <ArrowRight className="h-4 w-4" />
            </MagneticLink>
          </Reveal>
        </div>
      </section>
    </>
  );
}
