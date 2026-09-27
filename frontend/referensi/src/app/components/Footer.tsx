export function Footer() {
  return (
    <footer className="bg-zinc-900/50 border-t border-white/5 pb-20 md:pb-0">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16">
        {/* Main Grid */}
        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-12 mb-12">
          {/* Brand Column */}
          <div className="lg:col-span-1 space-y-6">
            <div>
              <span className="text-xl font-bold tracking-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Hell<span className="text-yellow-400">OM</span>
              </span>
            </div>
            <p className="text-sm text-zinc-400 leading-relaxed max-w-xs">
              Platform digital untuk UMKM Indonesia. Dari kasir, landing page, sampai toko online — semua dalam satu ekosistem yang terhubung.
            </p>
            {/* Social Links */}
            <div className="flex gap-3">
              <a href="#" className="w-10 h-10 bg-zinc-800 hover:bg-zinc-700 border border-white/5 hover:border-yellow-400/30 rounded-lg flex items-center justify-center text-sm transition-all">
                𝕏
              </a>
              <a href="#" className="w-10 h-10 bg-zinc-800 hover:bg-zinc-700 border border-white/5 hover:border-yellow-400/30 rounded-lg flex items-center justify-center text-sm transition-all">
                in
              </a>
              <a href="#" className="w-10 h-10 bg-zinc-800 hover:bg-zinc-700 border border-white/5 hover:border-yellow-400/30 rounded-lg flex items-center justify-center text-sm transition-all">
                ig
              </a>
              <a href="#" className="w-10 h-10 bg-zinc-800 hover:bg-zinc-700 border border-white/5 hover:border-yellow-400/30 rounded-lg flex items-center justify-center text-sm transition-all">
                ▶
              </a>
            </div>
          </div>

          {/* Produk */}
          <div>
            <h4 className="text-sm font-bold text-white mb-5 tracking-wide uppercase" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
              Produk
            </h4>
            <ul className="space-y-3">
              {['POS / Kasir Digital', 'Landing Page Builder', 'Aplikasi Member', 'Template & UI Kit', 'Source Code', 'Kursus Online'].map((item) => (
                <li key={item}>
                  <a href="#" className="text-sm text-zinc-400 hover:text-yellow-400 transition-colors">
                    {item}
                  </a>
                </li>
              ))}
            </ul>
          </div>

          {/* Akun */}
          <div>
            <h4 className="text-sm font-bold text-white mb-5 tracking-wide uppercase" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
              Akun
            </h4>
            <ul className="space-y-3">
              {['Masuk', 'Daftar Gratis', 'Dashboard', 'Harga', 'FAQ'].map((item) => (
                <li key={item}>
                  <a href="#" className="text-sm text-zinc-400 hover:text-yellow-400 transition-colors">
                    {item}
                  </a>
                </li>
              ))}
            </ul>
          </div>

          {/* Perusahaan */}
          <div>
            <h4 className="text-sm font-bold text-white mb-5 tracking-wide uppercase" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
              Perusahaan
            </h4>
            <ul className="space-y-3">
              {['Tentang Hellom', 'Artikel & Blog', 'Kontak', 'Syarat & Ketentuan', 'Kebijakan Privasi', 'Kebijakan Refund'].map((item) => (
                <li key={item}>
                  <a href="#" className="text-sm text-zinc-400 hover:text-yellow-400 transition-colors">
                    {item}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        </div>

        {/* Bottom */}
        <div className="pt-8 border-t border-white/5">
          <div className="flex flex-col md:flex-row items-center justify-between gap-4">
            <p className="text-xs text-zinc-500">
              © 2026 Hellom. Digital Agency. Semua hak dilindungi.
            </p>
            <div className="flex gap-3">
              {['Bayar via QRIS', 'Transfer Bank', 'SSL Aman'].map((tag) => (
                <span
                  key={tag}
                  className="px-3 py-1 bg-zinc-800 border border-white/5 rounded-lg text-xs text-zinc-500"
                >
                  {tag}
                </span>
              ))}
            </div>
          </div>
        </div>
      </div>
    </footer>
  );
}
