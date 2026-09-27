import { Link } from 'react-router-dom';
import { getSessionUser, getToken } from '@/lib/hellomApi';
import { savePendingCheckoutIntent } from '@/lib/checkoutIntent';
import { CTA_CONTENT } from '../../lib/landingData';

export const CTASection = () => {
  const isAuthenticated = Boolean(getToken() && getSessionUser());
  const primaryHref = isAuthenticated ? '/dashboard/apps/pos?subscribe=1' : '/register?app=pos&plan=pos_starter&subscribe=1';
  const secondaryHref = isAuthenticated ? '/dashboard' : '/login';

  const handlePrimaryClick = () => {
    if (!isAuthenticated) {
      savePendingCheckoutIntent({
        kind: 'app_subscription',
        app_slug: 'pos',
        app_name: 'POS',
        return_to: '/dashboard/apps/pos?subscribe=1',
      });
    }
  };
  const handleSecondaryClick = () => {
    if (!isAuthenticated) {
      localStorage.setItem('hellom_intended_url', '/dashboard');
    }
  };

  return (
    <section className="relative overflow-hidden px-6 py-20 text-center sm:py-28" id="cta">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute left-1/2 top-1/2 h-[420px] w-[620px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-yellow-400/8 blur-3xl" />
      </div>
      <div className="relative mx-auto max-w-4xl">
        <div className="mb-3 inline-flex rounded-lg border border-yellow-400/20 bg-yellow-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-yellow-400 sm:mb-4">
          Mulai dari sini
        </div>
        <h2 className="text-4xl font-bold leading-tight tracking-tight text-white sm:text-6xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
          {CTA_CONTENT.headingLines[0]} <span className="text-yellow-400 italic">{CTA_CONTENT.headingLines[1]}</span> {CTA_CONTENT.headingLines[2]}
        </h2>
        <p className="mx-auto mt-6 max-w-3xl text-base leading-relaxed text-zinc-400 sm:text-xl">
          {CTA_CONTENT.description}
        </p>
        <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row sm:gap-4">
          <Link
            to={primaryHref}
            onClick={handlePrimaryClick}
            className="inline-flex items-center justify-center rounded-xl bg-yellow-400 px-8 py-4 text-base font-semibold text-black transition-all hover:scale-105 hover:bg-yellow-300"
          >
            {CTA_CONTENT.ctaPrimary}
          </Link>
          <Link
            to={secondaryHref}
            onClick={handleSecondaryClick}
            className="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-8 py-4 text-base font-semibold text-white transition-all hover:border-white/20 hover:bg-white/10"
          >
            {CTA_CONTENT.ctaSecondary}
          </Link>
        </div>
      </div>
    </section>
  );
};
