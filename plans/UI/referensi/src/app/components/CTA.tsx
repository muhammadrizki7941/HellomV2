import { ArrowRight } from 'lucide-react';

export function CTA() {
  return (
    <section className="relative py-16 sm:py-32 bg-black overflow-hidden">
      {/* Gradient Orbs */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[400px] sm:w-[600px] h-[300px] sm:h-[400px] bg-yellow-400/10 rounded-full blur-3xl" />
      </div>

      <div className="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <div className="space-y-6 sm:space-y-8">
          {/* Headline */}
          <h2 className="text-3xl sm:text-5xl lg:text-7xl font-bold text-white tracking-tight leading-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Mulai langganan
            <br />
            dan aktifkan bisnis lo
            <br />
            <span className="text-yellow-400 italic">dalam hitungan menit.</span>
          </h2>

          {/* Description */}
          <p className="text-sm sm:text-lg text-zinc-400 leading-relaxed max-w-2xl mx-auto px-4">
            Buat akun Hellom, pilih aplikasi yang lo butuhkan, dan aktifkan. Semua langkah sudah disiapkan untuk lo — tidak perlu tim IT.
          </p>

          {/* CTA Buttons */}
          <div className="flex flex-col sm:flex-row items-center justify-center gap-3 sm:gap-4 pt-2 sm:pt-4">
            <button className="group w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 sm:px-10 py-4 sm:py-5 bg-yellow-400 hover:bg-yellow-300 text-black font-semibold rounded-xl transition-all hover:scale-105 active:scale-95 shadow-lg shadow-yellow-400/20 text-base sm:text-lg">
              Daftar & Aktifkan Sekarang
              <ArrowRight className="w-5 h-5 sm:w-6 sm:h-6 group-hover:translate-x-1 transition-transform" />
            </button>
            <button className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 sm:px-10 py-4 sm:py-5 bg-white/5 hover:bg-white/10 text-white font-semibold rounded-xl border border-white/10 hover:border-white/20 transition-all text-base sm:text-lg">
              Masuk ke Akun
            </button>
          </div>

          {/* Note */}
          <p className="text-xs sm:text-sm text-zinc-500 pt-2 sm:pt-4 px-4">
            Tidak perlu kartu kredit.{' '}
            <a href="#" className="text-yellow-400 hover:text-yellow-300 transition-colors underline underline-offset-2">
              Baca syarat & ketentuan
            </a>
          </p>
        </div>
      </div>
    </section>
  );
}
