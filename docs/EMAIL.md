# Email Hellomspace

Email platform menggunakan pengaturan di Super Admin → Pengaturan → Email. Pengaturan SMTP database yang aktif dan lengkap mengutamakan konfigurasi tersebut daripada .env. Jangan commit App Password atau membagikannya di dokumentasi.

## Gmail dengan App Password

- Aktifkan email, host smtp.gmail.com, port 587, TLS (alternatif 465, SSL).
- Username dan alamat pengirim: alamat Gmail lengkap yang sama. Nama pengirim: Hellomspace.
- Password: App Password akun Google tersebut, bukan password login. Spasi pemisah dibuang otomatis, termasuk dari pengaturan lama.
- Reply-To: mailbox yang benar-benar dipantau.
- Simpan, lalu kirim satu email tes ke alamat sendiri. Status sent berarti server SMTP menerima email, bukan bukti inbox atau dibaca.
- Di Gmail, buka Tampilkan versi asli untuk memeriksa SPF, DKIM, dan DMARC. Google mengelola DNS gmail.com; jangan menambahkan record di hellomspace.com untuk mengautentikasi pengirim @gmail.com.
- Jika mendapat 535 BadCredentials, periksa akun, App Password, verifikasi dua langkah, dan apakah App Password telah dicabut. Jangan menonaktifkan verifikasi sertifikat TLS.

Jika memakai .env untuk fallback, gunakan MAIL_MAILER=smtp, MAIL_SCHEME=smtp, MAIL_HOST=smtp.gmail.com, MAIL_PORT=587, MAIL_USERNAME=alamat Gmail, MAIL_PASSWORD=App Password, MAIL_FROM_ADDRESS=alamat Gmail yang sama. Setelah mengubahnya, jalankan php artisan config:cache dan php artisan queue:restart dari direktori backend. Konfigurasi lokal tidak otomatis tersalin ke VPS.

## Cakupan email

- Pendaftaran: sambutan, verifikasi, dan langkah awal; tips hari kedua sesuai kriteria lifecycle, dengan unsubscribe.
- Pembayaran aplikasi: receipt untuk manual, wallet, dan gateway. Konfirmasi gateway melalui rekonsiliasi juga mengirim receipt. Alamat pembeli yang sama dengan owner hanya menerima satu receipt per pemanggilan.
- Perpanjangan wallet otomatis: ringkasan paket dan masa aktif.
- Produk digital: pembeli login maupun tamu menerima email setelah paid. Pembeli login menggunakan tautan dashboard biasa tanpa perubahan password; alur akses tamu tetap tersedia.
- Email produk dijalankan setelah commit melalui queue, dengan tiga percobaan dan jeda 60/300 detik. Marker pengiriman mencegah pengulangan normal; seperti SMTP pada umumnya, timeout setelah server menerima email tetap dapat menghasilkan duplikat.
- Mode log tidak dilaporkan sebagai berhasil terkirim. Pengiriman gagal masuk Riwayat Email dan dapat dikirim ulang. Jangan retry massal sebelum memeriksa penyebab.

## VPS

Setelah deploy kode, periksa konfigurasi melalui Super Admin → Pengaturan → Email pada domain produksi, kirim tes ke alamat sendiri, dan periksa Riwayat Email. Jalankan php artisan queue:failed untuk melihat kegagalan worker; scheduler dan worker queue harus aktif. Tidak ada migrasi baru untuk perubahan email ini.

## Jika ingin From @hellomspace.com

Gunakan Google Workspace atau penyedia SMTP yang memverifikasi domain tersebut. Ikuti record SPF/DKIM/DMARC yang diberikan penyedia; alias harus diizinkan dan diverifikasi. Record DNS yang ditemukan tidak membuktikan autentikasi email aktual: periksa header pesan yang diterima.

Pisahkan receipt dari promosi, hormati unsubscribe, pantau bounce dan keluhan, serta hindari pengiriman massal mendadak. Tidak ada konfigurasi yang menjamin 100% inbox.

Referensi: https://support.google.com/mail/answer/22370 dan https://support.google.com/mail/answer/81126
