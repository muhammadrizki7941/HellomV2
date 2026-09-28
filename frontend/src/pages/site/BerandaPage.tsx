import { Link } from 'react-router-dom';
import { ArrowRight, ArrowUpRight } from 'lucide-react';
import { getImageUrl } from '@/lib/hellomApi';
import { HELLOM_APPS } from '@/data/apps';
import HeroPortrait from '@/components/site/HeroPortrait';
import IntroVideo from '@/components/site/IntroVideo';
import MagneticLink, { primaryCta, secondaryCta } from '@/components/site/MagneticLink';
import { SectionLabel, usePageMeta } from '@/components/site/PageHero';
import { Reveal, RevealText } from '@/components/site/Reveal';
import useSiteData from '@/components/site/useSiteData';

// Short teaser for every inner page; each row links to the full page.
const INDEX_ROWS = [
  { path: '/tentang', title: 'Tentang', line: 'Siapa di balik Hellom dan cara kami bekerja.' },
  { path: '/layanan', title: 'Layanan', line: 'Branding, website, produk digital, dan sistem bisnis.' },
  { path: '/aplikasi', title: 'Aplikasi', line: 'Kasir POS dan pembuat halaman jualan untuk UMKM.' },
  { path: '/produk', title: 'Produk', line: 'Template dan produk digital siap pakai, sekali beli.' },
  { path: '/portofolio', title: 'Portofolio', line: 'Beberapa proyek pilihan yang pernah kami kerjakan.' },
  { path: '/wawasan', title: 'Wawasan', line: 'Artikel seputar digital, branding, dan produktivitas.' },
];

export default function BerandaPage() {
  const { clients, content } = useSiteData();
  const about = content.about || {};
  usePageMeta(
    'Partner Kreatif untuk Bisnismu',
    'Hellom membantu bisnis dan kreator membangun brand, sistem, dan produk digital — plus aplikasi kasir dan pembuat halaman jualan untuk UMKM.',
    '/'
  );

  const stats = [
    { value: `${about.happy_clients || 100}+`, label: 'Klien puas' },
    { value: `${about.years_experience || 5}+`, label: 'Tahun pengalaman' },
    { value: `${about.projects_completed || 100}+`, label: 'Proyek selesai' },
  ];

  return (
    <>
      {/* Intro: full-screen background video with clickable content on top */}
      <IntroVideo nextSectionId="sorotan" />

      {/* Hero (now the section after the intro, so it reveals on scroll) */}
      <section id="sorotan" className="relative scroll-mt-20 border-b border-white/[0.08] px-5 py-20 md:px-10 md:py-28 lg:px-16">
        <div className="mx-auto grid max-w-[1500px] items-center gap-12 md:grid-cols-[0.95fr_1.05fr]">
          <div className="relative z-10">
            <Reveal className="mb-8 flex items-center gap-3">
              <span className="h-px w-12 bg-white/[0.15]" />
              <span className="text-[11px] font-bold uppercase tracking-[0.4em] text-[#F6B400]">Kreator · Desainer · Builder</span>
            </Reveal>
            <RevealText
              as="h2"
              inView
              lines={['Hellom', <span key="space" className="text-[#F6B400]">Space.</span>]}
              className="font-display text-[3.6rem] font-semibold leading-[0.92] sm:text-8xl lg:text-[8.2rem]"
            />
            <Reveal delay={0.2}>
              <p className="mt-6 font-serif text-2xl italic md:text-3xl">Partner kreatif untuk bisnismu</p>
              <p className="mt-6 max-w-xl text-base leading-8 text-[#A1A1A6]">
                Kami membantu bisnis dan kreator membangun brand, sistem, dan produk digital yang estetik, fungsional, dan berdampak.
              </p>
            </Reveal>
            <Reveal delay={0.3} className="mt-9 flex flex-col gap-4 sm:flex-row">
              <MagneticLink to="/aplikasi" className={primaryCta}>
                Lihat Aplikasi <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
              </MagneticLink>
              <Link to="/portofolio" className={secondaryCta}>
                Lihat Portofolio <ArrowRight className="h-4 w-4" />
              </Link>
            </Reveal>
            <Reveal delay={0.4} className="mt-10 grid max-w-xl grid-cols-3 gap-5">
              {stats.map((stat) => (
                <div key={stat.label} className="border-r border-white/[0.08] last:border-r-0">
                  <p className="font-display text-2xl text-[#F6B400]">{stat.value}</p>
                  <p className="mt-1 text-xs text-[#A1A1A6]">{stat.label}</p>
                </div>
              ))}
            </Reveal>
          </div>

          <Reveal delay={0.15} className="relative">
            <div className="relative aspect-[592/478] overflow-hidden rounded-[28px] border border-[#F6B400]/15 bg-[#0E0E11]">
              <HeroPortrait className="h-full w-full object-cover" />
              <div className="absolute inset-0 bg-[radial-gradient(circle_at_55%_18%,rgba(246,180,0,.20),transparent_40%),linear-gradient(to_top,#050505,transparent_45%)]" />
              <div className="absolute bottom-6 right-6 text-right">
                <p className="font-signature text-4xl text-[#F6B400] md:text-5xl">Muhammad Rizki</p>
                <p className="mt-2 text-[10px] font-bold uppercase tracking-[0.45em] text-[#F5F5F2]">Pendiri Hellom</p>
              </div>
            </div>
          </Reveal>
        </div>
      </section>

      {/* Trusted by */}
      <section aria-label="Dipercaya oleh" className="border-b border-white/[0.08] px-5 py-6 md:px-10 lg:px-16">
        <div className="mx-auto flex max-w-[1500px] items-center gap-10 overflow-hidden">
          <p className="shrink-0 text-[11px] font-bold uppercase tracking-[0.35em] text-[#F6B400]">Dipercaya oleh</p>
          <div className="scrollbar-hide flex min-w-0 flex-1 items-center justify-between gap-10 overflow-x-auto">
            {clients.map((client) => (
              <span key={client.id} className="shrink-0">
                {client.logo_url ? (
                  <img src={getImageUrl(client.logo_url)} alt={client.name} height={32} loading="lazy" className="h-8 w-auto max-w-[150px] object-contain opacity-70 grayscale" />
                ) : (
                  <span className="font-display text-xl text-[#A1A1A6]">{client.name}</span>
                )}
              </span>
            ))}
          </div>
        </div>
      </section>

      {/* Apps teaser */}
      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <Reveal>
            <SectionLabel>Aplikasi Unggulan</SectionLabel>
            <h2 className="max-w-3xl font-display text-4xl font-medium leading-tight md:text-5xl">
              Siapkan aplikasi untuk bantu bisnismu <span className="text-[#F6B400]">naik kelas.</span>
            </h2>
          </Reveal>
          <div className="mt-10 grid gap-5 md:grid-cols-2">
            {HELLOM_APPS.map((app, index) => {
              const Icon = app.icon;
              return (
                <Reveal key={app.slug} delay={index * 0.08}>
                  <Link
                    to={`/aplikasi/${app.slug}`}
                    className="group flex h-full flex-col rounded-2xl border border-white/[0.08] bg-white/[0.03] p-8 transition-colors hover:border-[#F6B400]/50"
                  >
                    <span className="mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#F6B400]/12 text-[#F6B400]">
                      <Icon className="h-6 w-6" />
                    </span>
                    <h3 className="font-display text-2xl">{app.name}</h3>
                    <p className="mt-3 text-sm leading-7 text-[#A1A1A6]">{app.summary}</p>
                    <span className="mt-6 inline-flex items-center gap-2 text-sm font-bold text-[#F6B400]">
                      Lihat detail <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                    </span>
                  </Link>
                </Reveal>
              );
            })}
          </div>
        </div>
      </section>

      {/* Index of pages */}
      <section className="border-b border-white/[0.08] px-5 py-20 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <Reveal>
            <SectionLabel>Jelajahi Hellom</SectionLabel>
          </Reveal>
          <ul className="border-t border-white/[0.08]">
            {INDEX_ROWS.map((row, index) => (
              <Reveal as="li" key={row.path} delay={index * 0.04} className="border-b border-white/[0.08]">
                <Link to={row.path} className="group grid grid-cols-[3rem_1fr_auto] items-center gap-4 py-7 md:grid-cols-[4rem_1fr_1.2fr_auto] md:py-9">
                  <span className="text-xs text-[#A1A1A6]">{String(index + 1).padStart(2, '0')}</span>
                  <span className="font-display text-3xl transition-colors group-hover:text-[#F6B400] md:text-5xl">{row.title}</span>
                  <span className="hidden text-sm text-[#A1A1A6] md:block">{row.line}</span>
                  <ArrowUpRight className="h-6 w-6 text-[#F6B400] transition-transform duration-500 group-hover:-translate-y-1 group-hover:translate-x-1" />
                </Link>
              </Reveal>
            ))}
          </ul>
        </div>
      </section>

      {/* Contact CTA */}
      <section className="relative overflow-hidden px-5 py-24 text-center md:px-10 lg:px-16">
        <div aria-hidden className="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(246,180,0,.14),transparent_35%)]" />
        <Reveal className="relative mx-auto max-w-3xl">
          <h2 className="font-display text-5xl font-medium leading-tight md:text-7xl">Yuk, bangun sesuatu yang berarti.</h2>
          <div className="mt-9 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <MagneticLink to="/kontak" className={primaryCta}>
              Mulai Proyek <ArrowRight className="h-4 w-4" />
            </MagneticLink>
            <Link to="/layanan" className={secondaryCta}>Lihat Layanan</Link>
          </div>
        </Reveal>
      </section>
    </>
  );
}
