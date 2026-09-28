import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Search } from 'lucide-react';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { APP_CATEGORIES, APP_STATUS_LABEL, HELLOM_APPS } from '@/data/apps';
import { usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';

// Deliberately the calmest page: plain fades only, big touch targets, short copy.
export default function AplikasiPage() {
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('semua');
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  usePageMeta(
    'Katalog Aplikasi',
    'Aplikasi Hellom untuk UMKM: kasir POS untuk pesanan dan laporan, serta pembuat halaman jualan tanpa coding.',
    '/aplikasi'
  );

  // Only categories that actually have apps get a chip.
  const chips = useMemo(
    () => [{ key: 'semua', label: 'Semua' }, ...APP_CATEGORIES.filter((c) => HELLOM_APPS.some((app) => app.category === c.key))],
    []
  );

  const apps = useMemo(() => {
    const q = query.trim().toLowerCase();
    return HELLOM_APPS.filter((app) =>
      (category === 'semua' || app.category === category)
      && (q === '' || `${app.name} ${app.summary}`.toLowerCase().includes(q))
    );
  }, [category, query]);

  return (
    <section className="px-5 pb-24 pt-32 md:px-10 md:pt-40 lg:px-16">
      <div className="mx-auto max-w-6xl">
        <Reveal onMount>
          <h1 className="font-display text-4xl font-medium md:text-6xl">Aplikasi untuk usahamu</h1>
          <p className="mt-4 max-w-xl text-base leading-7 text-[#A1A1A6]">
            Pilih aplikasi yang kamu butuhkan, coba langsung, dan pakai dari HP maupun laptop.
          </p>
        </Reveal>

        <Reveal onMount delay={0.1} className="mt-8 space-y-4">
          <label className="relative block max-w-xl">
            <span className="sr-only">Cari aplikasi</span>
            <Search aria-hidden className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-[#A1A1A6]" />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Cari aplikasi, misal: kasir"
              className="h-12 w-full rounded-xl border border-white/[0.12] bg-white/[0.04] pl-12 pr-4 text-base text-[#F5F5F2] placeholder:text-[#8B8B90] focus:border-[#F6B400] focus:outline-none focus:ring-2 focus:ring-[#F6B400]/40"
            />
          </label>
          <div role="group" aria-label="Filter kategori" className="scrollbar-hide -mx-5 flex gap-2 overflow-x-auto px-5">
            {chips.map((chip) => (
              <button
                key={chip.key}
                type="button"
                onClick={() => setCategory(chip.key)}
                aria-pressed={category === chip.key}
                className={`h-11 shrink-0 rounded-full px-5 text-sm font-semibold transition-colors ${
                  category === chip.key ? 'bg-[#F6B400] text-black' : 'border border-white/[0.12] text-[#D6D6D8] hover:border-white/30'
                }`}
              >
                {chip.label}
              </button>
            ))}
          </div>
        </Reveal>

        {apps.length === 0 ? (
          <p className="mt-12 rounded-2xl border border-dashed border-white/[0.12] p-8 text-center text-[#A1A1A6]">
            Belum ada aplikasi yang cocok. Coba kata kunci lain.
          </p>
        ) : (
          <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {apps.map((app, index) => {
              const Icon = app.icon;
              const available = app.status === 'available';
              return (
                <Reveal as="li" key={app.slug} onMount delay={0.15 + index * 0.05} className="flex flex-col rounded-2xl border border-white/[0.10] bg-white/[0.03] p-6">
                  <div className="flex items-start justify-between gap-3">
                    <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#F6B400]/12 text-[#F6B400]">
                      <Icon aria-hidden className="h-6 w-6" />
                    </span>
                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${available ? 'bg-emerald-400/15 text-emerald-300' : 'bg-white/[0.08] text-[#D6D6D8]'}`}>
                      {APP_STATUS_LABEL[app.status]}
                    </span>
                  </div>
                  <h2 className="mt-5 font-display text-xl">{app.name}</h2>
                  <p className="mt-2 line-clamp-3 text-sm leading-6 text-[#A1A1A6]">{app.summary}</p>
                  <div className="mt-auto grid grid-cols-2 gap-3 pt-6">
                    <Link
                      to={`/aplikasi/${app.slug}`}
                      className="flex min-h-11 items-center justify-center rounded-lg border border-white/[0.14] px-3 text-sm font-semibold hover:border-[#F6B400]"
                    >
                      Lihat Detail
                    </Link>
                    {available ? (
                      <Link
                        to={isAuthenticated ? app.tryHref.member : app.tryHref.guest}
                        className="flex min-h-11 items-center justify-center rounded-lg bg-[#F6B400] px-3 text-sm font-bold text-black hover:bg-[#FFCC47]"
                      >
                        Coba Sekarang
                      </Link>
                    ) : (
                      <span className="flex min-h-11 items-center justify-center rounded-lg bg-white/[0.06] px-3 text-sm font-semibold text-[#A1A1A6]">
                        Segera Hadir
                      </span>
                    )}
                  </div>
                </Reveal>
              );
            })}
          </ul>
        )}
      </div>
    </section>
  );
}
