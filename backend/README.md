# Backend (Laravel API)

Lihat [README utama](../README.md) dan [docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md).

## Pusat Bantuan

- Jalankan migrasi database setelah deploy: `php artisan migrate --force`.
- Atur `GROQ_API_KEY` di `.env` untuk mengaktifkan AI Pusat Bantuan. Model default `openai/gpt-oss-120b` dapat diganti melalui `GROQ_MODEL`. Jika provider gagal berulang atau konteks tidak cukup, percakapan akan dialihkan ke tiket agent.
- Pastikan worker antrean berjalan agar notifikasi email Pusat Bantuan terkirim: `php artisan queue:work`.
- Superadmin mengelola agent, kategori, balasan cepat, dan artikel di `/admin/support`. Agent memakai akun Hellom yang diaktifkan superadmin dan membuka `/support/agent`.
- Pengguna mengakses Pusat Bantuan dari `/dashboard/support`. Lampiran disimpan pada disk privat backend dan hanya dapat diunduh oleh pemilik tiket atau agent aktif.

## Checkout POS iPaymu

- Superadmin dapat memilih aplikasi promosi yang belum dimiliki pengguna melalui Admin → Analitik & Iklan → Promosi dashboard. Pilihan disimpan di `system_settings` dan kartu hanya tampil untuk organisasi tanpa entitlement aktif.
- Aplikasi satu kali beli dapat ikut dipromosikan bila produknya diterbitkan sebagai “Aplikasi unggulan”; pembeli yang masih memiliki akses tidak akan melihat kartu tersebut. Ikon aplikasi langganan diatur dari Admin → Aplikasi & Paket, sedangkan ikon produk satu kali beli menggunakan thumbnail produk.
- Setelah menambah kolom ikon aplikasi, jalankan `php artisan migrate --force`.
- Jalankan `php artisan migrate --force` setelah deploy untuk membuat tabel pembayaran checkout dan menyimpan token status secara hash.
- Isi `IPAYMU_VA`, `IPAYMU_API_KEY`, `IPAYMU_ENV` (`sandbox` atau `production`), dan `IPAYMU_NOTIFY_URL` pada `.env`. URL notifikasi harus HTTPS dan mengarah ke `/api/v1/hellom/public/pos-checkout/ipaymu/callback`. Kredensial tetap berada di backend.
- Kanal QRIS/Virtual Account yang ditawarkan mengikuti kanal Direct Payment yang diaktifkan superadmin. Checkout POS tidak menggunakan halaman redirect iPaymu.
- Pastikan scheduler Laravel berjalan setiap menit (`php artisan schedule:run` dari cron) agar invoice checkout yang kedaluwarsa ditutup otomatis. Worker antrean juga diperlukan untuk email instruksi dan konfirmasi.
- Jalankan uji callback simulasi hanya di `local`/`testing` dengan `IPAYMU_ENV=sandbox` dan `BILLING_MOCK_ENABLED=true`: `php artisan hellom:billing:simulate-pos-checkout {intent_token} --status=paid`.
- Halaman tamu dapat dibuka dari landing page dengan `?checkout=pos`, `?paket=<slug>`, dan `?promo=<kode>`. Halaman status menggunakan tautan ber-token terbatas `/pembayaran/{token}`.
- Meta Pixel ID dan Conversions API access token dapat disediakan melalui `META_PIXEL_ID` dan `META_ACCESS_TOKEN` (opsional; keduanya tetap dapat dikelola lewat pengaturan platform). Aktifkan pelacakan Meta dari pengaturan platform; gunakan `META_API_VERSION` bila perlu mengganti versi Graph API.
