import { ArrowRight } from 'lucide-react';
import { useState } from 'react';

export function Products() {
  const [activeTab, setActiveTab] = useState('Semua');

  const tabs = ['Semua', 'Berlangganan', 'Sekali Beli', 'Gratis'];

  const products = [
    {
      icon: '🧾',
      badges: ['Berlangganan', 'Terpopuler'],
      badgeColors: ['yellow', 'red'],
      title: 'POS / Kasir Digital',
      description: 'Kelola pesanan, stok, transaksi, dan laporan harian dari satu aplikasi. Cocok untuk restoran & toko.',
      price: 'Rp 99rb',
      period: '/ bulan',
      cta: 'Coba 14 Hari',
      ctaStyle: 'primary',
    },
    {
      icon: '🚀',
      badges: ['Gratis Selamanya'],
      badgeColors: ['blue'],
      title: 'Landing Page Builder',
      description: 'Buat landing page bisnis lo dalam menit. Tampil profesional dengan template yang bisa langsung dipakai.',
      price: 'Gratis',
      cta: 'Mulai Sekarang',
      ctaStyle: 'secondary',
    },
    {
      icon: '👥',
      badges: ['Berlangganan'],
      badgeColors: ['yellow'],
      title: 'Aplikasi Member & Loyalitas',
      description: 'Bangun komunitas pelanggan setia. Kelola poin, reward, dan histori transaksi member dengan mudah.',
      price: 'Rp 149rb',
      period: '/ bulan',
      cta: 'Coba Gratis',
      ctaStyle: 'primary',
    },
    {
      icon: '🎨',
      badges: ['Sekali Beli'],
      badgeColors: ['green'],
      title: 'Template Toko Online Pro',
      description: '50+ desain toko online siap pakai. Responsif, modern, dan mudah dikustomisasi sesuai brand lo.',
      oldPrice: 'Rp 149rb',
      price: 'Rp 79rb',
      cta: 'Beli Sekarang',
      ctaStyle: 'primary',
    },
    {
      icon: '💻',
      badges: ['Sekali Beli', 'Baru'],
      badgeColors: ['green', 'red'],
      title: 'Source Code Laravel UMKM Starter',
      description: 'Boilerplate Laravel lengkap dengan auth, dashboard, manajemen produk & laporan. Hemat 40 jam development.',
      price: 'Rp 199rb',
      cta: 'Beli Sekarang',
      ctaStyle: 'primary',
    },
    {
      icon: '🎓',
      badges: ['Sekali Beli'],
      badgeColors: ['green'],
      title: 'Kursus Digital Marketing untuk UMKM',
      description: 'Dari nol sampai bisa iklan Meta Ads, TikTok, dan WhatsApp Blast dengan budget terbatas.',
      price: 'Rp 149rb',
      cta: 'Beli Sekarang',
      ctaStyle: 'primary',
    },
  ];

  const badgeStyles = {
    yellow: 'bg-yellow-400/10 border-yellow-400/20 text-yellow-400',
    green: 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400',
    blue: 'bg-blue-500/10 border-blue-500/20 text-blue-400',
    red: 'bg-red-500/10 border-red-500/20 text-red-400',
  };

  return (
    <section className="relative py-12 sm:py-24 bg-zinc-900/30 border-y border-white/5">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Section Header */}
        <div className="flex flex-col md:flex-row items-start md:items-end justify-between gap-4 sm:gap-6 mb-8 sm:mb-12">
          <div>
            <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/20 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-3 sm:mb-4">
              Produk Unggulan
            </div>
            <h2 className="text-2xl sm:text-3xl lg:text-4xl font-bold text-white tracking-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
              Pilihan terbaik dari kami
            </h2>
          </div>

          <div className="flex items-center gap-3 sm:gap-4 w-full md:w-auto overflow-x-auto pb-2 md:pb-0">
            {/* Tabs - Scrollable di mobile */}
            <div className="flex gap-1 p-1 bg-zinc-800/50 border border-white/5 rounded-lg sm:rounded-xl flex-shrink-0">
              {tabs.map((tab) => (
                <button
                  key={tab}
                  onClick={() => setActiveTab(tab)}
                  className={`px-3 sm:px-4 py-1.5 sm:py-2 text-xs sm:text-sm font-semibold rounded-md sm:rounded-lg transition-all whitespace-nowrap ${
                    activeTab === tab
                      ? 'bg-yellow-400 text-black'
                      : 'text-zinc-400 hover:text-white hover:bg-white/5'
                  }`}
                >
                  {tab}
                </button>
              ))}
            </div>

            <a href="#" className="hidden sm:inline text-sm font-semibold text-yellow-400 hover:text-yellow-300 transition-colors whitespace-nowrap">
              Lihat semua →
            </a>
          </div>
        </div>

        {/* Products Grid - 2 kolom di mobile */}
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6">
          {products.map((product, index) => (
            <div
              key={index}
              className="group bg-black border border-white/5 hover:border-yellow-400/20 rounded-xl sm:rounded-2xl overflow-hidden transition-all duration-300 hover:-translate-y-1"
            >
              {/* Thumbnail */}
              <div className="relative h-28 sm:h-40 bg-gradient-to-br from-zinc-900 to-zinc-950 flex items-center justify-center border-b border-white/5 overflow-hidden">
                <div className="absolute inset-0 flex items-center justify-center text-5xl sm:text-8xl opacity-5 blur-sm">
                  {product.icon}
                </div>
                <div className="relative text-3xl sm:text-5xl">{product.icon}</div>
              </div>

              {/* Content */}
              <div className="p-3 sm:p-6 space-y-2.5 sm:space-y-4">
                {/* Badges */}
                <div className="flex flex-wrap gap-1.5 sm:gap-2">
                  {product.badges.map((badge, i) => (
                    <span
                      key={i}
                      className={`px-2 sm:px-3 py-0.5 sm:py-1 border rounded-md sm:rounded-lg text-[9px] sm:text-xs font-semibold uppercase tracking-wider ${
                        badgeStyles[product.badgeColors[i] as keyof typeof badgeStyles]
                      }`}
                    >
                      {badge}
                    </span>
                  ))}
                </div>

                {/* Title & Description */}
                <div className="space-y-1 sm:space-y-2">
                  <h3 className="text-xs sm:text-lg font-bold text-white leading-tight line-clamp-2" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                    {product.title}
                  </h3>
                  <p className="text-[10px] sm:text-sm text-zinc-400 leading-relaxed line-clamp-2 hidden sm:block">
                    {product.description}
                  </p>
                </div>

                {/* Footer */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-0 pt-1 sm:pt-2">
                  <div className="space-y-0.5 sm:space-y-1">
                    {product.oldPrice && (
                      <div className="text-[9px] sm:text-xs text-zinc-600 line-through">{product.oldPrice}</div>
                    )}
                    <div className="flex items-baseline gap-0.5 sm:gap-1">
                      <span className={`text-sm sm:text-xl font-bold ${product.price === 'Gratis' ? 'text-blue-400' : 'text-yellow-400'}`} style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                        {product.price}
                      </span>
                      {product.period && (
                        <span className="text-[9px] sm:text-xs text-zinc-500 hidden sm:inline">{product.period}</span>
                      )}
                    </div>
                  </div>

                  <button
                    className={`group/btn w-full sm:w-auto inline-flex items-center justify-center gap-0.5 sm:gap-1 px-2.5 sm:px-4 py-1.5 sm:py-2.5 rounded-lg text-[10px] sm:text-sm font-semibold transition-all ${
                      product.ctaStyle === 'primary'
                        ? 'bg-yellow-400 hover:bg-yellow-300 text-black'
                        : 'bg-white/5 hover:bg-white/10 border border-yellow-400/20 hover:border-yellow-400/40 text-yellow-400'
                    }`}
                  >
                    <span className="hidden sm:inline">{product.cta}</span>
                    <span className="sm:hidden">Beli</span>
                    <ArrowRight className="w-3 h-3 sm:w-4 sm:h-4 group-hover/btn:translate-x-1 transition-transform" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
