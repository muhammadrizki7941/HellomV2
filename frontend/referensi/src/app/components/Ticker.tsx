export function Ticker() {
  const items = [
    'POS Digital',
    'Landing Page',
    'Toko Online',
    'Aplikasi Member',
    'Dashboard Analytics',
    'Source Code',
    'Template UI',
    'Kursus Digital',
    'Custom Software',
    'Notifikasi Otomatis',
    'Laporan Transaksi',
    'Manajemen Stok',
  ];

  return (
    <div className="relative overflow-hidden bg-zinc-900/50 border-y border-white/5 py-4">
      <div className="flex animate-scroll whitespace-nowrap">
        {/* First set */}
        {items.map((item, i) => (
          <div key={`first-${i}`} className="flex items-center gap-3 px-6 text-sm font-medium text-zinc-400">
            <span className="w-1.5 h-1.5 rounded-full bg-yellow-400" />
            {item}
          </div>
        ))}
        {/* Duplicate for seamless loop */}
        {items.map((item, i) => (
          <div key={`second-${i}`} className="flex items-center gap-3 px-6 text-sm font-medium text-zinc-400">
            <span className="w-1.5 h-1.5 rounded-full bg-yellow-400" />
            {item}
          </div>
        ))}
      </div>

      <style>{`
        @keyframes scroll {
          0% { transform: translateX(0); }
          100% { transform: translateX(-50%); }
        }
        .animate-scroll {
          animation: scroll 30s linear infinite;
        }
        .animate-scroll:hover {
          animation-play-state: paused;
        }
      `}</style>
    </div>
  );
}
