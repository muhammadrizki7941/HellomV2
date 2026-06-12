import { ArrowUpRight } from 'lucide-react';

export function Categories() {
  const categories = [
    {
      icon: '📱',
      title: 'Aplikasi SaaS',
      description: 'POS, member app, dashboard analytics — tinggal aktifkan, langsung kerja.',
      count: '3 aplikasi tersedia',
    },
    {
      icon: '🎨',
      title: 'Template & UI Kit',
      description: 'Desain siap pakai untuk landing page, toko online, dan profil bisnis.',
      count: '12 template tersedia',
    },
    {
      icon: '💻',
      title: 'Source Code',
      description: 'Kode siap pakai Laravel & React — tinggal install, modifikasi, deploy.',
      count: '8 source code tersedia',
    },
    {
      icon: '🎓',
      title: 'Kursus Online',
      description: 'Pelajari digital marketing, web dev, dan growth bisnis dari praktisi.',
      count: '5 kursus tersedia',
    },
  ];

  return (
    <section id="produk" className="relative py-12 sm:py-24 bg-black">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Section Header */}
        <div className="text-center max-w-3xl mx-auto mb-8 sm:mb-16">
          <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/20 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-3 sm:mb-4">
            Kategori Produk
          </div>
          <h2
            className="text-2xl sm:text-4xl lg:text-5xl font-bold text-white mb-4 sm:mb-6 tracking-tight px-4"
            style={{ fontFamily: 'Space Grotesk, sans-serif' }}
          >
            Pilih yang bisnis lo{' '}
            <span className="text-yellow-400 italic">betul-betul butuhkan</span>
          </h2>
          <p className="text-sm sm:text-lg text-zinc-400 leading-relaxed px-4">
            Ada yang gratis selamanya, ada yang sekali beli, ada yang berlangganan. Semua harga transparan. Tidak ada biaya tersembunyi.
          </p>
        </div>

        {/* Category Grid - 2 kolom di mobile */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6">
          {categories.map((category, index) => (
            <div
              key={index}
              className="group relative p-4 sm:p-6 bg-zinc-900/50 hover:bg-zinc-900 border border-white/5 hover:border-yellow-400/30 rounded-xl sm:rounded-2xl transition-all duration-300 hover:-translate-y-1 cursor-pointer overflow-hidden"
            >
              {/* Hover Gradient Effect */}
              <div className="absolute inset-0 bg-gradient-to-br from-yellow-400/0 to-yellow-400/0 group-hover:from-yellow-400/5 group-hover:to-transparent transition-all duration-300 pointer-events-none" />

              <div className="relative space-y-3 sm:space-y-4">
                {/* Icon */}
                <div className="w-11 h-11 sm:w-14 sm:h-14 bg-yellow-400/10 group-hover:bg-yellow-400/20 border border-yellow-400/20 rounded-lg sm:rounded-xl flex items-center justify-center text-2xl sm:text-3xl transition-colors duration-300">
                  {category.icon}
                </div>

                {/* Content */}
                <div className="space-y-1.5 sm:space-y-2">
                  <h3 className="text-sm sm:text-lg font-bold text-white leading-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                    {category.title}
                  </h3>
                  <p className="text-xs sm:text-sm text-zinc-400 leading-relaxed line-clamp-2 sm:line-clamp-none">
                    {category.description}
                  </p>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between pt-1 sm:pt-2">
                  <span className="text-[10px] sm:text-xs font-semibold text-yellow-400">
                    {category.count}
                  </span>
                  <ArrowUpRight className="w-4 h-4 sm:w-5 sm:h-5 text-zinc-600 group-hover:text-yellow-400 group-hover:translate-x-1 group-hover:-translate-y-1 transition-all duration-300" />
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
