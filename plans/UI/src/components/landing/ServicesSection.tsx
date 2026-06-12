import { SERVICES_DATA } from '../../lib/landingData';

export const ServicesSection = () => {
  return (
    <section className="py-12 sm:py-24" id="services">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-8 sm:mb-14">
          <div className="mb-3 inline-flex rounded-lg border border-yellow-400/20 bg-yellow-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-yellow-400 sm:mb-4">
            Kategori Penjualan
          </div>
          <h2 className="text-2xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
            Apapun kebutuhan digitalmu, ada di sini.
          </h2>
          <p className="mt-4 max-w-2xl text-sm leading-relaxed text-zinc-400 sm:text-lg">
            Dari kasir bisnis sampai kursus online, pilih produk, pilih harga, langsung pakai.
          </p>
        </div>

        <div className="grid grid-cols-2 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
          {SERVICES_DATA.map((service) => (
            <div
              key={service.code}
              className="group relative overflow-hidden rounded-xl border border-white/5 bg-zinc-900 p-5 transition-all duration-300 hover:-translate-y-1 hover:border-yellow-400/20 sm:rounded-2xl sm:p-7"
            >
              <div className="absolute inset-0 bg-gradient-to-br from-yellow-400/[0.03] to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100" />
              <div className="relative">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-xl border border-yellow-400/15 bg-yellow-400/10 text-lg text-yellow-400">
                  {service.code}
                </div>
                <h3 className="text-sm font-bold text-white sm:text-lg" style={{ fontFamily: 'Space Grotesk, sans-serif' }}>
                  {service.name}
                </h3>
                <p className="mt-2 text-[11px] leading-relaxed text-zinc-400 sm:text-sm">{service.desc}</p>
                <div className="mt-4 text-xs font-semibold text-yellow-400 sm:text-sm">
                  {service.code === 'CS' ? 'Konsultasi via WhatsApp -&gt;' : 'Lihat detail -&gt;'}
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};
