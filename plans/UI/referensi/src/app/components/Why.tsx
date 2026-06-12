export function Why() {
  const points = [
    {
      number: '01',
      title: 'Onboarding jelas dan cepat',
      description: 'Tim aktif bantu lo dari pertama daftar sampai sistem berjalan — tidak dibiarkan baca dokumentasi sendirian.',
    },
    {
      number: '02',
      title: 'Semua modul terhubung',
      description: 'POS, member, landing page, dan billing berjalan bersama dalam satu panel terpadu yang terkoneksi.',
    },
    {
      number: '03',
      title: 'Harga yang bisa dijelaskan',
      description: 'Tidak ada angka yang tiba-tiba muncul di invoice. Platform fee 5% per transaksi — itu saja, tidak ada biaya tersembunyi.',
    },
    {
      number: '04',
      title: 'Desain yang tidak malu-maluin',
      description: 'Tampilan yang lo dan tim bangga tunjukkan ke klien. Bukan template asal jadi.',
    },
  ];

  const terminalData = [
    { key: 'transaksi_hari_ini', value: 'Rp 2.340.000', type: 'value' },
    { key: 'jumlah_pesanan', value: '47 pesanan', type: 'value' },
    { key: 'pelanggan_baru', value: '12 orang', type: 'value' },
    { key: 'member_aktif', value: '284 member', type: 'value' },
    { key: 'stok_hampir_habis', value: '3 produk', type: 'warning' },
    { key: 'notif_otomatis', value: '✓ aktif', type: 'value' },
    { key: 'laporan_mingguan', value: '✓ terkirim ke WA', type: 'value' },
  ];

  const badges = ['Real-time sync', 'Notif WhatsApp', 'Multi-cabang', 'Export PDF'];

  return (
    <section className="relative py-12 sm:py-24 bg-black">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid lg:grid-cols-2 gap-8 sm:gap-16 items-center">
          {/* Left Content */}
          <div className="space-y-6 sm:space-y-8">
            <div>
              <div className="inline-flex px-3 py-1 bg-yellow-400/10 border border-yellow-400/20 rounded-lg text-xs font-semibold text-yellow-400 uppercase tracking-wider mb-3 sm:mb-4">
                Kenapa Hellom
              </div>
              <h2 className="text-2xl sm:text-4xl lg:text-5xl font-bold text-white mb-4 sm:mb-6 tracking-tight" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                Bukan sekadar vendor.{' '}
                <span className="text-yellow-400 italic">Mitra bisnis lo.</span>
              </h2>
              <p className="text-sm sm:text-lg text-zinc-400 leading-relaxed">
                Banyak vendor jual produk lalu hilang. Hellom ada dari onboarding, bantu setup, sampai bisnis lo beneran jalan.
              </p>
            </div>

            {/* Points */}
            <div className="space-y-4 sm:space-y-6">
              {points.map((point) => (
                <div key={point.number} className="flex gap-3 sm:gap-4 group">
                  <div className="flex-shrink-0 w-10 h-10 sm:w-12 sm:h-12 bg-yellow-400/10 group-hover:bg-yellow-400/20 border border-yellow-400/20 rounded-lg sm:rounded-xl flex items-center justify-center transition-colors">
                    <span className="text-xs sm:text-sm font-bold text-yellow-400" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                      {point.number}
                    </span>
                  </div>
                  <div className="space-y-0.5 sm:space-y-1 pt-0.5 sm:pt-1">
                    <h3 className="text-sm sm:text-base font-semibold text-white">
                      {point.title}
                    </h3>
                    <p className="text-xs sm:text-sm text-zinc-400 leading-relaxed">
                      {point.description}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Right Terminal */}
          <div className="relative">
            <div className="bg-zinc-900/50 border border-white/5 rounded-xl sm:rounded-2xl overflow-hidden shadow-2xl">
              {/* Terminal Header */}
              <div className="bg-zinc-800/50 border-b border-white/5 px-3 sm:px-4 py-2 sm:py-3 flex items-center gap-2">
                <div className="flex gap-1.5 sm:gap-2">
                  <div className="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-red-500/80" />
                  <div className="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-yellow-500/80" />
                  <div className="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-full bg-green-500/80" />
                </div>
                <span className="text-[10px] sm:text-xs text-zinc-500 font-mono ml-2">hellom — dashboard bisnis</span>
              </div>

              {/* Terminal Body */}
              <div className="p-4 sm:p-6 font-mono text-xs sm:text-sm space-y-1">
                <div className="text-zinc-500 mb-3">// status bisnis lo hari ini</div>
                <div className="border-t border-white/5 my-2" />

                {terminalData.slice(0, 4).map((item, i) => (
                  <div key={i} className="flex justify-between py-1">
                    <span className="text-blue-400">{item.key}</span>
                    <span className="text-emerald-400">{item.value}</span>
                  </div>
                ))}

                <div className="border-t border-white/5 my-2" />

                {terminalData.slice(4).map((item, i) => (
                  <div key={i} className="flex justify-between py-1">
                    <span className="text-blue-400">{item.key}</span>
                    <span className={item.type === 'warning' ? 'text-yellow-400' : 'text-emerald-400'}>
                      {item.value}
                    </span>
                  </div>
                ))}

                <div className="border-t border-white/5 my-2" />
                <div className="text-zinc-500">// semua berjalan. bisnis lo aman.</div>
              </div>

              {/* Terminal Footer Badges */}
              <div className="border-t border-white/5 p-3 sm:p-4 flex flex-wrap gap-1.5 sm:gap-2">
                {badges.map((badge) => (
                  <div key={badge} className="inline-flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-3 py-1 sm:py-1.5 bg-zinc-800/50 border border-white/5 rounded-full">
                    <span className="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full bg-yellow-400" />
                    <span className="text-[10px] sm:text-xs font-medium text-zinc-300">{badge}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
