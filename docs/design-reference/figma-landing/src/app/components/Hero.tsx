import { ArrowRight, CheckCircle2, Zap } from 'lucide-react';

export function Hero() {
  return (
    <section className="relative overflow-hidden bg-black pt-12 pb-16 sm:pt-28 sm:pb-32">
      {/* Gradient Orbs */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-yellow-400/10 rounded-full blur-3xl" />
        <div className="absolute top-40 right-0 w-[400px] h-[400px] bg-amber-500/5 rounded-full blur-3xl" />
      </div>

      <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          {/* Left Content */}
          <div className="space-y-8">
            {/* Badge */}
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-yellow-400/10 border border-yellow-400/20 backdrop-blur-sm">
              <span className="relative flex h-2 w-2">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2 w-2 bg-yellow-400"></span>
              </span>
              <span className="text-xs font-semibold text-yellow-400 tracking-wider uppercase">
                Platform UMKM Indonesia
              </span>
            </div>

            {/* Headline */}
            <div className="space-y-4 sm:space-y-6">
              <h1 className="text-3xl sm:text-6xl lg:text-7xl font-bold tracking-tight text-white leading-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Satu platform.{' '}
                <span className="block text-yellow-400 italic">Semua yang bisnis</span>
                <span className="block">lo butuhkan.</span>
              </h1>

              <p className="text-base sm:text-lg lg:text-xl text-zinc-400 leading-relaxed max-w-xl">
                Dari kasir digital, landing page, sampai toko online —{' '}
                <span className="text-white font-medium">Hellom hadir buat UMKM yang mau tampil serius</span>{' '}
                tanpa ribet setup, tanpa bayar mahal di awal.
              </p>
            </div>

            {/* CTA Buttons */}
            <div className="flex flex-col sm:flex-row gap-3 sm:gap-4">
              <button className="group inline-flex items-center justify-center gap-2 px-6 py-3.5 sm:px-8 sm:py-4 bg-yellow-400 hover:bg-yellow-300 text-black text-sm sm:text-base font-semibold rounded-xl transition-all hover:scale-105 active:scale-95 shadow-lg shadow-yellow-400/20">
                Mulai Gratis Sekarang
                <ArrowRight className="w-4 h-4 sm:w-5 sm:h-5 group-hover:translate-x-1 transition-transform" />
              </button>
              <button className="inline-flex items-center justify-center gap-2 px-6 py-3.5 sm:px-8 sm:py-4 bg-white/5 hover:bg-white/10 text-white text-sm sm:text-base font-semibold rounded-xl border border-white/10 hover:border-white/20 transition-all">
                Lihat Semua Produk
              </button>
            </div>

            {/* Stats */}
            <div className="grid grid-cols-3 gap-3 sm:gap-6 pt-6 sm:pt-8">
              <div className="space-y-1">
                <div className="text-2xl sm:text-3xl font-bold text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>100+</div>
                <div className="text-xs sm:text-sm text-zinc-500">bisnis aktif</div>
              </div>
              <div className="space-y-1">
                <div className="text-2xl sm:text-3xl font-bold text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>5</div>
                <div className="text-xs sm:text-sm text-zinc-500">menit setup</div>
              </div>
              <div className="space-y-1">
                <div className="text-2xl sm:text-3xl font-bold text-white" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>99%</div>
                <div className="text-xs sm:text-sm text-zinc-500">kepuasan klien</div>
              </div>
            </div>
          </div>

          {/* Right Visual - Floating Cards */}
          <div className="relative hidden lg:block h-[500px]">
            {/* Card 1 - Background */}
            <div className="absolute top-0 right-48 w-64 p-5 bg-zinc-900/80 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl transform rotate-3 hover:rotate-0 transition-transform duration-300">
              <div className="inline-flex px-3 py-1 bg-blue-500/10 border border-blue-500/20 rounded-lg text-xs font-semibold text-blue-400 uppercase tracking-wider mb-3">
                Gratis
              </div>
              <h3 className="text-base font-bold text-white mb-2" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Landing Builder</h3>
              <div className="text-2xl font-bold text-white mb-3" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Rp 0 <span className="text-sm font-normal text-zinc-500">/ selamanya</span>
              </div>
              <div className="flex gap-2">
                <div className="w-9 h-9 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">🎨</div>
                <div className="w-9 h-9 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">📱</div>
                <div className="w-9 h-9 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">⚡</div>
              </div>
            </div>

            {/* Card 2 - Background Bottom */}
            <div className="absolute top-48 right-52 w-64 p-5 bg-zinc-900/60 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl transform -rotate-2 hover:rotate-0 transition-transform duration-300">
              <div className="inline-flex px-3 py-1 bg-emerald-500/10 border border-emerald-500/20 rounded-lg text-xs font-semibold text-emerald-400 uppercase tracking-wider mb-3">
                Sekali Beli
              </div>
              <h3 className="text-base font-bold text-white mb-2" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>Template Pro Pack</h3>
              <div className="text-2xl font-bold text-white mb-3" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Rp 79rb <span className="text-sm font-normal text-zinc-500">/ selamanya</span>
              </div>
              <div className="h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                <div className="h-full w-[72%] bg-yellow-400 rounded-full" />
              </div>
            </div>

            {/* Card 3 - Main Featured */}
            <div className="absolute top-8 right-0 w-80 p-6 bg-gradient-to-br from-zinc-900 to-black backdrop-blur-xl border border-yellow-400/30 rounded-2xl shadow-2xl shadow-yellow-400/10 hover:scale-105 transition-transform duration-300">
              <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/30 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-4">
                Berlangganan
              </div>
              <h3 className="text-lg font-bold text-white mb-2" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>POS / Kasir Digital</h3>
              <div className="text-3xl font-bold text-yellow-400 mb-4" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Rp 99rb <span className="text-sm font-normal text-zinc-500">/ bulan</span>
              </div>
              <div className="flex gap-2 mb-4">
                <div className="w-10 h-10 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">🧾</div>
                <div className="w-10 h-10 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">📊</div>
                <div className="w-10 h-10 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">💳</div>
                <div className="w-10 h-10 bg-zinc-800 rounded-lg flex items-center justify-center text-lg">🔔</div>
              </div>
              <div className="h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                <div className="h-full w-[85%] bg-yellow-400 rounded-full" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
