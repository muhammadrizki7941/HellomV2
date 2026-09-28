import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Check, Crown } from 'lucide-react';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { APP_STATUS_LABEL } from '@/data/apps';
import MagneticLink, { primaryCta } from '@/components/site/MagneticLink';
import { SectionLabel, usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';
import AppPreview from '@/components/site/AppPreview';
import ShareButtons from '@/components/site/ShareButtons';
import useFlagshipApps from '@/components/site/useFlagshipApps';

export default function AplikasiDetailPage() {
  const { slug = '' } = useParams();
  const { apps, loaded } = useFlagshipApps();
  const app = apps.find((item) => item.key === slug);
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  usePageMeta(app ? app.name : 'Aplikasi', app?.summary || 'Aplikasi unggulan Hellom untuk UMKM.', `/aplikasi/${slug}`);

  if (!app) {
    // Product-only flagship apps arrive with the API; wait before saying "not found".
    if (!loaded) {
      return <section className="min-h-[60vh] px-5 pt-40" aria-busy="true" />;
    }
    return (
      <section className="px-5 pb-24 pt-40 text-center md:px-10">
        <h1 className="font-display text-4xl">Aplikasi tidak ditemukan</h1>
        <Link to="/aplikasi" className="mt-8 inline-flex min-h-11 items-center gap-2 text-[#F6B400]">
          <ArrowLeft className="h-4 w-4" /> Kembali ke aplikasi unggulan
        </Link>
      </section>
    );
  }

  const Icon = app.icon;
  const available = app.status === 'available';
  const tryHref = isAuthenticated ? app.tryHref.member : app.tryHref.guest;
  const hasBanner = Boolean(app.banner || app.bannerMobile);

  return (
    <section className="px-5 pb-24 pt-32 md:px-10 md:pt-40 lg:px-16">
      <div className="mx-auto max-w-6xl">
        <Link to="/aplikasi" className="inline-flex min-h-11 items-center gap-2 text-sm text-[#A1A1A6] hover:text-[#F5F5F2]">
          <ArrowLeft className="h-4 w-4" /> Aplikasi unggulan
        </Link>

        {hasBanner && (
          <Reveal onMount className="mt-6 overflow-hidden rounded-[28px] border border-[#F6B400]/25">
            <picture>
              {app.bannerMobile ? <source media="(max-width: 767px)" srcSet={app.bannerMobile} /> : null}
              <img
                src={app.banner ?? app.bannerMobile ?? ''}
                alt={`Banner ${app.name}`}
                width={1920}
                height={800}
                className={`w-full object-cover ${app.bannerMobile ? 'aspect-[4/5] md:aspect-[12/5]' : 'aspect-[16/10] md:aspect-[12/5]'}`}
              />
            </picture>
          </Reveal>
        )}

        <div className="mt-8 grid gap-12 lg:grid-cols-[1fr_1fr] lg:items-start">
          <Reveal onMount>
            <div className="flex flex-wrap items-center gap-3">
              <span className="flex h-14 w-14 items-center justify-center rounded-xl bg-[#F6B400]/12 text-[#F6B400]">
                <Icon aria-hidden className="h-7 w-7" />
              </span>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-gradient-to-r from-[#F6B400] to-[#FFD970] px-3 py-1 text-xs font-bold text-black">
                <Crown aria-hidden className="h-3.5 w-3.5" /> Unggulan
              </span>
              <span className={`rounded-full px-3 py-1 text-xs font-semibold ${available ? 'bg-emerald-400/15 text-emerald-300' : 'bg-white/[0.08] text-[#D6D6D8]'}`}>
                {APP_STATUS_LABEL[app.status]}
              </span>
            </div>
            <h1 className="mt-6 font-display text-4xl font-medium md:text-6xl">{app.name}</h1>
            {app.summary ? <p className="mt-4 text-lg leading-8 text-[#A1A1A6]">{app.summary}</p> : null}
            {app.description ? <p className="mt-4 whitespace-pre-line text-base leading-7 text-[#D6D6D8]">{app.description}</p> : null}

            {app.benefits.length > 0 && (
              <>
                <div className="mt-10">
                  <SectionLabel>Manfaat utama</SectionLabel>
                </div>
                <ul className="space-y-3">
                  {app.benefits.map((benefit) => (
                    <li key={benefit} className="flex items-start gap-3 text-base">
                      <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#F6B400]/15 text-[#F6B400]">
                        <Check aria-hidden className="h-3.5 w-3.5" />
                      </span>
                      {benefit}
                    </li>
                  ))}
                </ul>
              </>
            )}

            {app.pricing ? (
              <div className="mt-10 rounded-2xl border border-white/[0.10] bg-white/[0.03] p-6">
                <p className="text-xs font-bold uppercase tracking-[0.3em] text-[#F6B400]">Harga & paket</p>
                <p className="mt-3 text-lg font-semibold">{app.pricing.label}</p>
                <p className="mt-1 text-sm text-[#A1A1A6]">{app.pricing.note}</p>
              </div>
            ) : null}

            <div className="mt-10">
              {available ? (
                <MagneticLink to={tryHref} className={primaryCta}>
                  {app.tryLabel} <ArrowRight className="h-4 w-4" />
                </MagneticLink>
              ) : (
                <span className="inline-flex h-14 items-center rounded-lg bg-white/[0.06] px-8 text-sm font-semibold text-[#A1A1A6]">Segera Hadir</span>
              )}
            </div>

            <ShareButtons className="mt-10" url={`/aplikasi/${app.key}`} title={app.name} text={app.summary} />
          </Reveal>

          <Reveal onMount delay={0.15}>
            <p className="mb-4 text-xs font-bold uppercase tracking-[0.3em] text-[#A1A1A6]">Cuplikan tampilan</p>
            {app.screenshots?.length ? (
              <div className="space-y-4">
                {app.screenshots.map((shot) => (
                  <img key={shot.src} src={shot.src} alt={shot.alt} width={shot.width} height={shot.height} loading="lazy" className="w-full rounded-2xl border border-white/[0.10]" />
                ))}
              </div>
            ) : app.preview ? (
              <AppPreview kind={app.preview} />
            ) : app.banner ? (
              <img src={app.banner} alt={app.name} className="w-full rounded-2xl border border-white/[0.10]" />
            ) : null}
          </Reveal>
        </div>
      </div>
    </section>
  );
}
