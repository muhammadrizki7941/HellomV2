import { ArrowRight } from 'lucide-react';

export function Articles() {
  const articles = [
    {
      icon: '📊',
      gradient: 'from-yellow-500/10 to-transparent',
      category: 'Bisnis & UMKM',
      title: '5 Kesalahan Paling Umum UMKM Saat Memilih Software Kasir',
      excerpt: 'Banyak bisnis gagal di sistem bukan karena produknya jelek — tapi karena pilihan software yang tidak sesuai skala dan kebutuhan operasional.',
      date: '8 Mei 2026',
    },
    {
      icon: '📱',
      gradient: 'from-blue-500/10 to-transparent',
      category: 'Digital Marketing',
      title: 'Cara Buat Landing Page yang Beneran Convert — Bukan Sekadar Cantik',
      excerpt: 'Landing page yang bagus bukan yang paling ramai animasinya. Ini 7 elemen wajib yang harus ada supaya pengunjung mau ambil tindakan.',
      date: '5 Mei 2026',
    },
    {
      icon: '🤝',
      gradient: 'from-emerald-500/10 to-transparent',
      category: 'Strategi Pertumbuhan',
      title: 'Sistem Member Loyalitas: Cara Murah Buat Pelanggan Balik Lagi',
      excerpt: 'Mendapatkan pelanggan baru 5x lebih mahal dari menjaga yang lama. Ini cara praktis membangun sistem loyalitas dari nol tanpa budget besar.',
      date: '2 Mei 2026',
    },
  ];

  return (
    <section id="artikel" className="relative py-12 sm:py-24 bg-black">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Section Header */}
        <div className="text-center max-w-3xl mx-auto mb-8 sm:mb-16">
          <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/20 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-3 sm:mb-4">
            Artikel & Insight
          </div>
          <h2 className="text-2xl sm:text-4xl lg:text-5xl font-bold text-white mb-4 sm:mb-6 tracking-tight px-4" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Bukan sekadar jual.{' '}
            <span className="text-yellow-400 italic">Lo juga kami edukasi.</span>
          </h2>
          <p className="text-sm sm:text-lg text-zinc-400 leading-relaxed px-4">
            Panduan praktis buat UMKM — dari cara setting kasir, strategi marketing digital, sampai tips kelola bisnis yang lebih efisien.
          </p>
        </div>

        {/* Articles Grid - 2 kolom di mobile */}
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6">
          {articles.map((article, index) => (
            <article
              key={index}
              className="group bg-zinc-900/50 border border-white/5 hover:border-yellow-400/20 rounded-xl sm:rounded-2xl overflow-hidden transition-all duration-300 hover:-translate-y-1 cursor-pointer"
            >
              {/* Thumbnail */}
              <div className="relative h-32 sm:h-48 bg-gradient-to-br from-zinc-900 to-zinc-950 flex items-center justify-center border-b border-white/5 overflow-hidden">
                <div className={`absolute inset-0 bg-gradient-to-br ${article.gradient}`} />
                <div className="relative text-4xl sm:text-6xl">{article.icon}</div>
              </div>

              {/* Content */}
              <div className="p-3 sm:p-6 space-y-2 sm:space-y-4">
                <div className="text-[9px] sm:text-xs font-bold text-yellow-400 uppercase tracking-wider">
                  {article.category}
                </div>

                <h3 className="text-xs sm:text-lg font-bold text-white leading-tight group-hover:text-yellow-400 transition-colors line-clamp-2 sm:line-clamp-none" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                  {article.title}
                </h3>

                <p className="text-[10px] sm:text-sm text-zinc-400 leading-relaxed line-clamp-2 hidden sm:block">
                  {article.excerpt}
                </p>

                <div className="flex items-center justify-between pt-1 sm:pt-2">
                  <span className="text-[9px] sm:text-xs text-zinc-600">{article.date}</span>
                  <span className="inline-flex items-center gap-0.5 sm:gap-1 text-[10px] sm:text-sm font-semibold text-yellow-400 group-hover:gap-2 transition-all">
                    <span className="hidden sm:inline">Baca Artikel</span>
                    <span className="sm:hidden">Baca</span>
                    <ArrowRight className="w-3 h-3 sm:w-4 sm:h-4" />
                  </span>
                </div>
              </div>
            </article>
          ))}
        </div>

        {/* View All Button */}
        <div className="text-center mt-8 sm:mt-12">
          <button className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 sm:px-8 py-3 sm:py-4 bg-white/5 hover:bg-white/10 text-white text-sm sm:text-base font-semibold rounded-xl border border-white/10 hover:border-white/20 transition-all">
            Lihat Semua Artikel
            <ArrowRight className="w-4 h-4 sm:w-5 sm:h-5" />
          </button>
        </div>
      </div>
    </section>
  );
}
