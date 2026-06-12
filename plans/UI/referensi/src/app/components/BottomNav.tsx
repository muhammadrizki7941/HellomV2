import { Home, Package, FileText, User } from 'lucide-react';
import { useState } from 'react';

export function BottomNav() {
  const [activeTab, setActiveTab] = useState('home');

  const navItems = [
    { id: 'home', icon: Home, label: 'Home', href: '#' },
    { id: 'produk', icon: Package, label: 'Produk', href: '#produk' },
    { id: 'artikel', icon: FileText, label: 'Artikel', href: '#artikel' },
    { id: 'akun', icon: User, label: 'Akun', href: '#' },
  ];

  return (
    <nav className="md:hidden fixed bottom-0 left-0 right-0 z-50 pb-safe">
      {/* Background Blur */}
      <div className="absolute inset-0 bg-gradient-to-t from-black via-black/95 to-transparent backdrop-blur-xl" />

      {/* Nav Container */}
      <div className="relative px-4 pb-4 pt-2">
        <div className="bg-zinc-900/90 backdrop-blur-2xl border border-white/10 rounded-3xl shadow-2xl shadow-black/50">
          <div className="grid grid-cols-4 gap-1 p-2">
            {navItems.map((item) => {
              const Icon = item.icon;
              const isActive = activeTab === item.id;

              return (
                <a
                  key={item.id}
                  href={item.href}
                  onClick={() => setActiveTab(item.id)}
                  className={`flex flex-col items-center justify-center gap-1 py-3 px-2 rounded-2xl transition-all duration-300 ${
                    isActive
                      ? 'bg-yellow-400 text-black scale-105'
                      : 'text-zinc-400 hover:text-white hover:bg-white/5 active:scale-95'
                  }`}
                >
                  <Icon className={`w-5 h-5 transition-transform ${isActive ? 'scale-110' : ''}`} />
                  <span className={`text-[10px] font-semibold tracking-tight ${isActive ? 'font-bold' : ''}`}>
                    {item.label}
                  </span>
                </a>
              );
            })}
          </div>
        </div>
      </div>
    </nav>
  );
}
