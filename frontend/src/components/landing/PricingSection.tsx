import { Check, X } from 'lucide-react';
import { Link } from 'react-router-dom';

const plans = [
  {
    label: 'Gratis',
    price: '0',
    period: 'akses dasar untuk mulai',
    features: [
      { text: 'Landing Page Builder', included: true },
      { text: '1 halaman aktif', included: true },
      { text: 'Template dasar (10+)', included: true },
      { text: 'Analitik kunjungan', included: true },
      { text: 'POS & Kasir', included: false },
      { text: 'Aplikasi Member', included: false },
      { text: 'Produk sekali beli premium', included: false },
    ],
    cta: 'Mulai Sekarang',
    href: '/register?app=landing_builder&plan=free',
    kind: 'secondary',
    featured: false,
  },
  {
    label: 'Pro - Rp 199rb/bln',
    badge: 'Paling Laku',
    price: '199rb',
    period: 'akses pro untuk kebutuhan operasional',
    features: [
      { text: 'Semua fitur paket Gratis', included: true },
      { text: 'POS & Kasir Digital', included: true },
      { text: 'Aplikasi Member Loyalitas', included: true },
      { text: 'Promo & banner campaign', included: true },
      { text: 'Template premium (50+)', included: true },
      { text: 'Laporan & analytics lengkap', included: true },
      { text: 'Notifikasi operasional otomatis', included: true },
    ],
    cta: 'Mulai Sekarang',
    href: '/register?app=pos&plan=pos_starter&subscribe=1',
    kind: 'primary',
    featured: true,
  },
  {
    label: 'Custom / Enterprise',
    price: 'Cerita dulu',
    period: 'penawaran sesuai kebutuhan bisnis',
    features: [
      { text: 'Desain & development kustom', included: true },
      { text: 'Integrasi sistem existing', included: true },
      { text: 'SLA dan dukungan khusus', included: true },
      { text: 'Training tim internal', included: true },
      { text: 'Branding & white-label', included: true },
      { text: 'Paket produk campuran download + SaaS', included: true },
    ],
    cta: 'Hubungi Kami via WhatsApp ->',
    href: '/register',
    kind: 'tertiary',
    featured: false,
  },
] as const;

export const PricingSection = () => {
  return (
    <section id="harga" className="relative border-y border-white/5 bg-zinc-900/30 py-12 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto mb-8 max-w-3xl text-center sm:mb-16">
          <div className="mb-3 inline-flex rounded-lg border border-yellow-400/20 bg-yellow-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-yellow-400 sm:mb-4">
            Soal Harga
          </div>
          <h2 className="px-4 text-2xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Bayar sesuai caramu. <span className="text-yellow-400 italic">Bulanan, tahunan, lifetime, atau beli putus.</span>
          </h2>
          <p className="mt-4 px-4 text-sm leading-relaxed text-zinc-400 sm:text-lg">
            Pilih skema pembayaran yang paling cocok untuk cara kerja bisnis kamu, dari akses dasar sampai kebutuhan enterprise.
          </p>
        </div>

        <div className="-mx-4 grid snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-4 md:mx-0 md:grid-cols-3 md:overflow-visible md:px-0 md:pb-0">
          {plans.map((plan) => (
            <article
              key={plan.label}
              className={`relative min-w-[280px] snap-center rounded-xl border bg-black p-5 transition-all duration-300 sm:rounded-2xl sm:p-8 ${
                plan.featured
                  ? 'border-yellow-400/40 shadow-2xl shadow-yellow-400/10 md:scale-105'
                  : 'border-white/5 hover:border-white/10'
              }`}
            >
              {plan.badge ? (
                <div className="absolute -top-4 left-1/2 -translate-x-1/2 rounded-full bg-yellow-400 px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-black">
                  {plan.badge}
                </div>
              ) : null}

              <div className="space-y-6">
                <div className="space-y-4 border-b border-white/5 pb-6">
                  <div className="text-xs font-bold uppercase tracking-wider text-zinc-500">{plan.label}</div>
                  <div className="space-y-2">
                    <div
                      className={`${plan.price === 'Cerita dulu' ? 'text-2xl' : 'text-5xl'} font-bold tracking-tight text-white`}
                      style={{ fontFamily: 'Space Grotesk, sans-serif' }}
                    >
                      {plan.price === '0' ? (
                        <span className="text-yellow-400">Gratis</span>
                      ) : (
                        <>
                          {plan.price.includes('rb') ? 'Rp ' : ''}
                          <span className="text-yellow-400">{plan.price}</span>
                        </>
                      )}
                    </div>
                    <p className="text-sm text-zinc-500">{plan.period}</p>
                  </div>
                </div>

                <ul className="space-y-3">
                  {plan.features.map((feature) => (
                    <li key={feature.text} className="flex items-start gap-3">
                      {feature.included ? (
                        <Check className="mt-0.5 h-5 w-5 shrink-0 text-yellow-400" />
                      ) : (
                        <X className="mt-0.5 h-5 w-5 shrink-0 text-zinc-700" />
                      )}
                      <span className={`text-sm ${feature.included ? 'text-zinc-200' : 'text-zinc-600'}`}>{feature.text}</span>
                    </li>
                  ))}
                </ul>

                <Link
                  to={plan.href}
                  className={`block w-full rounded-xl py-3.5 text-center text-sm font-semibold transition-all ${
                    plan.kind === 'primary'
                      ? 'bg-yellow-400 text-black hover:scale-105 hover:bg-yellow-300'
                      : plan.kind === 'secondary'
                        ? 'border border-white/10 bg-white/5 text-white hover:border-white/20 hover:bg-white/10'
                        : 'border border-yellow-400/20 bg-white/5 text-yellow-400 hover:border-yellow-400/40 hover:bg-white/10'
                  }`}
                >
                  {plan.cta}
                </Link>
              </div>
            </article>
          ))}
        </div>

        <p className="mx-auto mt-12 max-w-2xl text-center text-sm text-zinc-500">
          Produk <span className="font-medium text-white">sekali beli</span> seperti template, source code, dan kursus bisa dijual terpisah dari plan berlangganan.
        </p>
      </div>
    </section>
  );
};
