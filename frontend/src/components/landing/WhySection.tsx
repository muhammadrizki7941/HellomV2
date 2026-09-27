import { WHY_POINTS } from '../../lib/landingData';
import { SectionLabel } from './SectionLabel';

export const WhySection = () => {
  return (
    <section className="border-y border-white/[0.08] bg-brand-bg2 px-6 py-20 sm:py-28">
      <div className="mx-auto grid max-w-6xl grid-cols-1 items-start gap-16 md:grid-cols-2">
        <div>
          <SectionLabel>kenapa section ini penting</SectionLabel>
          <p
            className="mb-6 font-display font-bold leading-snug text-brand-text"
            style={{ fontSize: 'clamp(1.8rem, 3.5vw, 2.6rem)', letterSpacing: '-0.5px' }}
          >
            Produk yang bagus,
            <br />
            disajikan dengan benar,
            <br />
            <span className="text-brand-accent">akan berbicara sendiri.</span>
          </p>
          <p className="max-w-sm font-body text-sm leading-relaxed text-brand-muted">
            Pengunjung harus cepat paham apa produk utama, apa penawaran entry, dan apa upsell yang bisa dibeli setelah percaya.
            Itu yang bikin halaman premium terasa benar-benar bekerja, bukan cuma terlihat bagus.
          </p>
        </div>

        <div className="flex flex-col gap-4">
          {WHY_POINTS.map((point) => (
            <div
              key={point.num}
              className="flex items-start gap-4 rounded-xl border border-white/[0.06] bg-brand-bg3 p-5 transition-colors hover:border-brand-accent/20"
            >
              <span className="mt-0.5 shrink-0 rounded bg-brand-accent/10 px-2 py-1 font-body text-xs font-bold text-brand-accent">
                {point.num}
              </span>
              <div>
                <p className="mb-1 font-body text-sm font-semibold text-brand-text">{point.title}</p>
                <p className="font-body text-xs leading-relaxed text-brand-muted">{point.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};
