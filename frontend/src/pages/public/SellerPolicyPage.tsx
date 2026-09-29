import { useEffect } from 'react';
import { Link, NavLink, useParams } from 'react-router-dom';
import { cn } from '@/lib/utils';

// Hellom Page policies for sellers and buyers (/kebijakan/:slug). Linked from checkout,
// public pages ("Laporkan") and the seller dashboard.
type Policy = { title: string; intro: string; sections: Array<{ heading: string; items: string[] }> };

const POLICIES: Record<string, Policy> = {
  syarat: {
    title: 'Syarat & Ketentuan Hellom Page',
    intro: 'Berlaku untuk penjual yang berjualan lewat halaman Hellom dan pembeli yang membayar lewat halaman tersebut.',
    sections: [
      {
        heading: 'Peran Hellom',
        items: [
          'Hellom menyediakan halaman toko, checkout, dan pemrosesan pembayaran. Penjual bertanggung jawab penuh atas produk, isi, dan pengirimannya.',
          'Semua pembayaran pembeli diterima Hellom lewat mitra pembayaran resmi, lalu dicatat di Saldo Penjualan penjual setelah dipotong biaya layanan.',
        ],
      },
      {
        heading: 'Kewajiban penjual',
        items: [
          'Menjual produk yang sah, milik sendiri atau berlisensi, dan tidak termasuk daftar produk terlarang.',
          'Mengirim produk sesuai deskripsi. Produk digital dikirim otomatis lewat halaman akses Hellom; produk fisik dan jasa wajib diproses penjual.',
          'Menanggapi pertanyaan dan keluhan pembeli dengan wajar.',
          'Data diri (KTP) dan rekening harus benar dan atas nama yang sama. Email harus terverifikasi sebelum menarik dana.',
        ],
      },
      {
        heading: 'Biaya & penarikan dana',
        items: [
          'Biaya layanan Hellom dipotong dari setiap penjualan dan sudah termasuk biaya pembayaran. Rinciannya tampil di Saldo Penjualan.',
          'Dana bisa ditarik setelah masa tahan (jika ada) dan diproses paling lambat 1×24 jam. Minimal penarikan tercantum di menu Saldo.',
          'Hellom dapat menahan saldo penjual yang dilaporkan atau diduga melanggar, sampai pemeriksaan selesai.',
        ],
      },
      {
        heading: 'Pelanggaran',
        items: [
          'Hellom berhak menonaktifkan produk atau toko yang melanggar ketentuan ini, dengan atau tanpa pemberitahuan sebelumnya.',
          'Dana dari transaksi penipuan dapat dikembalikan ke pembeli.',
        ],
      },
    ],
  },
  refund: {
    title: 'Kebijakan Refund',
    intro: 'Pengembalian dana (refund) untuk pembelian lewat halaman Hellom.',
    sections: [
      {
        heading: 'Siapa yang memutuskan refund',
        items: [
          'Refund diajukan oleh penjual dari dashboard, misalnya karena produk tidak bisa dikirim, pembeli salah beli, atau kesepakatan dengan pembeli.',
          'Pembeli yang ingin refund dapat menghubungi penjual lewat halaman akses pesanan. Jika penjual tidak merespons atau ada dugaan penipuan, gunakan tombol "Laporkan" di halaman toko.',
        ],
      },
      {
        heading: 'Proses',
        items: [
          'Nominal refund langsung dipotong dari Saldo Penjualan penjual saat diajukan.',
          'Tim Hellom mentransfer refund ke rekening/e-wallet pembeli yang didaftarkan penjual, biasanya 1×24 jam hari kerja. Pembeli menerima email saat refund diproses dan saat selesai.',
          'Jika transfer gagal (misalnya nomor rekening salah), dana kembali ke saldo penjual dan refund dapat diajukan ulang.',
        ],
      },
      {
        heading: 'Biaya',
        items: [
          'Biaya layanan Hellom atas transaksi tidak ikut dikembalikan; nominal refund ditanggung penjual.',
          'Setelah refund selesai, akses produk digital untuk pesanan tersebut dinonaktifkan.',
        ],
      },
    ],
  },
  'produk-terlarang': {
    title: 'Produk Terlarang',
    intro: 'Produk dan konten berikut tidak boleh dijual atau ditampilkan di halaman Hellom.',
    sections: [
      {
        heading: 'Dilarang',
        items: [
          'Barang atau jasa ilegal menurut hukum Indonesia: narkotika, senjata, bahan peledak, obat keras tanpa izin, satwa dilindungi.',
          'Produk bajakan atau yang melanggar hak cipta/merek: e-book, kelas, software, template, film, atau musik tanpa lisensi.',
          'Judi, investasi bodong, skema piramida/money game, dan "cara cepat kaya" yang menyesatkan.',
          'Konten pornografi, kekerasan, ujaran kebencian, atau SARA.',
          'Data pribadi orang lain, akun hasil peretasan, alat peretasan, atau layanan pembobolan.',
          'Produk yang tidak pernah dikirim atau deskripsi yang menipu.',
        ],
      },
      {
        heading: 'Jika menemukan pelanggaran',
        items: ['Klik "Laporkan" di bagian bawah halaman toko. Tim Hellom akan meninjau dan dapat menonaktifkan produk, toko, serta menahan saldo penjual.'],
      },
    ],
  },
};

const NAV: Array<[string, string]> = [['syarat', 'Syarat & Ketentuan'], ['refund', 'Refund'], ['produk-terlarang', 'Produk Terlarang']];

export default function SellerPolicyPage() {
  const { slug = 'syarat' } = useParams();
  const policy = POLICIES[slug] ?? POLICIES.syarat;

  useEffect(() => { document.title = `${policy.title} · Hellom`; }, [policy.title]);

  return (
    <main className="min-h-[100svh] bg-zinc-50 px-4 py-8 text-zinc-900">
      <div className="mx-auto max-w-2xl">
        <nav className="flex gap-2 overflow-x-auto pb-2">
          {NAV.map(([key, label]) => (
            <NavLink key={key} to={`/kebijakan/${key}`} className={({ isActive }) => cn('flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-semibold', isActive ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-600 ring-1 ring-zinc-200')}>
              {label}
            </NavLink>
          ))}
        </nav>
        <article className="mt-4 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-zinc-100 md:p-8">
          <h1 className="text-2xl font-bold">{policy.title}</h1>
          <p className="mt-2 text-sm leading-6 text-zinc-500">{policy.intro}</p>
          {policy.sections.map((section) => (
            <section key={section.heading} className="mt-6">
              <h2 className="text-base font-bold">{section.heading}</h2>
              <ul className="mt-2 list-disc space-y-2 pl-5 text-sm leading-6 text-zinc-700">
                {section.items.map((item) => <li key={item}>{item}</li>)}
              </ul>
            </section>
          ))}
          <p className="mt-8 text-xs text-zinc-400">Pertanyaan? <Link to="/kontak" className="underline">Hubungi tim Hellom</Link>.</p>
        </article>
      </div>
    </main>
  );
}
