import { STATS_DATA } from '../../lib/landingData';

export const StatsSection = () => {
  return (
    <div className="border-b border-white/[0.08] bg-zinc-950/70">
      <div className="mx-auto grid max-w-6xl grid-cols-2 md:grid-cols-4">
        {STATS_DATA.map((stat, i) => (
          <div
            key={i}
            className={`px-8 py-10 text-center ${
              i < 3 ? 'border-b border-white/[0.08] md:border-b-0 md:border-r' : ''
            }`}
          >
            <div className="mb-1 font-display text-3xl font-bold text-brand-accent sm:text-4xl">{stat.num}</div>
            <div className="font-body text-xs leading-snug text-brand-muted sm:text-sm">{stat.label}</div>
          </div>
        ))}
      </div>
    </div>
  );
};
