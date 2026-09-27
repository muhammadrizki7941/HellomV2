import { Menu, X } from 'lucide-react';
import { useState } from 'react';

export function Navbar() {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <>
      {/* Top Announcement Bar */}
      <div className="bg-gradient-to-r from-yellow-400 to-amber-500 text-black">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5">
          <p className="text-center text-sm font-medium tracking-tight">
            🎉 Landing Builder sekarang gratis selamanya —{' '}
            <a href="#" className="underline underline-offset-2 font-semibold hover:no-underline">
              Mulai Gratis →
            </a>
          </p>
        </div>
      </div>

      {/* Main Navbar */}
      <nav className="sticky top-0 z-50 bg-black/80 backdrop-blur-xl border-b border-white/5">
        <div className="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-14 md:h-16">
            {/* Logo */}
            <a href="#" className="flex items-center space-x-2">
              <span className="text-lg md:text-xl font-bold tracking-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Hell<span className="text-yellow-400">OM</span>
              </span>
            </a>

            {/* Desktop Navigation */}
            <div className="hidden md:flex items-center space-x-1">
              <a href="#produk" className="px-4 py-2 text-sm font-medium text-zinc-400 hover:text-white transition-colors rounded-lg hover:bg-white/5">
                Produk
              </a>
              <a href="#harga" className="px-4 py-2 text-sm font-medium text-zinc-400 hover:text-white transition-colors rounded-lg hover:bg-white/5">
                Harga
              </a>
              <a href="#artikel" className="px-4 py-2 text-sm font-medium text-zinc-400 hover:text-white transition-colors rounded-lg hover:bg-white/5">
                Artikel
              </a>
              <a href="#" className="px-4 py-2 text-sm font-medium text-zinc-400 hover:text-white transition-colors rounded-lg hover:bg-white/5">
                Tentang
              </a>
            </div>

            {/* Desktop CTA */}
            <div className="hidden md:flex items-center space-x-3">
              <button className="px-4 py-2 text-sm font-semibold text-zinc-300 hover:text-white transition-colors">
                Masuk
              </button>
              <button className="px-5 py-2.5 bg-yellow-400 hover:bg-yellow-300 text-black text-sm font-semibold rounded-lg transition-all hover:scale-105 active:scale-95">
                Daftar Gratis
              </button>
            </div>

            {/* Mobile CTA - Simple */}
            <div className="md:hidden">
              <button className="px-4 py-2 bg-yellow-400 hover:bg-yellow-300 text-black text-xs font-bold rounded-lg transition-all active:scale-95">
                Daftar
              </button>
            </div>
          </div>
        </div>

        {/* Mobile Menu */}
        {isOpen && (
          <div className="md:hidden border-t border-white/5 bg-black/95 backdrop-blur-xl">
            <div className="px-4 py-4 space-y-2">
              <a href="#produk" className="block px-4 py-3 text-sm font-medium text-zinc-400 hover:text-white hover:bg-white/5 rounded-lg transition-colors">
                Produk
              </a>
              <a href="#harga" className="block px-4 py-3 text-sm font-medium text-zinc-400 hover:text-white hover:bg-white/5 rounded-lg transition-colors">
                Harga
              </a>
              <a href="#artikel" className="block px-4 py-3 text-sm font-medium text-zinc-400 hover:text-white hover:bg-white/5 rounded-lg transition-colors">
                Artikel
              </a>
              <a href="#" className="block px-4 py-3 text-sm font-medium text-zinc-400 hover:text-white hover:bg-white/5 rounded-lg transition-colors">
                Tentang
              </a>
              <div className="pt-4 space-y-2 border-t border-white/5">
                <button className="w-full px-4 py-3 text-sm font-semibold text-zinc-300 hover:text-white hover:bg-white/5 rounded-lg transition-colors">
                  Masuk
                </button>
                <button className="w-full px-4 py-3 bg-yellow-400 hover:bg-yellow-300 text-black text-sm font-semibold rounded-lg transition-colors">
                  Daftar Gratis
                </button>
              </div>
            </div>
          </div>
        )}
      </nav>
    </>
  );
}
