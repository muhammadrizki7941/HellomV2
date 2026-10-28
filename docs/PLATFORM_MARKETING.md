# Analitik & Iklan Hellomspace

Menu: Super Admin → Analitik & Iklan (`/admin/marketing`).

## Aktivasi

1. Jalankan migrasi `2026_10_28_120000_create_platform_marketing_events.php` dan build frontend.
2. Analitik lokal aktif secara default. Data baru dicatat setelah pengunjung mengizinkan pelacakan; tidak ada data historis yang dibuat-buat.
3. Di tab Integrasi Meta, isi Pixel/Dataset ID, access token Conversions API, versi Graph API yang didukung akun, dan aktifkan integrasi. Token tersimpan terenkripsi dan tidak dikirim kembali ke browser.
4. Salin Test Event Code dari Events Manager, simpan, lalu klik Kirim event tes. Status `sent` berarti API Meta menerima event; periksa juga Test Events pada dataset yang benar. Kode tes hanya berlaku untuk tombol tes.
5. Pastikan scheduler Laravel yang sudah ada menjalankan `php artisan schedule:run` setiap menit. Pengiriman pertama berjalan setelah respons HTTP; scheduler mencoba kembali event pending/gagal setiap lima menit (maksimum lima percobaan otomatis, umur event maksimum tujuh hari). Tombol Coba ulang tersedia pada riwayat.
6. Buat tautan pada tab Tautan kampanye. Gunakan domain produksi publik, bukan localhost. Tempel URL ke Meta Ads. Fitur ini tidak membuat iklan atau membelanjakan anggaran.

## Data dan batasan

- PageView/ViewContent: halaman publik Hellomspace, persetujuan pengunjung diperlukan. Tidak memasang pixel platform pada toko milik seller atau layar operasional POS.
- CompleteRegistration: pendaftaran akun yang berhasil di server.
- InitiateCheckout/Purchase: checkout langganan dan pembelian produk digital. Purchase hanya dari status pembayaran terkonfirmasi, nominal IDR dari server, bukan browser. Top-up bukan pembelian. Refund tidak mengurangi pendapatan pembelian bruto di laporan.
- Event browser dan CAPI untuk halaman berbagi event ID. Konversi pendaftaran/pembelian dikirim server saja. ID unik database mencegah penghitungan ulang saat webhook/polling berulang.
- Atribusi kampanye terakhir tersimpan 30 hari dalam browser yang sama; sesi baru setelah 30 menit tidak aktif. Pengunjung unik berarti ID browser, bukan identitas orang. Pengaturan ulang browser, consent, ad blocker, dan perpindahan perangkat memengaruhi angka.
- Funnel adalah jumlah aktivitas pada periode terpilih, bukan analisis cohort. Zona waktu laporan mengikuti konfigurasi backend dan ditampilkan di UI.
- Biaya iklan, CPA, dan ROAS memerlukan koneksi Marketing API akun iklan; belum termasuk tahap ini.
- Tanpa Pixel ID/token, analitik internal tetap dapat digunakan. Koneksi akun Meta sesungguhnya harus diverifikasi memakai akun pemilik; pengujian otomatis menggunakan respons Meta simulasi.

## Verifikasi

`php vendor/bin/phpunit -c phpunit.pos.xml tests/Admin/PlatformMarketingTest.php tests/Landing/SubscriptionCheckoutPriceTest.php tests/Landing/IpaymuBillingWebhookTest.php`

`npm --prefix frontend run lint` dan `npm --prefix frontend run build` dari root proyek.

`node --test frontend/tests/platformMarketing.test.mjs` menguji consent, kesinambungan kampanye, dan pemisahan Pixel seller dari platform.

Migrasi harus sudah tersedia pada database pengujian `hellom_pos_test` sebelum pengujian integrasi.
