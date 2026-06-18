import { Link } from 'react-router-dom';
import LegalPageLayout, { LegalSection } from '@/components/legal/LegalPageLayout';
import useBrand from '@/hooks/useBrand';
import { COMPANY_INFO } from '@/lib/companyInfo';

export default function RefundPolicyPage() {
  const { brand } = useBrand();
  const brandName = brand.app_name || brand.business_name || COMPANY_INFO.brandName;
  const email = brand.support_email || COMPANY_INFO.fallbackEmail;

  return (
    <LegalPageLayout
      title="Kebijakan Refund"
      description={`Ketentuan pengembalian dana untuk pembelian produk dan layanan di ${brandName}.`}
      lastUpdated="19 Juni 2026"
    >
      <div className="rounded-2xl border border-amber-400/30 bg-amber-400/10 p-5 text-amber-100">
        <p className="font-semibold">Ringkasan singkat</p>
        <p className="mt-2">
          Produk yang dijual bersifat <strong>digital</strong> dan dapat langsung diunduh/diakses setelah pembayaran.
          Oleh karena itu, <strong>semua pembelian yang telah lunas bersifat final dan tidak dapat di-refund</strong>.
          Selama pembayaran belum lunas, Anda masih dapat membatalkan pesanan tanpa biaya.
        </p>
      </div>

      <LegalSection heading="1. Sifat Produk Digital">
        <p>
          {brandName} menjual produk digital (seperti source code, template, e-book, kursus) dan akses aplikasi
          berbasis langganan/satu kali bayar. Begitu pembayaran berhasil, produk langsung dikirim secara elektronik —
          tersedia untuk diunduh atau diaktifkan seketika. Karena produk tidak dapat “dikembalikan” seperti barang
          fisik, pembelian yang sudah selesai tidak memenuhi syarat untuk pengembalian dana.
        </p>
      </LegalSection>

      <LegalSection heading="2. Pembelian Bersifat Final">
        <p>
          Semua transaksi yang telah berstatus <strong>LUNAS / PAID</strong> bersifat final dan{' '}
          <strong>tidak dapat dibatalkan maupun di-refund</strong> dengan alasan apa pun, termasuk namun tidak
          terbatas pada: berubah pikiran, salah membeli, produk sudah diunduh, atau ketidaksesuaian preferensi
          pribadi. Mohon pastikan produk yang Anda pilih sudah benar sebelum menyelesaikan pembayaran.
        </p>
      </LegalSection>

      <LegalSection heading="3. Pembatalan Sebelum Pembayaran">
        <p>
          Selama transaksi masih berstatus <strong>menunggu pembayaran (pending)</strong> dan belum lunas, Anda
          berhak membatalkan pesanan kapan saja secara mandiri melalui halaman checkout produk. Pembatalan pada tahap
          ini tidak dikenakan biaya, dan instruksi pembayaran (Virtual Account/QRIS) yang belum dibayar akan otomatis
          dibatalkan.
        </p>
      </LegalSection>

      <LegalSection heading="4. Pengecualian">
        <p>Pengembalian dana hanya dapat dipertimbangkan dalam kondisi luar biasa berikut:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>
            Anda terkena <strong>pembayaran ganda (double charge)</strong> untuk produk yang sama akibat kesalahan
            teknis sistem; kelebihan pembayaran akan dikembalikan penuh.
          </li>
          <li>
            Produk <strong>tidak dapat diakses sama sekali</strong> akibat kesalahan teknis dari pihak kami dan tidak
            dapat kami perbaiki dalam waktu yang wajar setelah dilaporkan.
          </li>
        </ul>
        <p>
          Permohonan untuk kasus di atas harus diajukan maksimal <strong>3×24 jam</strong> sejak transaksi, disertai
          bukti transaksi dan penjelasan masalah.
        </p>
      </LegalSection>

      <LegalSection heading="5. Proses Pengajuan">
        <p>
          Untuk kasus yang memenuhi pengecualian di atas, hubungi kami di{' '}
          <a href={`mailto:${email}`} className="font-semibold text-yellow-400 hover:underline">{email}</a> dengan
          menyertakan nomor invoice/kode transaksi. Permohonan yang disetujui akan diproses kembali ke metode
          pembayaran asal dalam <strong>7–14 hari kerja</strong>, tergantung kebijakan bank/penyedia pembayaran.
        </p>
      </LegalSection>

      <LegalSection heading="6. Perubahan Kebijakan">
        <p>
          Kebijakan ini dapat diperbarui sewaktu-waktu. Perubahan berlaku sejak dipublikasikan di halaman ini.
          Dengan melakukan pembelian, Anda dianggap telah membaca dan menyetujui kebijakan ini beserta{' '}
          <Link to="/terms" className="font-semibold text-yellow-400 hover:underline">Syarat & Ketentuan</Link>.
        </p>
      </LegalSection>
    </LegalPageLayout>
  );
}
