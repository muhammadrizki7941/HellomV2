import { TICKER_ITEMS } from '../../lib/landingData';

export const TickerSection = () => {
  const all = [...TICKER_ITEMS, ...TICKER_ITEMS];

  return (
    <div className="overflow-hidden border-y border-white/[0.08] py-4">
      <div className="animate-ticker flex w-max gap-10">
        {all.map((item, i) => (
          <span key={i} className="font-body flex items-center gap-2 whitespace-nowrap text-sm text-brand-muted">
            <span className="text-base text-brand-accent">*</span> {item}
          </span>
        ))}
      </div>
    </div>
  );
};
