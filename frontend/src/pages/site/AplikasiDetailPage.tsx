import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { APP_STATUS_LABEL, findApp, type HellomApp } from '@/data/apps';
import MagneticLink, { primaryCta } from '@/components/site/MagneticLink';
import { SectionLabel, usePageMeta } from '@/components/site/PageHero';
import { Reveal } from '@/components/site/Reveal';

/** Lightweight UI mock used until real screenshots are added to data/apps.ts. */
function AppPreview({ kind }: { kind: HellomApp['preview'] }) {
  if (kind === 'pos') {
    const orders = [
      { no: 'A-102', table: 'Meja 4', total: 'Rp 86.000', status: 'Diproses' },
      { no: 'A-103', table: 'Bawa pulang', total: 'Rp 42.500', status: 'Baru' },
      { no: 'A-104', table: 'Meja 1', total: 'Rp 128.000', status: 'Selesai' },
    ];
    return (
      <div className="rounded-2xl border border-white/[0.10] bg-[#0E0E11] p-5" aria-label="Contoh tampilan daftar pesanan">
        <div className="grid grid-cols-3 gap-3">
          {[['Omzet hari ini', 'Rp 2,4 jt'], ['Pesanan', '38'], ['Rata-rata', 'Rp 63 rb']].map(([label, value]) => (
            <div key={label} className="rounded-xl bg-white/[0.04] p-3">
              <p className="text-[11px] text-[#A1A1A6]">{label}</p>
              <p className="mt-1 text-sm font-bold">{value}</p>
            </div>
          ))}
        </div>
        <ul className="mt-4 space-y-2">
          {orders.map((order) => (
            <li key={order.no} className="flex items-center justify-between rounded-xl border border-white/[0.06] px-4 py-3 text-sm">
              <span className="font-semibold">{order.no}</span>
              <span className="text-[#A1A1A6]">{order.table}</span>
              <span>{order.total}</span>
              <span className="rounded-full bg-[#F6B400]/15 px-2 py-0.5 text-xs text-[#F6B400]">{order.status}</span>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  return (
    <div className="rounded-2xl border border-white/[0.10] bg-[#0E0E11] p-5" aria-label="Contoh tampilan editor halaman">
      <div className="grid grid-cols-[120px_1fr] gap-4">
        <ul className="space-y-2">
          {['Judul', 'Gambar', 'Testimoni', 'FAQ', 'Form pesan'].map((block) => (
            <li key={block} className="rounded-lg bg-white/[0.04] px-3 py-2 text-xs text-[#D6D6D8]">{block}</li>
          ))}
        </ul>
        <div className="space-y-3 rounded-xl bg-white/[0.03] p-4">
          <div className="h-4 w-3/4 rounded bg-white/[0.14]" />
          <div className="h-3 w-1/2 rounded bg-white/[0.08]" />
          <div className="aspect-[16/7] rounded-lg bg-[radial-gradient(circle_at_40%_30%,rgba(246,180,0,.25),transparent_45%),#15151a]" />
          <div className="h-9 w-32 rounded-lg bg-[#F6B400]" />
        </div>
      </div>
    </div>
  );
}

export default function AplikasiDetailPage() {
  const { slug = '' } = useParams();
  const app = findApp(slug);
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  usePageMeta(app ? app.name : 'Aplikasi tidak ditemukan', app?.summary ?? 'Aplikasi Hellom untuk UMKM.', `/aplikasi/${slug}`);

  if (!app) {
    return (
      <section className="px-5 pb-24 pt-40 text-center md:px-10">
        <h1 className="font-display text-4xl">Aplikasi tidak ditemukan</h1>
        <Link to="/aplikasi" className="mt-8 inline-flex min-h-11 items-center gap-2 text-[#F6B400]">
          <ArrowLeft className="h-4 w-4" /> Kembali ke katalog
        </Link>
      </section>
    );
  }

  const Icon = app.icon;
  const available = app.status === 'available';
  const tryHref = isAuthenticated ? app.tryHref.member : app.tryHref.guest;

  return (
    <section className="px-5 pb-24 pt-32 md:px-10 md:pt-40 lg:px-16">
      <div className="mx-auto max-w-6xl">
        <Link to="/aplikasi" className="inline-flex min-h-11 items-center gap-2 text-sm text-[#A1A1A6] hover:text-[#F5F5F2]">
          <ArrowLeft className="h-4 w-4" /> Semua aplikasi
        </Link>

        <div className="mt-6 grid gap-12 lg:grid-cols-[1fr_1fr] lg:items-start">
          <Reveal onMount>
            <div className="flex items-center gap-4">
              <span className="flex h-14 w-14 items-center justify-center rounded-xl bg-[#F6B400]/12 text-[#F6B400]">
                <Icon aria-hidden className="h-7 w-7" />
              </span>
              <span className={`rounded-full px-3 py-1 text-xs font-semibold ${available ? 'bg-emerald-400/15 text-emerald-300' : 'bg-white/[0.08] text-[#D6D6D8]'}`}>
                {APP_STATUS_LABEL[app.status]}
              </span>
            </div>
            <h1 className="mt-6 font-display text-4xl font-medium md:text-6xl">{app.name}</h1>
            <p className="mt-4 text-lg leading-8 text-[#A1A1A6]">{app.summary}</p>

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
                  Coba Sekarang <ArrowRight className="h-4 w-4" />
                </MagneticLink>
              ) : (
                <span className="inline-flex h-14 items-center rounded-lg bg-white/[0.06] px-8 text-sm font-semibold text-[#A1A1A6]">Segera Hadir</span>
              )}
            </div>
          </Reveal>

          <Reveal onMount delay={0.15}>
            <p className="mb-4 text-xs font-bold uppercase tracking-[0.3em] text-[#A1A1A6]">Cuplikan tampilan</p>
            {app.screenshots?.length ? (
              <div className="space-y-4">
                {app.screenshots.map((shot) => (
                  <img key={shot.src} src={shot.src} alt={shot.alt} width={shot.width} height={shot.height} loading="lazy" className="w-full rounded-2xl border border-white/[0.10]" />
                ))}
              </div>
            ) : (
              <AppPreview kind={app.preview} />
            )}
          </Reveal>
        </div>
      </div>
    </section>
  );
}
