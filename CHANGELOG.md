# Changelog

## [Unreleased] — Refactor & hardening (branch `refactor/cleanup`, 2026-09-27 … 2026-09-28)

Audit lengkap dan status per temuan: [docs/AUDIT.md](docs/AUDIT.md). Langkah deploy khusus rilis ini: [docs/DEPLOY.md §3](docs/DEPLOY.md#3-catatan-khusus-rilis-refactor-branch-refactorcleanup).

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
- **Billing**: akses paket tahunan tidak pernah berakhir; pembelian tahunan/lifetime via saldo wallet atau Xendit tercatat 1 bulan; webhook lama yang diputar ulang bisa menghidupkan akses kedaluwarsa; bulanan dengan auto-renew mati tidak pernah berakhir.

### Ditambahkan
- **Checkout tamu produk digital**: produk berbayar milik platform bisa dibeli tanpa login (email wajib, no. HP opsional). Setelah lunas, pembeli menerima email berisi link sekali pakai yang langsung membuka produk di dashboard, plus password untuk akun baru. Halaman status pembayaran publik dengan polling dan kirim ulang email.
- Fondasi penjualan: `Plan::accessEndsAt()`, `EntitlementService`, `Entitlement::effectiveStatus()`, masa tenggang `BILLING_GRACE_DAYS`, command `hellom:billing:expire-subscriptions` (per jam) dan `hellom:billing:backfill-entitlement-ends` (laporan dulu, `--force` untuk menulis). Paket **lifetime** (bayar sekali) didukung penuh.
- Artefak deploy: `deploy/deploy.sh`, `deploy/ecosystem.config.js` (PM2), contoh Nginx (termasuk proxy `/socket.io`), `deploy/crontab.example`.
- `.env.example` lengkap untuk backend, frontend, dan realtime.
- Dokumentasi: `README.md`, `docs/ARCHITECTURE.md`, `docs/DEPLOY.md`, `docs/AUDIT.md`, `docs/proposals/billing-foundation.md`, `CLAUDE.md`.
- Unit test untuk periode akses dan status entitlement.

### Diubah
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
- Build frontend dari `frontend/` (`npm ci --include=dev && npm run build`).
- Migration baru (aditif): `users.role_before_suspension`, `users.pending_guest_credentials`, kolom checkout tamu di `product_purchases`, tabel `login_links`.
- Jalankan laporan `hellom:billing:backfill-entitlement-ends` sebelum memutuskan `--force`.
- Pastikan cron `schedule:run` aktif dan `REALTIME_REQUIRE_AUTH=true`.
