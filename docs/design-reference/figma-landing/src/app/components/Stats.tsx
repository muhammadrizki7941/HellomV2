export function Stats() {
  const stats = [
    {
      number: '100+',
      label: 'Bisnis & restoran\nsudah aktif pakai',
    },
    {
      number: '50+',
      label: 'Organisasi &\nkomunitas terdaftar',
    },
    {
      number: '99%',
      label: 'Tingkat kepuasan\npelanggan aktif',
    },
    {
      number: '5',
      suffix: 'mnt',
      label: 'Rata-rata waktu\nsetup hingga aktif',
    },
  ];

  return (
    <section className="relative py-12 sm:py-20 bg-black">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-px bg-white/5 rounded-xl sm:rounded-2xl overflow-hidden border border-white/5">
          {stats.map((stat, index) => (
            <div
              key={index}
              className="bg-black p-5 sm:p-8 lg:p-10 hover:bg-zinc-900/50 transition-colors duration-300"
            >
              <div className="space-y-2 sm:space-y-3">
                <div
                  className="text-3xl sm:text-4xl lg:text-5xl font-bold text-yellow-400 tracking-tight"
                  style={{ fontFamily: 'Space Grotesk, sans-serif' }}
                >
                  {stat.number}
                  {stat.suffix && (
                    <span className="text-xl sm:text-2xl ml-1">{stat.suffix}</span>
                  )}
                </div>
                <p className="text-xs sm:text-sm text-zinc-500 leading-relaxed whitespace-pre-line">
                  {stat.label}
                </p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
