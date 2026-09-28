import { Star } from 'lucide-react';
import { TESTIMONIALS_DATA } from '../../lib/landingData';

export const TestimonialsSection = () => {
  return (
    <section className="relative border-y border-white/5 bg-zinc-900/30 py-12 sm:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto mb-8 max-w-3xl text-center sm:mb-16">
          <div className="mb-3 inline-flex rounded-lg border border-yellow-400/20 bg-yellow-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-yellow-400 sm:mb-4">
            Kata Mereka
          </div>
          <h2 className="px-4 text-2xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Mereka sudah coba. <span className="text-yellow-400 italic">Ini yang mereka bilang.</span>
          </h2>
          <p className="mt-4 px-4 text-sm leading-relaxed text-zinc-400 sm:text-lg">
            Tiga orang, tiga kebutuhan berbeda. Tapi semuanya butuh sistem yang kelihatan proper saat dijual.
          </p>
        </div>

        <div className="-mx-4 grid snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-4 md:mx-0 md:grid-cols-3 md:overflow-visible md:px-0 md:pb-0">
          {TESTIMONIALS_DATA.map((testimonial, index) => (
            <article
              key={testimonial.name}
              className={`min-w-[280px] snap-center rounded-xl border bg-black p-5 transition-all duration-300 hover:-translate-y-1 sm:rounded-2xl sm:p-8 ${
                index === 1 ? 'border-yellow-400/30 shadow-lg shadow-yellow-400/5' : 'border-white/5 hover:border-white/10'
              }`}
            >
              <div className="space-y-4 sm:space-y-6">
                <div className="flex gap-1">
                  {[...Array(testimonial.rating)].map((_, starIndex) => (
                    <Star key={starIndex} className="h-3.5 w-3.5 fill-yellow-400 text-yellow-400 sm:h-4 sm:w-4" />
                  ))}
                </div>

                <p className="text-xs italic leading-relaxed text-zinc-300 sm:text-sm">
                  "{testimonial.quote}"
                </p>

                <div className="flex items-center gap-3 border-t border-white/5 pt-3 sm:gap-4 sm:pt-4">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-yellow-400/20 bg-yellow-400/10 sm:h-12 sm:w-12">
                    <span className="text-xs font-bold text-yellow-400 sm:text-sm" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                      {testimonial.initials}
                    </span>
                  </div>
                  <div>
                    <div className="text-xs font-semibold text-white sm:text-sm">{testimonial.name}</div>
                    <div className="text-[10px] text-zinc-500 sm:text-xs">{testimonial.role}</div>
                  </div>
                </div>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
};
