import { Link } from 'react-router-dom';
import LegalPageLayout, { LegalSection } from '@/components/legal/LegalPageLayout';
import useBrand from '@/hooks/useBrand';
import { COMPANY_INFO, formatCompanyAddress } from '@/lib/companyInfo';

export default function TermsPage() {
  const { brand } = useBrand();
  const brandName = brand.app_name || brand.business_name || COMPANY_INFO.brandName;
  const email = brand.support_email || COMPANY_INFO.fallbackEmail;
  const address = formatCompanyAddress();

  return (
    <LegalPageLayout
      title="Syarat & Ketentuan"
      description={`Ketentuan penggunaan layanan dan pembelian produk di ${brandName} (${COMPANY_INFO.domain}).`}
      lastUpdated="19 Juni 2026"
    >
      <LegalSection heading="1. Penerimaan Ketentuan">
        <p>
          Dengan mengakses dan menggunakan website {COMPANY_INFO.domain} (“Layanan”) yang dioperasikan oleh{' '}
          <strong>{COMPANY_INFO.legalName}</strong>, Anda menyatakan telah membaca, memahami, dan menyetujui untuk
          terikat pada Syarat & Ketentuan ini. Jika Anda tidak setuju, mohon untuk tidak menggunakan Layanan.
        </p>
      </LegalSection>

      <LegalSection heading="2. Akun Pengguna">
        <p>
          Sebagian fitur mengharuskan Anda membuat akun. Anda bertanggung jawab menjaga kerahasiaan kredensial akun
          dan atas seluruh aktivitas yang terjadi pada akun Anda. Data yang Anda berikan harus akurat dan terkini.
        </p>
      </LegalSection>

      <LegalSection heading="3. Produk & Lisensi">
        <p>
          Produk yang dijual berupa produk digital dan akses aplikasi. Kecuali dinyatakan lain, pembelian memberikan
          Anda lisensi non-eksklusif dan tidak dapat dipindahtangankan untuk penggunaan sesuai deskripsi masing-masing
          produk. Anda dilarang menjual ulang, mendistribusikan, atau menggandakan produk tanpa izin tertulis.
        </p>
      </LegalSection>

      <LegalSection heading="4. Harga & Pembayaran">
        <p>
          Seluruh harga tercantum dalam Rupiah (IDR). Pembayaran diproses melalui payment gateway resmi (antara lain
          iPaymu) menggunakan metode seperti QRIS, Virtual Account bank, dan gerai retail. Pesanan dianggap sah setelah
          pembayaran terkonfirmasi. Kami tidak menyimpan data kartu atau kredensial pembayaran Anda.
        </p>
      </LegalSection>

      <LegalSection heading="5. Pengiriman Produk Digital">
        <p>
          Produk dikirim secara elektronik. Setelah pembayaran berhasil, produk digital langsung tersedia untuk
          diunduh pada akun Anda, dan akses aplikasi diaktifkan otomatis. Tidak ada pengiriman barang fisik.
        </p>
      </LegalSection>

      <LegalSection heading="6. Pembatalan & Refund">
        <p>
          Anda dapat membatalkan pesanan selama pembayaran belum lunas. Setelah lunas, pembelian bersifat final dan
          tidak dapat di-refund, kecuali pada kondisi tertentu yang diatur dalam{' '}
          <Link to="/refund-policy" className="font-semibold text-yellow-400 hover:underline">Kebijakan Refund</Link>{' '}
          yang merupakan bagian tidak terpisahkan dari ketentuan ini.
        </p>
      </LegalSection>

      <LegalSection heading="7. Penggunaan yang Dilarang">
        <p>Anda setuju untuk tidak menggunakan Layanan untuk:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>aktivitas melanggar hukum yang berlaku di Republik Indonesia;</li>
          <li>melanggar hak kekayaan intelektual pihak mana pun;</li>
          <li>menyebarkan malware, melakukan peretasan, atau mengganggu operasional sistem;</li>
          <li>tindakan penipuan, termasuk penyalahgunaan metode pembayaran.</li>
        </ul>
      </LegalSection>

      <LegalSection heading="8. Hak Kekayaan Intelektual">
        <p>
          Seluruh konten, merek, logo, dan materi pada Layanan adalah milik {COMPANY_INFO.legalName} atau pemberi
          lisensinya dan dilindungi undang-undang. Pembelian produk tidak mengalihkan kepemilikan hak cipta.
        </p>
      </LegalSection>

      <LegalSection heading="9. Batasan Tanggung Jawab">
        <p>
          Layanan disediakan “sebagaimana adanya”. Sepanjang diizinkan hukum, {COMPANY_INFO.legalName} tidak
          bertanggung jawab atas kerugian tidak langsung yang timbul dari penggunaan Layanan. Tanggung jawab maksimal
          kami terbatas pada nilai transaksi produk terkait.
        </p>
      </LegalSection>

      <LegalSection heading="10. Perubahan Ketentuan">
        <p>
          Kami dapat memperbarui Syarat & Ketentuan ini sewaktu-waktu. Versi terbaru berlaku sejak dipublikasikan di
          halaman ini. Penggunaan Layanan secara berkelanjutan dianggap sebagai persetujuan atas perubahan tersebut.
        </p>
      </LegalSection>

      <LegalSection heading="11. Hukum yang Berlaku">
        <p>
          Ketentuan ini diatur dan ditafsirkan berdasarkan hukum Republik Indonesia. Setiap perselisihan akan
          diselesaikan secara musyawarah, dan bila tidak tercapai, melalui jalur hukum yang berlaku.
        </p>
      </LegalSection>

      <LegalSection heading="12. Kontak">
        <p>
          Untuk pertanyaan terkait ketentuan ini, hubungi kami di{' '}
          <a href={`mailto:${email}`} className="font-semibold text-yellow-400 hover:underline">{email}</a>
          {address ? <> atau kunjungi kami di {address}</> : null}. Detail lengkap tersedia pada halaman{' '}
          <Link to="/contact" className="font-semibold text-yellow-400 hover:underline">Kontak</Link>.
        </p>
      </LegalSection>
    </LegalPageLayout>
  );
}
