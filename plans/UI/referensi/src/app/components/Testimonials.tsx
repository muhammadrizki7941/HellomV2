import { Star } from 'lucide-react';

export function Testimonials() {
  const testimonials = [
    {
      stars: 5,
      quote: 'Sebelum pakai Hellom, kami catat semua manual di buku. Sekarang laporan harian langsung masuk WA owner — saya bisa pantau dari mana saja.',
      avatar: 'RS',
      name: 'Rina Sari',
      role: 'Founder Toko Online, Jakarta',
      featured: false,
    },
    {
      stars: 5,
      quote: 'Kami punya 3 cabang restoran. Dulu ribet banget rekap per cabang. Sekarang semua terintegrasi, satu dashboard buat semua.',
      avatar: 'AF',
      name: 'Ahmad Fauzi',
      role: 'Owner Restoran, Padang',
      featured: true,
    },
    {
      stars: 5,
      quote: 'Proses onboarding-nya benar-benar simpel. Tim Hellom responsif dan proses setup beres dalam satu hari. Tidak disuruh baca manual panjang.',
      avatar: 'DP',
      name: 'Dian Putri',
      role: 'CEO Startup, Bandung',
      featured: false,
    },
  ];

  return (
    <section className="relative py-12 sm:py-24 bg-zinc-900/30 border-y border-white/5">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Section Header */}
        <div className="text-center max-w-3xl mx-auto mb-8 sm:mb-16">
          <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/20 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-3 sm:mb-4">
            Kata Mereka
          </div>
          <h2 className="text-2xl sm:text-4xl lg:text-5xl font-bold text-white mb-4 sm:mb-6 tracking-tight px-4" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Gue nggak perlu lo percaya{' '}
            <span className="text-yellow-400 italic">sebelum lo baca ini dulu.</span>
          </h2>
          <p className="text-sm sm:text-lg text-zinc-400 leading-relaxed px-4">
            Tiga orang, tiga kebutuhan berbeda. Tapi ada satu hal yang sama — mereka semua mulai dari nggak yakin.
          </p>
        </div>

        {/* Testimonials Grid - Scroll horizontal di mobile */}
        <div className="grid md:grid-cols-3 gap-4 sm:gap-6 overflow-x-auto pb-4 md:pb-0 snap-x snap-mandatory md:snap-none -mx-4 px-4 md:mx-0 md:px-0">
          {testimonials.map((testimonial, index) => (
            <div
              key={index}
              className={`bg-black border rounded-xl sm:rounded-2xl p-5 sm:p-8 transition-all duration-300 hover:-translate-y-1 min-w-[280px] md:min-w-0 snap-center ${
                testimonial.featured
                  ? 'border-yellow-400/30 shadow-lg shadow-yellow-400/5'
                  : 'border-white/5 hover:border-white/10'
              }`}
            >
              <div className="space-y-4 sm:space-y-6">
                {/* Stars */}
                <div className="flex gap-0.5 sm:gap-1">
                  {[...Array(testimonial.stars)].map((_, i) => (
                    <Star key={i} className="w-3.5 h-3.5 sm:w-4 sm:h-4 fill-yellow-400 text-yellow-400" />
                  ))}
                </div>

                {/* Quote */}
                <p className="text-xs sm:text-sm text-zinc-300 leading-relaxed italic">
                  "{testimonial.quote}"
                </p>

                {/* Author */}
                <div className="flex items-center gap-3 sm:gap-4 pt-3 sm:pt-4 border-t border-white/5">
                  <div className="w-10 h-10 sm:w-12 sm:h-12 bg-yellow-400/10 border border-yellow-400/20 rounded-full flex items-center justify-center flex-shrink-0">
                    <span className="text-xs sm:text-sm font-bold text-yellow-400" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                      {testimonial.avatar}
                    </span>
                  </div>
                  <div>
                    <div className="text-xs sm:text-sm font-semibold text-white">
                      {testimonial.name}
                    </div>
                    <div className="text-[10px] sm:text-xs text-zinc-500">
                      {testimonial.role}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
