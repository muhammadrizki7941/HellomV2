# Changelog

## [Unreleased] — Refactor & hardening (branch `refactor/cleanup`, 2026-09-27 … 2026-09-29)

Audit lengkap dan status per temuan: [docs/AUDIT.md](docs/AUDIT.md). Langkah deploy khusus rilis ini: [docs/DEPLOY.md §3](docs/DEPLOY.md#3-catatan-khusus-rilis-refactor-branch-refactorcleanup).

### Hellom Page — editor link-in-bio, Fase 0–1 (branch `feat/linkinbio-builder`, 2026-10-03)
Audit & status: [docs/audit-linkinbio-builder.md](docs/audit-linkinbio-builder.md).
- **Perbaikan gambar**: foto HP (sampai 8 MB) bisa diunggah (dulu ditolak di atas 4 MB dengan pesan bahasa Inggris); gambar di editor tampil juga saat dashboard & server beda alamat (dev lokal).
- **Onboarding adaptif**: saat pertama membuka builder, penjual memilih yang biasa dipakai (lynk.id / Linktree / OrderHero / belum pernah); editor menyesuaikan istilah, urutan menu blok, template awal, dan menampilkan tur singkat. Bisa diubah di Pengaturan › Gaya editor.
- **Halaman berversi** (`schema_version`): halaman lama otomatis di-upgrade saat dibuka, tanpa mengubah tampilannya.
- Editor desktop halaman kosong langsung menampilkan panel blok; editor tidak terkunci lagi saat halaman pertama dibuat bersamaan.
- **Editor baru gaya link-in-bio (Fase 2)**: daftar blok + pratinjau HP yang merupakan halaman asli (dirender server, aman di iframe terpisah); ketuk bagian di pratinjau untuk mengedit; galeri "+ Tambah" bergambar; tampil/sembunyi, duplikat, hapus, geser urutan (juga keyboard), Urungkan/Ulangi; Lihat halaman & Salin link. Di HP: pratinjau penuh dengan bilah bawah dan bottom sheet. Blok bergaya landing page panjang tidak ditawarkan lagi (halaman lama tetap tampil).

### Hellom Page — pembeli bayar langsung di halaman toko (2026-10-03)
- **Perbaikan: pembayaran produk penjual selalu gagal** ("Pembayaran belum bisa dibuat"), padahal produk milik Hellom berhasil. Penyebab: checkout penjual memakai QRIS direct dengan permintaan & cara membaca jawaban iPaymu yang berbeda dari checkout Hellom (kode QRIS dikirim iPaymu di `PaymentNo` → QR kosong; nomor HP palsu).
- Sekarang **sama seperti checkout produk Hellom**: pembeli memilih QRIS, Virtual Account (BCA, BNI, BRI, Mandiri, Permata, CIMB) atau Indomaret/Alfamart dan membayar **di halaman toko** — QR tampil langsung, nomor VA dengan tombol Salin, tanpa diarahkan ke halaman iPaymu. Halaman otomatis lanjut ke produk setelah bayar; halaman status pesanan menampilkan QR/VA lagi bila dibuka ulang.
- Teks judul di halaman checkout/akses yang tidak terlihat (krem di atas putih) diperbaiki.
- Alasan gagal dari iPaymu disimpan di pesanan; perintah diagnosa `php artisan landing:payments-check`.

### Dashboard Super Admin — audit & perbaikan (branch `fix/super-admin-overhaul`, 2026-10-02 … 10-03)
Audit & status per temuan: [docs/audit-super-admin.md](docs/audit-super-admin.md).
- **Keamanan (penting)**: pemilik toko yang daftar sendiri tidak bisa lagi melihat/masuk ke organisasi lain (dulu bisa membaca tim, saldo, KTP & rekening toko lain). Migration mengembalikan organisasi aktif yang "asing" ke organisasi milik sendiri.
- **Pembayaran**: notifikasi iPaymu untuk langganan, produk digital, dan top-up kini selalu dicek ke API iPaymu (status, referensi, nominal) — sama seperti penjualan Hellom Page; tombol "cek pembayaran" tidak lagi menerima ID transaksi sembarang. **Langganan tahunan via iPaymu/Xendit dulu ditagih harga bulanan** — sekarang ditagih benar. DOKU wajib tanda tangan & dicek ke API; status lunas tidak bisa turun karena notifikasi telat; pesan jelas untuk akun DOKU yang belum aktif.
- **Data aman**: hapus paket yang punya riwayat → diarsipkan; hapus user ditolak bila dirinya sendiri, super admin, pemilik tunggal, atau punya riwayat pembayaran/saldo (pakai suspend).
- **Tidak dobel**: setujui transfer manual & konfirmasi pembelian produk berjalan sekali walau diklik dua kali (revenue & invoice tidak dobel); refund hanya untuk yang sudah lunas.
- **Menu baru**: Organisasi (suspend/aktifkan, atur akses dengan tanggal berakhir), Invoice, Log Audit; Ringkasan berisi angka platform + daftar "Perlu tindakan"; Kesehatan Sistem membaca `/api/health` (dulu angka statis); Keuangan Platform menaruh antrean transfer manual di atas; penyesuaian saldo penjual di Moderasi Toko; foto KTP bisa dilihat lagi.
- **Log audit** untuk semua perubahan sensitif (kredensial gateway — tanpa nilai rahasia, rekening transfer manual, branding, email, konten, produk, promo).
- **Lainnya**: format error API seragam; sesi berakhir otomatis saat token tidak berlaku; artikel disanitasi (XSS); logo SVG/favicon ICO bisa diunggah; respons branding publik tanpa logo base64; batas unggah 20 MB; daftar promo/transfer manual tidak terpotong diam-diam; teks admin Bahasa Indonesia; ESLint (`npm run lint:admin` bersih); broadcast promo & pengingat tagihan (tanpa UI, kirim sinkron ke semua user) dipensiunkan.
- **Tes**: 99 tes MySQL (`phpunit.pos.xml`, suite baru `Admin`) + smoke browser `tests/e2e/admin-smoke.mjs` (23/23).

### Akun — undangan, lupa kata sandi, daftar, email
- **Link undangan tidak lagi layar putih**: file tampilan (`/assets/*.js`) kini dilayani juga saat aplikasi dibuka lewat server Laravel.
- **Halaman undangan baru** `/invitation/accept`: menampilkan toko, peran & outlet; akun lama cukup kata sandi, orang baru cukup nama + kata sandi; kasir langsung masuk POS. Kasir yang punya usaha sendiri tetap bisa menerima undangan toko.
- **Lupa kata sandi**: email berisi tombol ke halaman "Buat kata sandi baru" (tanpa salin token), indikator kekuatan kata sandi. Kasir yang didaftarkan owner tapi belum punya akun menerima email aktivasi akun untuk tokonya.
- **Email**: logo Hellom kini tertanam di email (tampil di Gmail/Outlook/HP), tata letak baru yang bersih; isi email undangan & reset ditulis ulang.
- **Halaman daftar** baru yang bersih dengan pratinjau alamat toko yang tertulis saat nama usaha diketik.

### POS — hak akses kasir yang bisa diatur
- Owner/admin organisasi mengatur per kasir fitur POS apa saja yang boleh dibuka (POS › Staff › edit): batalkan & refund pesanan, kelola meja, produk, member, poin & data member, loyalty, promo & reservasi, laporan, buka/tutup kas, pengaturan pesanan outlet. **Kasir & pesanan selalu aktif.** Outlet, staf, dan pengaturan pembayaran tetap khusus owner/admin.
- Ditegakkan di server (middleware `posPermission`), menu POS kasir hanya menampilkan yang diizinkan dan ikut berubah tanpa login ulang.
- **Login kasir & staf terpisah** di `/login/kasir` (tautan dari halaman login pemilik): setelah berhasil langsung masuk ke toko & outlet tempat email itu terdaftar sebagai staf POS — walaupun akunnya juga punya/ikut usaha lain. Staf yang didaftarkan owner dengan email tertentu otomatis tertaut ke akun dengan email itu **jika email akun sudah terverifikasi**. Terdaftar di beberapa toko → pilih toko. Kasir yang keluar kembali ke halaman login kasir.
- **Buka/tutup kas di layar kasir** (tombol Kas di Orders): kas awal, penjualan tunai berjalan, uang seharusnya di laci, dan selisih saat tutup. Kelola meja & QR aktif bawaan untuk kasir.
- Perbaikan: menyimpan data staf dari form tidak lagi melepas akun login kasir yang tertaut; buka kas kini memakai outlet kasir (sebelumnya gagal untuk kasir di outlet selain outlet utama); pembatalan lewat endpoint status kini ikut dicek izinnya.

### Hellom Page — poles & siap produksi (Fase 5)
- **Onboarding penjual baru** 3 langkah (username → template → produk pertama → halaman terbit & siap dibagikan) + checklist *Siapkan toko kamu* di Overview (username, produk, halaman, verifikasi email, KTP & rekening, pixel).
- **Keamanan**: CSP di halaman toko (hash + `strict-dynamic`, pixel iklan tetap jalan setelah persetujuan), editor lama/pelanggan/domain/upload hanya pemilik & admin toko (kasir POS ditolak), rate limit login per IP, kirim ulang email & checkout lama; paket keamanan Laravel/Guzzle/Symfony/CommonMark diperbarui (`composer audit` bersih).
- **UI HP**: audit otomatis 360 px — tidak ada scroll ke samping, semua tombol ≥ 44 px, input ≥ 16 px (iPhone tidak zoom); formulir KTP & rekening bisa dibuka langsung dan tetap tersedia walau dompet top-up dimatikan; halaman toko belum terbit berbahasa Indonesia; warna tombol tema Ocean/Sunset lebih kontras.
- **Produksi**: `GET /api/health`, worker queue PM2 `hellom-queue` (`QUEUE_CONNECTION=database`), heartbeat scheduler, index order/ledger, backup harian `deploy/backup.sh`. Lihat `docs/DEPLOY.md` §3c.
- **Tes**: perjalanan lengkap di browser `backend/tests/e2e` (16/16) + checklist uji manual HP `docs/TESTING_HELLOM_PAGE.md`.
- **Setelah konfirmasi pemilik**: mode gelap dashboard (Terang/Gelap/Ikuti sistem, di menu samping); KTP & rekening pindah ke tab Saldo Hellom Page (bisa ganti rekening); captcha Cloudflare Turnstile untuk checkout berulang; gambar halaman toko memakai `srcset` (salinan 480/960 px); teks abu-abu dashboard lebih kontras.

### Hellom Page — builder & halaman publik (Fase 4)
- **Halaman toko dirender server** (`hellomspace.com/{username}` dan `/{username}/{produk}`): HTML ringan dengan CSS inline, meta WhatsApp/Facebook (Open Graph), tombol bagikan (salin link, WhatsApp, QR), laporkan, halaman 404/toko nonaktif. Lighthouse mobile 100/100/100/100. **Butuh perubahan Nginx** (lihat `docs/DEPLOY.md` §3b).
- **Editor**: draft tersimpan otomatis dan tidak mengubah halaman tayang sampai *Terbitkan*; riwayat versi & kembalikan; pratinjau asli (HP/Desktop); duplikat & sembunyikan blok; blok baru Profil, Katalog Produk, Galeri; 5 template; tema (huruf, bentuk & gaya tombol); upload gambar jadi WebP (bukan lagi base64 di halaman).
- **Username toko** bisa diganti (kata terlarang ditolak, alamat lama otomatis diarahkan). **Multi-halaman**: 1 halaman gratis, lebih dengan paket yang kuotanya diatur super admin (`Maksimal halaman` di form paket).
- **Iklan**: Meta Pixel, GA4, Google Ads, TikTok per toko + Meta Conversions API; Purchase dikirim sekali per pesanan; sumber iklan (UTM) tercatat di pesanan. Tab **Statistik** (kunjungan, sumber, klik, konversi per produk) dan **Pengaturan**.
- Tombol "AI" editor (bukan AI sungguhan) diganti Template; langganan yang dibayar saldo uji coba dicabut (`billing:revoke-subscription`); produk fisik yang belum dibayar kedaluwarsa 6 jam.

### Hellom Page — jualan & produk digital (Fase 3)
- **Produk** (tab *Produk*): Google Drive, upload file (maks 10 MB, disimpan privat), link/akses, produk fisik (ongkir gratis/tetap/manual), jasa; gambar otomatis WebP; harga coret, stok, pertanyaan checkout. Link Drive disimpan terenkripsi dan tidak pernah tampil ke publik.
- **Checkout pembeli** `/beli/{id}` tanpa login: mobile-first, saran salah ketik email, pilih QRIS atau VA/e-wallet, kupon, alamat kirim; stok & kuota kupon dipesan saat checkout dan dikembalikan bila kedaluwarsa.
- **Halaman akses** `/akses/{token}` (link di email): buka produk dengan batas buka/unduh & masa berlaku, catatan penjual, kirim ulang email; **Cek pesanan** `/cek-pesanan`.
- **Pesanan** (tab *Pesanan*): filter, detail rincian uang, tandai terkirim (resi), selesai (jasa), refund (dipotong dari saldo, ditransfer tim Hellom), pembeli + export Excel; **Kupon**.
- **Kepercayaan**: verifikasi email (wajib sebelum tarik dana), lencana *Penjual Terverifikasi*, halaman kebijakan `/kebijakan/*`, tombol *Laporkan*; super admin *Moderasi Toko* (laporan, nonaktifkan toko/produk, tahan saldo) dan antrean *Refund*.
- Overview penjual memakai data penjualan asli (badge "+12%" palsu dihapus). Email pembeli kini berisi invoice + link akses, bukan link file mentah.
- `wallet:clean-mock-topups` membersihkan saldo uji coba; kode status iPaymu dicocokkan dengan dokumentasi resmi.
- Pesan 422 di dashboard kini menampilkan error field pertama (bukan "… (and N more errors)").

### Hellom Page — uang penjualan (Fase 2, [docs/AUDIT_LANDING_BUILDER.md](docs/AUDIT_LANDING_BUILDER.md))
- **Pembayaran diverifikasi ke gateway**: order landing hanya lunas bila status **dan nominal** cocok di API gateway; webhook ganda tidak mengkredit dua kali; tanda tangan DOKU dicek; semua webhook dicatat mentah di `payment_webhook_logs`.
- **Status order** `pending → paid → fulfilled` (+ `expired/failed/refunded`) dengan transisi terkunci; order kedaluwarsa 24 jam; rekonsiliasi otomatis tiap 5 menit; item order di-snapshot.
- **Saldo Penjualan** baru (tab *Saldo* di Hellom Page): Tertahan / Tersedia / Diproses / Ditarik, rincian harga–biaya–bersih per penjualan, dari ledger append-only (`balance:reconcile`).
- **Penarikan**: min Rp50.000, nama rekening harus sama dengan KTP, ganti rekening = tahan 24 jam + email, SLA 1×24 jam, email tiap status, gagal = saldo kembali. Saldo top-up tidak bisa ditarik lagi.
- **Super admin → Keuangan Penjual**: ringkasan, antrean penarikan dengan penanda SLA (+ bukti transfer), riwayat webhook, rekonsiliasi, pengaturan biaya & masa tahan, export Excel.
- Halaman status pesanan pembeli `/pesanan/{ref}`.

### Keamanan
- **Penarikan dana hanya disetujui super admin.** Sebelumnya pemilik tenant bisa menyetujui (dan memicu payout Xendit) penarikannya sendiri; antrean super admin juga hanya melihat organisasinya sendiri. Kini lintas organisasi dan hanya `super_admin`.
- **Endpoint billing mock dimatikan** kecuali `BILLING_MOCK_ENABLED=true` (sebelumnya siapa pun bisa menambah saldo / mengaktifkan langganan gratis; UI juga memanggil top-up mock saat gateway belum siap).
- **Webhook Xendit** menolak token default `dev_*`; default token dev dihapus dari config.
- **Kebocoran data antar-tenant ditutup**: dashboard Customer Experience POS menampilkan produk semua restoran; paket Space, pre-order reservasi dan hadiah loyalti menerima produk tenant lain; status pesanan publik bisa dienumerasi lewat nomor order berurutan (kini wajib token meja).
- **Suspend akun efektif**: token dicabut, login/SSO/API ditolak, role asli dipulihkan saat reactivate.
- **Rate limit** pada auth dan endpoint publik yang menulis data.
- **Socket.IO terautentikasi**: token HMAC berumur pendek + room privat; notifikasi admin tidak lagi di-broadcast ke semua socket.
- Secret realtime yang ter-commit diganti placeholder (**rotasi di server wajib**).

### Diperbaiki (bug)
- **POS — poin & order (Fase 2B, [docs/AUDIT_POS.md](docs/AUDIT_POS.md))**: poin bisa masuk dua kali (selesai + bayar) dan diberikan sebelum dibayar; status bisa mundur (`completed → new → completed` = poin lagi); poin/stok tidak kembali saat batal; member tidak ditemukan di outlet kedua (scope slug utama vs outlet); `08…`/`628…`/`+62…` dianggap orang berbeda; reward bisa dipakai tanpa memenuhi ambang; laporan menghitung order *selesai* bukan *lunas*; bayar langsung menandai *selesai*; nomor order global 3 digit bisa bentrok; self-order tanpa add-on, tanpa cek jam/ketersediaan saat kirim, tanpa batas spam per meja; link toko memakai meja sungguhan.
- **POS → Pesanan**: ubah status pesanan selalu gagal 422 (body salah format).
- **POS → Laporan**: ekspor Excel selalu gagal dan mengabaikan rentang tanggal.
- **POS → Pesanan**: filter status hanya diterapkan pada 100 pesanan terbaru.
- **Admin**: pencarian/paginasi pengguna & organisasi, filter undangan, batas checkout manual, dan filter notifikasi tidak terkirim ke server.
- **Admin → Buat undangan** memanggil endpoint yang salah (kini undangan bertoken dengan link register).
- **Kirim email tes** (Pengaturan Email & Brand) selalu gagal validasi.
- **System Health** crash dan **kartu keuangan Dashboard** selalu 0 — kini memakai angka platform.
- **Admin → Dashboard**: grafik *User Growth* sebelumnya berisi angka karangan dan pilihan rentang tidak berfungsi; kini menampilkan pendaftaran pengguna & organisasi harian asli (7/30/90 hari).
- **POS → Staf**: ekspor CSV selalu mengambil outlet utama; kini mengikuti outlet aktif.
- **Link email undangan** mengarah ke `localhost` setelah `config:cache` (kini `FRONTEND_URL`/`APP_URL`).
- **Pembayaran produk digital via iPaymu tidak pernah tercatat lunas**: URL notifikasi tidak membawa callback token sehingga webhook ditolak.
- **Halaman kembali setelah bayar** DOKU/iPaymu mengarah ke path lama `/hellom/dashboard/...`; Xendit tidak mengembalikan pembeli ke Hellom (langganan, top-up, produk).
- **Mode checkout dari admin diabaikan untuk langganan**: bila transfer manual aktif, langganan selalu dipaksa manual walau admin memilih *Otomatis*. Kini satu `PaymentPolicy` untuk langganan & produk (manual hanya cadangan saat gateway belum siap).
- **Billing**: akses paket tahunan tidak pernah berakhir; pembelian tahunan/lifetime via saldo wallet atau Xendit tercatat 1 bulan; webhook lama yang diputar ulang bisa menghidupkan akses kedaluwarsa; bulanan dengan auto-renew mati tidak pernah berakhir.

### Ditambahkan
- **Aplikasi unggulan terpisah dari katalog produk**: `/aplikasi` kini etalase khusus aplikasi SaaS unggulan (Kasir POS, Landing Page Builder) dengan banner besar desktop/HP yang diunggah super admin (Produk › edit › *Aplikasi unggulan*, bisa ditautkan ke aplikasi bawaan). Produk bertanda unggulan tidak lagi muncul di `/produk`. Endpoint `GET /public/flagship-apps`; migration `2026_09_29_000003_add_flagship_fields_to_digital_products` (POS & LPB ditandai otomatis bila slug-nya ada).
- **Tombol bagikan** di aplikasi unggulan (kartu, halaman detail, halaman katalog) dan kartu `/produk`: WhatsApp, Facebook, X, Telegram, LinkedIn, salin link, serta menu bagikan bawaan HP. Link produk (`/produk?produk={slug}`) langsung menggulir dan menyorot produknya.
- **Satu mesin order untuk kasir & self-order** ([docs/ALUR_ORDER.md](docs/ALUR_ORDER.md)): `OrderService` + `PricingService` (add-on, diskon, service charge, pajak, pembulatan per outlet; total dari server, pratinjau `POST /pos/orders/preview`), status baku dengan label Indonesia dan transisi maju saja, bayar terpisah dari status dapur, batal (alasan wajib) dan refund (owner/supervisor), event `OrderCreated/Confirmed/StatusChanged/Paid/Voided/Refunded` untuk stok, poin, socket, audit log dan deteksi kecurangan.
- **Self-order**: QR acak 24 karakter terikat ke outlet + ganti QR + cetak massal; menu mengikuti outlet (add-on, ketersediaan, jam buka, pesan ramah saat tutup); perubahan harga/stok dilaporkan per item (409) dan keranjang diperbarui; limiter per token+IP dan batas pesanan menunggu per meja; konfirmasi kasir bisa dimatikan per outlet; status pesanan realtime untuk tamu; **tagihan meja** (pesanan QR & kasir satu tagihan, bayar sekaligus); metode bayar & branding milik outlet/tenant.
- **Realtime pesanan**: room `tenant:{slug}:outlet:{id}` (bunyi + badge di POS) dan `table:{id}` (tamu), polling tetap sebagai cadangan.
- **Member & poin**: member per organisasi dengan nomor HP ternormalisasi (628…), ledger `member_point_transactions` (saldo awal dimigrasi, lock saat tukar, FIFO, kedaluwarsa), tukar poin dengan konfirmasi nama (antarmuka OTP WhatsApp disiapkan), aturan per tenant (nilai poin, min/maks tukar, masa berlaku), ubah poin manual ber-audit, gabung member duplikat manual, sinyal kecurangan, halaman member owner (cari, filter outlet, urutan paling aktif, riwayat poin & pesanan, ekspor Excel).
- Command `pos:points reconcile|expire` (expire terjadwal harian) dan `pos:report duplicates|orphans|weak-tokens` (read-only).
- Tes POS di MySQL (`phpunit.pos.xml`, 16 tes): isolasi QR antar outlet/tenant, total kasir = self-order, normalisasi nomor per tenant, poin setelah lunas & ditarik saat refund, dua penukaran bersamaan tidak membuat saldo negatif.
- Pengaturan pembayaran dari dashboard super admin: channel VA/QRIS iPaymu yang tampil di checkout, tombol checkout tanpa login, URL webhook lengkap beserta petunjuk pendaftaran.
- **Checkout tamu produk digital**: produk berbayar milik platform bisa dibeli tanpa login (email wajib, no. HP opsional). Setelah lunas, pembeli menerima email berisi link sekali pakai yang langsung membuka produk di dashboard, plus password untuk akun baru. Halaman status pembayaran publik dengan polling dan kirim ulang email.
- Fondasi penjualan: `Plan::accessEndsAt()`, `EntitlementService`, `Entitlement::effectiveStatus()`, masa tenggang `BILLING_GRACE_DAYS`, command `hellom:billing:expire-subscriptions` (per jam) dan `hellom:billing:backfill-entitlement-ends` (laporan dulu, `--force` untuk menulis). Paket **lifetime** (bayar sekali) didukung penuh.
- Artefak deploy: `deploy/deploy.sh`, `deploy/ecosystem.config.js` (PM2), contoh Nginx (termasuk proxy `/socket.io`), `deploy/crontab.example`.
- `.env.example` lengkap untuk backend, frontend, dan realtime.
- Dokumentasi: `README.md`, `docs/ARCHITECTURE.md`, `docs/DEPLOY.md`, `docs/AUDIT.md`, `docs/proposals/billing-foundation.md`, `CLAUDE.md`.
- Unit test untuk periode akses dan status entitlement.

### Diubah
- **Situs publik dipecah jadi halaman terpisah** (`/`, `/tentang`, `/layanan`, `/aplikasi` + detail, `/produk`, `/portofolio`, `/wawasan` + artikel, `/kontak`) dengan navbar/footer tetap, transisi curtain, katalog aplikasi baru, dan halaman login yang lebih bersih. URL lama di-redirect. JS awal Beranda ±139 KB gzip (sebelumnya ±150 KB), font Google tidak lagi memblokir render (FCP lokal 3,1 s → 0,5 s), foto hero AVIF/WebP (580 KB → 10–17 KB).
- UI resmi dipindah `plans/UI` → **`frontend/`**; UI Blade lama dipensiunkan (Laravel kini hanya API + shell SPA).
- `routes/api.php` dipecah per modul (`routes/api/*.php`), route identik.
- `hellomApi.ts` (1.716 baris) dipecah ke `frontend/src/services/api/*` (export publik identik).
- Satu base controller API; method controller yang tidak ter-route dihapus.
- `BillingController` (2.737 baris) dipecah menjadi 6 controller di `Api/V1/Hellom/Billing/` + trait bersama + `Services/Billing/CheckoutNotifier`; route & perilaku identik.
- Klien API `lib/pos/{posApi,staffApi}.ts` disatukan ke `services/api/{posCustomer,posStaff}.ts`.
- TypeScript: `@types/react` dipasang (sebelumnya React tidak dicek tipe sama sekali); error `tsc` 314 → **0**; respons API bertipe sesuai payload backend; **`strict: true`** aktif.
- Performa: route SPA di-lazy-load, grafik dipisah → JS awal ~2,2 MB → ~500 KB.
- Artisan tidak lagi butuh koneksi DB saat boot; `route:cache` kini berhasil.

### Dihapus / diarsipkan (`_archive/`)
- UI Blade lama (±50 controller, ±120 view, 6 file route, middleware dummy auth/tenancy, aset Vite backend, tes Blade, skrip kiosk).
- Skrip debug di root `backend/`, kelas/command tak terpakai, model `LoyaltySetting`/`PointTransaction` (tabelnya tetap ada), controller Breeze tanpa route, build nyasar `plans/backend/`, desain referensi Figma (→ `docs/design-reference/`).
- Dependensi: `doctrine/dbal`, `laravel/breeze`, `laravel/sail`; frontend −201 paket (`@google/genai`, `better-sqlite3`, `express`, `motion`, dll.) dan dev server Express.
- Log debug di konsol browser yang mencetak data member dan pesanan pelanggan.

### Catatan upgrade
- **Fase 2B**: backup DB → catat `SUM(redeemable_points)` → `php artisan migrate` (2 migration aditif) → `php artisan pos:points reconcile` → `pos:report duplicates|orphans|weak-tokens` → restart `hellom-realtime` (server.js berubah) → build frontend. Langkah & SQL verifikasi: [docs/ALUR_ORDER.md §9](docs/ALUR_ORDER.md#9-migrasi-data--verifikasi). Endpoint `POST /pos/orders/{id}/payment` tidak lagi men-set status `completed`.
- Build frontend dari `frontend/` (`npm ci --include=dev && npm run build`).
- Migration baru (aditif): `users.role_before_suspension`, `users.pending_guest_credentials`, kolom checkout tamu di `product_purchases`, tabel `login_links`.
- Jalankan laporan `hellom:billing:backfill-entitlement-ends` sebelum memutuskan `--force`.
- Pastikan cron `schedule:run` aktif dan `REALTIME_REQUIRE_AUTH=true`.
