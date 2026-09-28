import { ArrowRight } from 'lucide-react';
import { WorkspaceImage } from '@/components/site/HeroPortrait';
import MagneticLink, { primaryCta } from '@/components/site/MagneticLink';
import PageHero, { SectionLabel, usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';
import useSiteData from '@/components/site/useSiteData';

const PRINCIPLES = [
  { title: 'Strategi dulu', text: 'Setiap desain dan sistem berangkat dari tujuan bisnismu, bukan sekadar tren.' },
  { title: 'Estetik & fungsional', text: 'Tampilan yang enak dilihat harus tetap gampang dipakai setiap hari.' },
  { title: 'Tumbuh bareng', text: 'Kami mendampingi sampai hasilnya jalan, lalu ikut membantu saat bisnismu berkembang.' },
];

export default function TentangPage() {
  const { content } = useSiteData();
  const about = content.about || {};
  usePageMeta(
    'Tentang Hellom',
    'Kenalan dengan Hellom: partner kreatif yang membantu bisnis dan kreator membangun brand, sistem, dan produk digital.',
    '/tentang'
  );

  const stats = [
    { value: `${about.happy_clients || 100}+`, label: 'Klien puas' },
    { value: `${about.years_experience || 5}+`, label: 'Tahun pengalaman' },
    { value: `${about.projects_completed || 100}+`, label: 'Proyek selesai' },
    { value: about.support_label || '24/7', label: 'Dukungan & pendampingan' },
  ];

  return (
    <>
      <PageHero
        eyebrow="Tentang"
        lines={['Membangun dengan strategi,', <span key="e">berkarya dengan <span className="text-[#F6B400]">estetika.</span></span>]}
        intro={about.subtitle || 'Hellom adalah ruang kreatif untuk bisnis dan kreator yang ingin tampil rapi, bekerja lebih efisien, dan tumbuh lebih cepat.'}
      />

      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto grid max-w-[1500px] gap-12 md:grid-cols-2 md:items-center">
          <Reveal>
            <SectionLabel>Cerita kami</SectionLabel>
            <p className="text-lg leading-9 text-[#D6D6D8]">
              {about.description || 'Berpengalaman lebih dari 5 tahun di dunia kreatif dan digital. Kami fokus membantu bisnis dan kreator mengubah ide menjadi sistem dan produk digital yang siap dipakai, terukur, dan berkelanjutan.'}
            </p>
          </Reveal>
          <Reveal delay={0.1}>
            <div className="aspect-[430/250] overflow-hidden rounded-2xl border border-white/[0.08] bg-[#0E0E11]">
              <WorkspaceImage className="h-full w-full object-cover" />
            </div>
          </Reveal>
        </div>
      </section>

      <section className="border-b border-white/[0.08] px-5 py-16 md:px-10 lg:px-16">
        <dl className="mx-auto grid max-w-[1500px] grid-cols-2 gap-8 md:grid-cols-4">
          {stats.map((stat, index) => (
            <Reveal key={stat.label} delay={index * 0.06}>
              <dt className="text-sm text-[#A1A1A6]">{stat.label}</dt>
              <dd className="mt-2 font-display text-5xl text-[#F6B400]">{stat.value}</dd>
            </Reveal>
          ))}
        </dl>
      </section>

      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <Reveal>
            <SectionLabel>Cara kami bekerja</SectionLabel>
          </Reveal>
          <div className="grid gap-6 md:grid-cols-3">
            {PRINCIPLES.map((item, index) => (
              <Reveal key={item.title} delay={index * 0.08} className="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-8">
                <p className="text-xs text-[#A1A1A6]">{String(index + 1).padStart(2, '0')}</p>
                <h2 className="mt-4 font-display text-2xl">{item.title}</h2>
                <p className="mt-3 text-sm leading-7 text-[#A1A1A6]">{item.text}</p>
              </Reveal>
            ))}
          </div>
          <Reveal className="mt-12">
            <MagneticLink to="/kontak" className={primaryCta}>
              Ngobrol soal proyekmu <ArrowRight className="h-4 w-4" />
            </MagneticLink>
          </Reveal>
        </div>
      </section>
    </>
  );
}
