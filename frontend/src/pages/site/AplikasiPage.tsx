import { Link } from 'react-router-dom';
import { ArrowRight, Check, Crown } from 'lucide-react';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { APP_STATUS_LABEL } from '@/data/apps';
import { usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';
import AppPreview from '@/components/site/AppPreview';
import ShareButtons from '@/components/site/ShareButtons';
import useFlagshipApps, { type ShowcaseApp } from '@/components/site/useFlagshipApps';

// Showcase of Hellom's flagship SaaS apps (POS, Landing Page Builder, …). Regular digital
// products live on /produk. Banners are uploaded by the super admin per app.

function AppBanner({ app, priority }: { app: ShowcaseApp; priority: boolean }) {
  const Icon = app.icon;
  if (app.banner || app.bannerMobile) {
    const desktop = app.banner ?? app.bannerMobile ?? '';
    return (
      <picture>
        {app.bannerMobile ? <source media="(max-width: 767px)" srcSet={app.bannerMobile} /> : null}
        <img
          src={desktop}
          alt={`Banner ${app.name}`}
          width={1920}
          height={800}
          loading={priority ? 'eager' : 'lazy'}
          decoding="async"
          className={`w-full object-cover ${app.bannerMobile ? 'aspect-[4/5] md:aspect-[12/5]' : 'aspect-[16/10] md:aspect-[12/5]'}`}
        />
      </picture>
    );
  }

  // No banner uploaded yet: branded artwork with a live UI mock.
  return (
    <div className="flex aspect-[4/5] items-center justify-center overflow-hidden bg-[radial-gradient(circle_at_20%_20%,rgba(246,180,0,.28),transparent_40%),radial-gradient(circle_at_85%_80%,rgba(246,180,0,.12),transparent_45%),#0B0B0D] px-6 pt-16 sm:aspect-[16/10] md:aspect-[12/5] md:px-12">
      <div className="w-full max-w-2xl">
        {app.preview ? <AppPreview kind={app.preview} /> : <Icon aria-hidden className="mx-auto h-24 w-24 text-[#F6B400]" />}
      </div>
    </div>
  );
}

function ShowcaseBlock({ app, index, isAuthenticated }: { app: ShowcaseApp; index: number; isAuthenticated: boolean }) {
  const Icon = app.icon;
  const available = app.status === 'available';

  return (
    <Reveal as="article" onMount={index === 0} delay={0.1} className="overflow-hidden rounded-[28px] border border-[#F6B400]/25 bg-gradient-to-b from-white/[0.05] to-white/[0.01] shadow-[0_30px_90px_rgba(246,180,0,0.08)]">
      <div className="relative">
        <AppBanner app={app} priority={index === 0} />
        <span className="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-gradient-to-r from-[#F6B400] to-[#FFD970] px-3 py-1.5 text-xs font-bold text-black shadow-lg md:left-6 md:top-6">
          <Crown aria-hidden className="h-3.5 w-3.5" /> Aplikasi Unggulan
        </span>
        <ShareButtons compact url={`/aplikasi/${app.key}`} title={app.name} text={app.summary} className="absolute right-4 top-4 md:right-6 md:top-6" />
      </div>

      <div className="grid gap-8 p-6 md:p-10 lg:grid-cols-[1.2fr_1fr] lg:items-end">
        <div>
          <div className="flex items-center gap-3">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#F6B400]/12 text-[#F6B400]">
              <Icon aria-hidden className="h-6 w-6" />
            </span>
            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${available ? 'bg-emerald-400/15 text-emerald-300' : 'bg-white/[0.08] text-[#D6D6D8]'}`}>
              {APP_STATUS_LABEL[app.status]}
            </span>
          </div>
          <h2 className="mt-5 font-display text-3xl font-medium md:text-5xl">{app.name}</h2>
          {app.summary ? <p className="mt-3 max-w-2xl text-base leading-7 text-[#A1A1A6] md:text-lg">{app.summary}</p> : null}
          {app.benefits.length > 0 && (
            <ul className="mt-6 grid gap-2 sm:grid-cols-2">
              {app.benefits.slice(0, 4).map((benefit) => (
                <li key={benefit} className="flex items-start gap-2.5 text-sm text-[#D6D6D8]">
                  <Check aria-hidden className="mt-0.5 h-4 w-4 shrink-0 text-[#F6B400]" />
                  {benefit}
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="rounded-2xl border border-white/[0.10] bg-black/30 p-5">
          {app.pricing ? (
            <>
              <p className="text-xs font-bold uppercase tracking-[0.3em] text-[#F6B400]">Harga & paket</p>
              <p className="mt-2 font-semibold">{app.pricing.label}</p>
              <p className="mt-1 text-sm text-[#A1A1A6]">{app.pricing.note}</p>
            </>
          ) : null}
          <div className="mt-5 grid gap-3 sm:grid-cols-2">
            {available ? (
              <Link
                to={isAuthenticated ? app.tryHref.member : app.tryHref.guest}
                className="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#F6B400] px-4 text-sm font-bold text-black transition-colors hover:bg-[#FFCC47]"
              >
                {app.tryLabel} <ArrowRight aria-hidden className="h-4 w-4" />
              </Link>
            ) : (
              <span className="flex min-h-12 items-center justify-center rounded-xl bg-white/[0.06] px-4 text-sm font-semibold text-[#A1A1A6]">Segera Hadir</span>
            )}
            <Link
              to={`/aplikasi/${app.key}`}
              className="flex min-h-12 items-center justify-center rounded-xl border border-white/[0.16] px-4 text-sm font-semibold transition-colors hover:border-[#F6B400]"
            >
              Lihat Detail
            </Link>
          </div>
        </div>
      </div>
    </Reveal>
  );
}

export default function AplikasiPage() {
  const { apps } = useFlagshipApps();
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  usePageMeta(
    'Aplikasi Unggulan',
    'Aplikasi unggulan Hellom untuk UMKM: kasir POS untuk pesanan dan laporan, serta pembuat halaman jualan tanpa coding.',
    '/aplikasi'
  );

  return (
    <section className="px-5 pb-24 pt-32 md:px-10 md:pt-40 lg:px-16">
      <div className="mx-auto max-w-6xl">
        <Reveal onMount>
          <p className="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.3em] text-[#F6B400]">
            <Crown aria-hidden className="h-4 w-4" /> Aplikasi Unggulan
          </p>
          <div className="mt-4 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div>
              <h1 className="font-display text-4xl font-medium md:text-6xl">Aplikasi andalan untuk usahamu</h1>
              <p className="mt-4 max-w-xl text-base leading-7 text-[#A1A1A6]">
                Dibangun dan dirawat langsung oleh tim Hellom. Coba sekarang, pakai dari HP maupun laptop.
              </p>
            </div>
            <ShareButtons url="/aplikasi" title="Aplikasi unggulan Hellom" text="Kasir POS & Landing Page Builder untuk UMKM" className="md:max-w-md" />
          </div>
        </Reveal>

        <div className="mt-12 space-y-10 md:mt-16 md:space-y-14">
          {apps.map((app, index) => (
            <ShowcaseBlock key={app.key} app={app} index={index} isAuthenticated={isAuthenticated} />
          ))}
        </div>

        <Reveal className="mt-16 flex flex-col items-start justify-between gap-4 rounded-2xl border border-white/[0.10] bg-white/[0.03] p-6 sm:flex-row sm:items-center md:p-8">
          <div>
            <p className="font-display text-xl">Cari template, ekstensi, atau kursus?</p>
            <p className="mt-1 text-sm text-[#A1A1A6]">Produk digital lainnya ada di katalog produk — sekali beli, akses selamanya.</p>
          </div>
          <Link to="/produk" className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-white/[0.16] px-5 text-sm font-semibold hover:border-[#F6B400]">
            Lihat Produk Digital <ArrowRight aria-hidden className="h-4 w-4" />
          </Link>
        </Reveal>
      </div>
    </section>
  );
}
