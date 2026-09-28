import { useEffect, useState } from 'react';
import { AnimatePresence, m } from 'framer-motion';
import { ExternalLink, Play, X } from 'lucide-react';
import { getImageUrl } from '@/lib/hellomApi';
import PageHero, { usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';
import useSiteData, { type SitePortfolio } from '@/components/site/useSiteData';

// Chip label (UI) → keyword matched against the project's category text.
const FILTERS = [
  { label: 'Semua', keyword: '' },
  { label: 'Branding', keyword: 'branding' },
  { label: 'Desain Web', keyword: 'web' },
  { label: 'Produk Digital', keyword: 'digital' },
  { label: 'Sistem', keyword: 'system' },
];

function ProjectModal({ project, onClose }: { project: SitePortfolio; onClose: () => void }) {
  const ytId = project.video_url?.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/)?.[1];
  const vimeoId = project.video_url?.match(/vimeo\.com\/(\d+)/)?.[1];

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <m.div
      className="fixed inset-0 z-[90] flex items-end justify-center bg-black/80 sm:items-center"
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.2 }}
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-label={project.title}
    >
      <m.div
        data-lenis-prevent
        className="max-h-[95vh] w-full max-w-3xl overflow-y-auto rounded-t-2xl border border-white/[0.08] bg-[#0E0E11] sm:rounded-2xl"
        initial={{ y: 24 }}
        animate={{ y: 0 }}
        transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex justify-end bg-[#0E0E11]/90 p-4">
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-lg bg-white/[0.06] text-white/70 hover:text-white">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="-mt-4 px-4 sm:px-6">
          {ytId ? (
            <div className="aspect-video overflow-hidden rounded-xl bg-black">
              <iframe title={project.title} src={`https://www.youtube.com/embed/${ytId}?autoplay=1&rel=0`} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowFullScreen className="h-full w-full" />
            </div>
          ) : vimeoId ? (
            <div className="aspect-video overflow-hidden rounded-xl bg-black">
              <iframe title={project.title} src={`https://player.vimeo.com/video/${vimeoId}?autoplay=1`} allow="autoplay; fullscreen; picture-in-picture" allowFullScreen className="h-full w-full" />
            </div>
          ) : project.video_url ? (
            <div className="aspect-video overflow-hidden rounded-xl bg-black">
              <video src={project.video_url} controls autoPlay className="h-full w-full object-contain" poster={project.thumbnail_url ?? undefined} />
            </div>
          ) : project.thumbnail_url ? (
            <img src={getImageUrl(project.thumbnail_url)} alt={project.title} className="aspect-[1.65] w-full rounded-xl object-cover" />
          ) : null}
        </div>
        <div className="p-6 sm:p-8">
          {project.category ? <p className="mb-3 text-[11px] font-bold uppercase tracking-[0.3em] text-[#F6B400]">{project.category}</p> : null}
          <h2 className="font-display text-2xl sm:text-3xl">{project.title}</h2>
          {(project.client_name || project.project_year) && (
            <p className="mt-3 text-sm text-[#A1A1A6]">
              {project.client_name ? <>Klien: <span className="text-white/85">{project.client_name}</span></> : null}
              {project.client_name && project.project_year ? ' · ' : null}
              {project.project_year ? <>Tahun: <span className="text-white/85">{project.project_year}</span></> : null}
            </p>
          )}
          {project.full_description || project.description ? (
            <p className="mt-5 whitespace-pre-line text-sm leading-7 text-[#C9C9CC]">{project.full_description || project.description}</p>
          ) : null}
          {project.project_url ? (
            <a href={project.project_url} target="_blank" rel="noopener noreferrer" className="mt-8 inline-flex h-12 items-center gap-3 rounded-lg bg-[#F6B400] px-7 text-sm font-bold text-black hover:bg-[#FFCC47]">
              <ExternalLink className="h-4 w-4" /> Kunjungi website
            </a>
          ) : null}
        </div>
      </m.div>
    </m.div>
  );
}

export default function PortofolioPage() {
  const { portfolios } = useSiteData();
  const [filter, setFilter] = useState('');
  const [selected, setSelected] = useState<SitePortfolio | null>(null);
  usePageMeta('Portofolio', 'Proyek pilihan Hellom: branding, website, produk digital, dan sistem bisnis.', '/portofolio');

  const visible = filter
    ? portfolios.filter((item) => `${item.category || ''}`.toLowerCase().includes(filter))
    : portfolios;

  return (
    <>
      <PageHero
        eyebrow="Portofolio"
        lines={['Beberapa karya dan', <span key="p" className="text-[#F6B400]">proyek pilihan.</span>]}
        intro="Klik salah satu proyek untuk melihat cerita dan hasilnya."
      />

      <section className="px-5 py-16 md:px-10 lg:px-16">
        <div className="mx-auto max-w-[1500px]">
          <div role="group" aria-label="Filter portofolio" className="scrollbar-hide -mx-5 mb-10 flex gap-2 overflow-x-auto px-5">
            {FILTERS.map((item) => (
              <button
                key={item.label}
                type="button"
                aria-pressed={filter === item.keyword}
                onClick={() => setFilter(item.keyword)}
                className={`h-11 shrink-0 rounded-full px-5 text-sm font-semibold transition-colors ${filter === item.keyword ? 'bg-[#F6B400] text-black' : 'border border-white/[0.12] text-[#D6D6D8] hover:border-white/30'}`}
              >
                {item.label}
              </button>
            ))}
          </div>

          {visible.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-white/[0.12] p-10 text-center text-[#A1A1A6]">Belum ada proyek di kategori ini.</p>
          ) : (
            <ul className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
              {visible.map((item, index) => (
                <Reveal as="li" key={item.id} delay={(index % 3) * 0.06}>
                  <button
                    type="button"
                    onClick={() => setSelected(item)}
                    className="group block w-full overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.03] text-left transition-colors hover:border-[#F6B400]/50"
                  >
                    <div className="relative aspect-[1.65] overflow-hidden bg-[#0E0E11]">
                      {item.thumbnail_url ? (
                        <img src={getImageUrl(item.thumbnail_url)} alt={item.title} width={660} height={400} loading="lazy" decoding="async" className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" />
                      ) : (
                        <div className="h-full w-full bg-[radial-gradient(circle_at_35%_30%,rgba(246,180,0,.22),transparent_33%),linear-gradient(135deg,#131313,#050505)]" />
                      )}
                      {item.video_url ? (
                        <span className="absolute inset-0 flex items-center justify-center">
                          <span className="flex h-14 w-14 items-center justify-center rounded-full border border-white/30 bg-black/40">
                            <Play aria-hidden className="ml-1 h-6 w-6 fill-white text-white" />
                          </span>
                        </span>
                      ) : null}
                    </div>
                    <div className="p-5">
                      <h2 className="font-display text-lg">{item.title}</h2>
                      <p className="mt-1 text-sm text-[#A1A1A6]">{item.category || item.description}</p>
                      {item.client_name || item.project_year ? (
                        <p className="mt-2 text-xs text-[#F6B400]">{[item.client_name, item.project_year].filter(Boolean).join(' · ')}</p>
                      ) : null}
                    </div>
                  </button>
                </Reveal>
              ))}
            </ul>
          )}
        </div>
      </section>

      <AnimatePresence>
        {selected ? <ProjectModal key={selected.id} project={selected} onClose={() => setSelected(null)} /> : null}
      </AnimatePresence>
    </>
  );
}
