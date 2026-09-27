import { DollarSign, Home, Package, User } from 'lucide-react';
import { useState } from 'react';

const navItems = [
  { id: 'home', icon: Home, label: 'Home', href: '#top' },
  { id: 'produk', icon: Package, label: 'Produk', href: '#produk' },
  { id: 'harga', icon: DollarSign, label: 'Harga', href: '#harga' },
  { id: 'akun', icon: User, label: 'Akun', href: '/login' },
] as const;

export const BottomNav = () => {
  const [activeTab, setActiveTab] = useState<(typeof navItems)[number]['id']>('home');

  return (
    <nav className="fixed bottom-0 left-0 right-0 z-50 md:hidden" aria-label="Mobile navigation">
      <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black via-black/95 to-transparent backdrop-blur-xl" />

      <div className="relative px-4 pb-[calc(env(safe-area-inset-bottom,0px)+16px)] pt-2">
        <div className="rounded-3xl border border-white/10 bg-zinc-900/90 shadow-2xl shadow-black/50 backdrop-blur-2xl">
          <div className="grid grid-cols-4 gap-1 p-2">
            {navItems.map((item) => {
              const Icon = item.icon;
              const isActive = activeTab === item.id;

              return (
                <a
                  key={item.id}
                  href={item.href}
                  onClick={() => setActiveTab(item.id)}
                  className={`flex flex-col items-center justify-center gap-1 rounded-2xl px-2 py-3 transition-all duration-300 ${
                    isActive
                      ? 'scale-105 bg-yellow-400 text-black'
                      : 'text-zinc-400 hover:bg-white/5 hover:text-white active:scale-95'
                  }`}
                >
                  <Icon className={`h-5 w-5 transition-transform ${isActive ? 'scale-110' : ''}`} />
                  <span className={`text-[10px] font-semibold tracking-tight ${isActive ? 'font-bold' : ''}`}>{item.label}</span>
                </a>
              );
            })}
          </div>
        </div>
      </div>
    </nav>
  );
};
