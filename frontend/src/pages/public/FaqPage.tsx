import { Link } from 'react-router-dom';
import LegalPageLayout, { LegalSection } from '@/components/legal/LegalPageLayout';
import useBrand from '@/hooks/useBrand';
import { COMPANY_INFO } from '@/lib/companyInfo';

const FAQS: Array<{ q: string; a: React.ReactNode }> = [
  {
    q: 'Apa itu Hellom?',
    a: (
      <>
        Hellom adalah platform digital yang menyediakan produk digital (source code, template, e-book, kursus),
        aplikasi siap pakai seperti POS / Kasir Digital dan Landing Page Builder, serta layanan terkait untuk
        kebutuhan bisnis modern. Semua produk dapat dibeli dan diakses langsung melalui website.
      </>
    ),
  },
  {
    q: 'Bagaimana cara membeli produk?',
    a: (
      <>
        Buat akun atau masuk, buka katalog produk, pilih produk yang diinginkan, lalu lanjutkan ke pembayaran.
        Setelah pembayaran berhasil, produk otomatis aktif di akun Anda dan dapat langsung diunduh atau digunakan.
      </>
    ),
  },
  {
    q: 'Metode pembayaran apa saja yang tersedia?',
    a: (
      <>
        Pembayaran diproses secara aman melalui payment gateway resmi. Anda dapat membayar menggunakan{' '}
        <strong>QRIS</strong> (semua e-wallet & m-banking), <strong>Virtual Account</strong> bank
        (BCA, BNI, BRI, Mandiri, Permata, CIMB), serta gerai retail (Indomaret/Alfamart). Beberapa produk juga
        mendukung konfirmasi pembayaran manual.
      </>
    ),
  },
  {
    q: 'Apakah pembayaran saya aman?',
    a: (
      <>
        Ya. Seluruh transaksi diproses melalui penyedia payment gateway berlisensi. Hellom tidak menyimpan data
        kartu atau kredensial pembayaran Anda. Status pembayaran diperbarui otomatis setelah dana diterima.
      </>
    ),
  },
  {
    q: 'Bagaimana saya menerima produk setelah membayar?',
    a: (
      <>
        Untuk produk digital, file dan dokumentasi langsung tersedia di halaman produk serta menu “Produk Saya”
        begitu pembayaran terkonfirmasi. Untuk aplikasi (POS / Landing Builder), akses akan otomatis aktif di
        dashboard akun Anda.
      </>
    ),
  },
  {
    q: 'Apakah saya bisa membatalkan pesanan?',
    a: (
      <>
        Selama pembayaran <strong>belum lunas</strong> (status menunggu pembayaran), Anda dapat membatalkan pesanan
        kapan saja melalui halaman checkout. Setelah pembayaran berhasil, produk bersifat final. Lihat{' '}
        <Link to="/refund-policy" className="font-semibold text-yellow-400 hover:underline">Kebijakan Refund</Link> untuk detailnya.
      </>
    ),
  },
  {
    q: 'Apakah produk bisa di-refund?',
    a: (
      <>
        Karena produk bersifat digital dan dapat langsung diunduh/diakses setelah pembayaran, semua pembelian yang
        sudah lunas bersifat <strong>final dan tidak dapat di-refund</strong>. Silakan baca{' '}
        <Link to="/refund-policy" className="font-semibold text-yellow-400 hover:underline">Kebijakan Refund</Link> sebelum membeli.
      </>
    ),
  },
  {
    q: 'Saya butuh bantuan. Bagaimana cara menghubungi Hellom?',
    a: (
      <>
        Tim kami siap membantu pada {COMPANY_INFO.operatingHours}. Hubungi kami melalui halaman{' '}
        <Link to="/contact" className="font-semibold text-yellow-400 hover:underline">Kontak</Link> — tersedia email
        dan WhatsApp.
      </>
    ),
  },
];

export default function FaqPage() {
  const { brand } = useBrand();
  const brandName = brand.app_name || brand.business_name || COMPANY_INFO.brandName;

  return (
    <LegalPageLayout
      title="Pertanyaan yang Sering Diajukan (FAQ)"
      description={`Jawaban atas pertanyaan umum seputar produk, pembayaran, dan layanan ${brandName}.`}
    >
      {FAQS.map((item) => (
        <LegalSection key={item.q} heading={item.q}>
          <p>{item.a}</p>
        </LegalSection>
      ))}
    </LegalPageLayout>
  );
}
