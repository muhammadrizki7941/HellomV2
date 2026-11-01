# Email Hellom via Resend

Semua email platform—verifikasi akun, reset akses, undangan, receipt, notifikasi pengguna, dan salinan aktivitas untuk super admin—dikirim melalui Resend. Tidak ada fallback ke Gmail SMTP.

## Konfigurasi

1. Pastikan domain pengirim sudah berstatus **Verified** di Resend. Ikuti record DNS yang ditampilkan Resend; status parsial belum cukup.
2. Buat API key Resend dengan izin pengiriman, lalu atur secara rahasia di environment backend:

   ```dotenv
   RESEND_API_KEY=re_...
   ```

   Jangan masukkan API key ke frontend, dashboard, dokumentasi, Git, atau chat. File `.env` lokal dan environment produksi harus diisi terpisah.
3. Di Super Admin → Pengaturan → Email, aktifkan email dan atur From menggunakan alamat pada domain terverifikasi (misalnya `noreply@hellomspace.com`), nama pengirim, Reply-To, dan alamat owner untuk salinan notifikasi super admin.
4. Simpan, kirim email tes ke inbox yang bisa diperiksa, lalu cek Riwayat Email. Status terkirim berarti Resend menerima permintaan, bukan jaminan pesan masuk inbox.
5. Setelah mengubah environment produksi, jalankan `php artisan config:cache` dan `php artisan queue:restart` dari direktori `backend`. Pastikan worker queue aktif untuk pengiriman asynchronous.

API key dibaca backend dari `services.resend.key` (`RESEND_API_KEY`); key tidak disimpan di database atau dikirim ke browser. Pengaturan super admin hanya menyimpan status aktif, identitas pengirim, Reply-To, dan alamat penerima notifikasi.

## Catatan operasional

- Jika API key belum diatur atau email dinonaktifkan, email tidak dikirim dan tidak dilaporkan sebagai sukses.
- Pengiriman gagal tercatat di Riwayat Email dan dapat dikirim ulang. Periksa penyebab sebelum retry massal.
- Email pemasaran tetap menghormati unsubscribe; receipt, verifikasi, reset akses, dan notifikasi transaksional tetap dikirim.
- Pengiriman melalui queue membutuhkan `QUEUE_CONNECTION=database` (atau queue yang dikonfigurasi) dan worker yang berjalan.
- Cek domain pada halaman pengaturan memeriksa record SPF, DKIM, dan DMARC. Untuk status verifikasi resmi, rujuk dashboard Resend.
