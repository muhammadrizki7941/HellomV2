import { Check, X } from 'lucide-react';

export function Pricing() {
  const plans = [
    {
      label: 'Gratis Selamanya',
      price: '0',
      period: 'tidak perlu kartu kredit',
      features: [
        { text: 'Landing Page Builder', included: true },
        { text: '1 halaman aktif', included: true },
        { text: 'Template dasar (10+)', included: true },
        { text: 'Analitik kunjungan', included: true },
        { text: 'POS & Kasir', included: false },
        { text: 'Aplikasi Member', included: false },
        { text: 'Custom domain', included: false },
      ],
      cta: 'Mulai Gratis',
      ctaStyle: 'secondary',
      featured: false,
    },
    {
      label: 'Langganan Bulanan',
      badge: 'Paling Laku',
      price: '199rb',
      period: 'per bulan, bisa cancel kapan saja',
      features: [
        { text: 'Semua fitur Gratis', included: true },
        { text: 'POS & Kasir Digital', included: true },
        { text: 'Aplikasi Member Loyalitas', included: true },
        { text: 'Custom domain', included: true },
        { text: 'Template premium (50+)', included: true },
        { text: 'Laporan & analytics lengkap', included: true },
        { text: 'Notifikasi WhatsApp otomatis', included: true },
      ],
      cta: 'Mulai 14 Hari Gratis',
      ctaStyle: 'primary',
      featured: true,
    },
    {
      label: 'Custom Development',
      price: 'Cerita dulu',
      priceStyle: 'text-2xl',
      period: 'penawaran sesuai kebutuhan bisnis',
      features: [
        { text: 'Desain & development kustom', included: true },
        { text: 'Integrasi sistem existing', included: true },
        { text: 'SLA dan dukungan khusus', included: true },
        { text: 'Training tim internal', included: true },
        { text: 'Branding & white-label', included: true },
        { text: 'Multi-tenant / franchise', included: true },
      ],
      cta: 'Diskusi Kebutuhan',
      ctaStyle: 'tertiary',
      featured: false,
    },
  ];

  return (
    <section id="harga" className="relative py-12 sm:py-24 bg-zinc-900/30 border-y border-white/5">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Section Header */}
        <div className="text-center max-w-3xl mx-auto mb-8 sm:mb-16">
          <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/20 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-3 sm:mb-4">
            Soal Harga
          </div>
          <h2 className="text-2xl sm:text-4xl lg:text-5xl font-bold text-white mb-4 sm:mb-6 tracking-tight px-4" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Harga harusnya bisa{' '}
            <span className="text-yellow-400 italic">dijelaskan, bukan cuma dikasih.</span>
          </h2>
          <p className="text-sm sm:text-lg text-zinc-400 leading-relaxed px-4">
            Tidak ada angka yang tiba-tiba muncul di invoice. Hellom percaya transparansi adalah bagian dari pelayanan.
          </p>
        </div>

        {/* Pricing Grid - Scroll horizontal di mobile */}
        <div className="grid md:grid-cols-3 gap-4 sm:gap-6 max-w-6xl mx-auto overflow-x-auto pb-4 md:pb-0 snap-x snap-mandatory md:snap-none -mx-4 px-4 md:mx-0 md:px-0">
          {plans.map((plan, index) => (
            <div
              key={index}
              className={`relative bg-black border rounded-xl sm:rounded-2xl p-5 sm:p-8 transition-all duration-300 min-w-[280px] md:min-w-0 snap-center ${
                plan.featured
                  ? 'border-yellow-400/40 shadow-2xl shadow-yellow-400/10 hover:border-yellow-400/60 md:scale-105'
                  : 'border-white/5 hover:border-white/10'
              }`}
            >
              {/* Featured Badge */}
              {plan.badge && (
                <div className="absolute -top-4 left-1/2 -translate-x-1/2 px-4 py-1.5 bg-yellow-400 text-black text-xs font-bold uppercase tracking-wider rounded-full whitespace-nowrap">
                  {plan.badge}
                </div>
              )}

              {/* Plan Content */}
              <div className="space-y-6">
                {/* Header */}
                <div className="space-y-4 pb-6 border-b border-white/5">
                  <div className="text-xs font-bold text-zinc-500 uppercase tracking-wider">
                    {plan.label}
                  </div>
                  <div className="space-y-2">
                    <div className={`font-bold text-white tracking-tight ${plan.priceStyle || 'text-5xl'}`} style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                      {plan.price === '0' || plan.price.includes('rb') ? 'Rp ' : ''}
                      <span className="text-yellow-400">{plan.price}</span>
                    </div>
                    <p className="text-sm text-zinc-500">{plan.period}</p>
                  </div>
                </div>

                {/* Features */}
                <ul className="space-y-3">
                  {plan.features.map((feature, i) => (
                    <li key={i} className="flex items-start gap-3">
                      {feature.included ? (
                        <Check className="w-5 h-5 text-yellow-400 flex-shrink-0 mt-0.5" />
                      ) : (
                        <X className="w-5 h-5 text-zinc-700 flex-shrink-0 mt-0.5" />
                      )}
                      <span className={`text-sm ${feature.included ? 'text-zinc-200' : 'text-zinc-600'}`}>
                        {feature.text}
                      </span>
                    </li>
                  ))}
                </ul>

                {/* CTA Button */}
                <button
                  className={`w-full py-3.5 rounded-xl font-semibold text-sm transition-all ${
                    plan.ctaStyle === 'primary'
                      ? 'bg-yellow-400 hover:bg-yellow-300 text-black hover:scale-105'
                      : plan.ctaStyle === 'secondary'
                      ? 'bg-white/5 hover:bg-white/10 border border-white/10 hover:border-white/20 text-white'
                      : 'bg-white/5 hover:bg-white/10 border border-yellow-400/20 hover:border-yellow-400/40 text-yellow-400'
                  }`}
                >
                  {plan.cta}
                </button>
              </div>
            </div>
          ))}
        </div>

        {/* Footer Note */}
        <p className="text-center text-sm text-zinc-500 mt-12 max-w-2xl mx-auto">
          Produk <span className="text-white font-medium">Sekali Beli</span> tersedia terpisah. Template, source code, dan kursus bisa dibeli tanpa berlangganan.{' '}
          <a href="#produk" className="text-yellow-400 hover:text-yellow-300 transition-colors">
            Lihat semua produk →
          </a>
        </p>
      </div>
    </section>
  );
}
