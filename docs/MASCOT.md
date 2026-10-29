# Maskot dan halaman akun Hellom

Halaman login, daftar, lupa kata sandi, dan reset kata sandi menggunakan LightAuthLayout. Tema terang dibatasi pada halaman ini; AuthShell lama untuk undangan tetap tersedia. Ilustrasi bisnis asli ada di public/images/auth.

HellomMascot menyediakan trolley, welcome, loading, maintenance, error, dan success. Dua aset WebP di public/images/mascot berasal dari gambar pengguna dan berukuran total sekitar 180 KiB. Ekspresi menggunakan satu sprite yang dicache browser; troli memakai WebP transparan. Tidak ada GIF, video, library animasi baru, atau request API untuk animasi.

- RouteJourney: animasi CSS 1,2 detik saat pathname berubah, termasuk halaman publik. Tidak memblokir klik, tidak menunda navigasi, tidak menggunakan progress persentase palsu. Dikecualikan pada POS dan rute order/customer untuk mengurangi gangguan saat transaksi.
- PublicLayout: halaman muncul langsung, tanpa menunggu curtain/preloader lama. Navbar/footer tetap ada ketika chunk halaman dimuat.
- HellomLoading: mode page/overlay/inline tetap didukung. Troli saat menunggu; ekspresi jam pasir setelah enam detik. Timer dibersihkan ketika komponen dilepas. Proses lambat bukan dianggap kegagalan atau maintenance.
- ConnectionNotice: mengikuti event online/offline browser, dapat ditutup; tidak membuktikan status kesehatan backend.
- ServiceNotice: respons 503 melalui API client memunculkan ekspresi teknisi dan pesan sementara tidak tersedia selama sepuluh detik. Tidak mengklaim maintenance terjadwal atau ETA. Tidak mencoba ulang pembayaran.
- PageErrorBoundary: menangkap kegagalan render React/chunk, menampilkan pose gangguan dan opsi muat ulang. Tidak menangkap semua error async dari event handler.
- Ekspresi sukses dipakai pada konfirmasi permintaan email pemulihan dan keberhasilan reset password. Pesan pemulihan tetap tidak membocorkan apakah akun terdaftar.

prefers-reduced-motion mematikan animasi CSS dan menyembunyikan transisi dekoratif. Label status tetap berupa teks, maskot dekoratif disembunyikan dari screen reader. Animasi dekoratif berakhir sendiri; animasi loading hanya berjalan selama komponen loading ditampilkan.

Verifikasi lokal: tsc, production build, inspeksi desktop/390 px, pratinjau sprite, perubahan pose loading lambat, penghitung animationstart saat pindah rute, dan simulasi notifikasi 503. Halaman pratinjau sementara dihapus setelah pemeriksaan.
