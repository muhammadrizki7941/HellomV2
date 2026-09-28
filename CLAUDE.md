# CLAUDE.md — SelfOrderResto (Hellom)

Aplikasi kasir (POS) + self-order restoran dan Landing Page Builder, bagian dari platform **Hellom** (hellomspace.com). Dijual sebagai SaaS multi-tenant: langganan bulanan/tahunan dan paket **lifetime** (bayar sekali, akses selamanya — tanpa license key/self-hosted).

Baca dulu: [README.md](README.md) (setup), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (tenant, role, realtime, billing), [docs/DEPLOY.md](docs/DEPLOY.md), [docs/AUDIT.md](docs/AUDIT.md) (temuan & status), [CHANGELOG.md](CHANGELOG.md).

## Layout
| Folder | Isi |
|---|---|
| `backend/` | Laravel 12 (PHP ^8.2, MySQL). **API saja**: `routes/api.php` → `routes/api/*.php` (prefix `/api/v1/hellom`). `routes/web.php` = `/media` + fallback SPA. Satu-satunya view: `resources/views/emails`. |
| `frontend/` | **UI satu-satunya**: React 19 + Vite 6 + TS + Tailwind 4, react-router 7. Build → `backend/public/hellom/` (git-ignored). Klien API: `src/services/api/*` (barrel `@/lib/hellomApi`). |
| `realtime/` | Socket.IO (`server.js`, :3001), PM2 `hellom-realtime`; token HMAC dari `GET /realtime/token`. |
| `deploy/` | `deploy.sh`, `ecosystem.config.js`, contoh Nginx, crontab. |
| `docs/` | ARCHITECTURE, DEPLOY, AUDIT, proposals/, notes/, design-reference/. |
| `_archive/` | Kode lama yang dipensiunkan (Blade UI, skrip debug, kelas tak terpakai) + README alasan. Jangan dipakai. |

## Perintah
```bash
# backend (butuh MySQL untuk migrate/tes; artisan lain jalan tanpa DB)
composer install
php artisan migrate                       # JANGAN migrate:fresh / db:wipe
php artisan route:list && php artisan config:cache && php artisan config:clear && php artisan route:cache && php artisan route:clear
php artisan serve --host=127.0.0.1 --port=8000
php artisan schedule:work                 # lokal; produksi: cron schedule:run

# frontend
npm install && npm run dev                # vite :3000
npx tsc --noEmit                          # harus 0 error (tsconfig strict: true)
npm run build                             # → ../backend/public/hellom

# realtime
node server.js                            # env: PORT, HOST, REALTIME_SERVER_SECRET, REALTIME_ALLOWED_ORIGINS, REALTIME_REQUIRE_AUTH
```
Tes PHPUnit fitur gagal di sqlite (migration memakai `information_schema` MySQL); tes unit jalan. Tes order/member POS: `php vendor/bin/phpunit -c phpunit.pos.xml` (MySQL `hellom_pos_test`, lihat [docs/ALUR_ORDER.md](docs/ALUR_ORDER.md) §10). Verifikasi backend dengan skrip `tinker` di `backend/storage/app/*.php` (git-ignored) dalam `DB::beginTransaction()/rollBack()`. **Hapus `bootstrap/cache/config.php` sebelum `php artisan test`.**

## Aturan domain yang wajib diingat
- **Isolasi tenant manual**: global scope `tenant` tidak aktif di API token. Setiap query POS filter `tenant_id` (`posTenantSlug` dari `InjectPosContext`, atau `OutletService::tenantSlugs($org)` untuk level organisasi). Endpoint publik mencari lewat token (`dining_tables.public_id`), bukan ID berurutan.
- **Akses berbayar** hanya lewat `App\Services\Billing\EntitlementService`; periode dari `Plan::accessEndsAt()`; cek akses dengan `Entitlement::allowsAccess()`.
- **Penarikan dana**: tinjauan/persetujuan hanya `super_admin`.
- Role: `users.role` (`super_admin`, `admin`, `tenant_admin`, `cashier`, `member`, `suspended`) + pivot `organization_user.role` (`owner`, `admin`, `member`, `cashier`).
- Respons API: `BaseApiController::ok()/fail()` (atau `success()/error()` di POS) — amplop `{ success, message, data, error }`.
- Validasi masih di controller (`$request->validate`) setelah cek otorisasi; kalau memakai Form Request, pindahkan otorisasi ke `authorize()` agar urutan 401/403 → 422 sama.
- Frontend: tipe respons API mengikuti payload backend; jangan menambah error `tsc`.

## Hellom Page (Landing Builder + toko online) — konteks proyek berjalan
Audit & status: [docs/AUDIT_LANDING_BUILDER.md](docs/AUDIT_LANDING_BUILDER.md). Dikerjakan per fase (1 audit → 2 uang → 3 produk → 4 builder/publik → 5 poles); **berhenti & minta konfirmasi setelah tiap fase**.
- Identitas penjual di modul ini = `organization_id` (halaman, blok, order, saldo, KYC) — dikonfirmasi pemilik (Q1).
- Keputusan pemilik (Q2–Q7): saldo **penjualan** terpisah dari saldo **top-up** (top-up tidak bisa ditarik); 1 halaman gratis per toko, halaman tambahan = langganan berbayar yang harganya diatur super admin (Fase 4); data produksi dipakai sungguhan (cek mock); gateway aktif = **iPaymu**; hotfix boleh; biaya gateway ditanggung Hellom dari biaya layanan: `platform_fee = max(harga×% + flat, biaya_gateway + margin_min)`.
- Uang: BIGINT rupiah; semua perubahan saldo dalam `DB::transaction(…, 3)` + `lockForUpdate`. Semua pembayaran pembeli masuk ke akun gateway Hellom; penjual punya **Saldo Penjualan** (`seller_balances` = cache, sumber kebenaran `seller_balance_ledger` append-only; `balance:reconcile`).
- Alur order: `LandingSaleService::createPendingOrder` → gateway lewat `App\Services\Payments\GatewayRegistry` (adapter `PaymentGateway`) → webhook/return/rekonsiliasi memanggil `SellerFinance\LandingPaymentService::handleNotification`, yang **selalu cek status + nominal ke API gateway** sebelum `settle()` (notifikasi iPaymu tidak bertanda tangan). Transisi status di `LandingPageOrder::TRANSITIONS`. Halaman `/pesanan/{ref}` hanya polling.
- Penarikan: `SellerFinance\WithdrawalService` (min/biaya/SLA/mode di `FinanceSettings`, SystemSetting `seller_finance_settings`); nama rekening harus sama dengan KYC; ganti rekening = tahan 24 jam + email. iPaymu tidak punya payout → mode manual (super admin transfer + unggah bukti di `/admin/keuangan-penjual`).
- Jadwal: `landing:orders reconcile|expire|release|sla`, `balance:reconcile` (routes/console.php). Tes: `tests/Landing` via `phpunit.pos.xml` (DB `hellom_pos_test`).
- Halaman publik saat ini SPA (`PublicPage.tsx`, route `/:organizationSlug`) di origin yang sama dengan dashboard → konten penjual **tidak boleh** dirender sebagai HTML mentah.
- Aturan brief: teks UI Bahasa Indonesia santai-profesional; FormData PUT/PATCH = POST + `_method`; boolean FormData pakai `filter_var(..., FILTER_VALIDATE_BOOLEAN)`; migration non-destruktif dengan `down()`; jangan `storage:link`; setelah perubahan instruksikan `php artisan optimize:clear`; jangan sentuh POS kecuali kode bersama (jelaskan dampak dulu).

## Aturan kerja
1. Jangan membaca, menampilkan, atau meng-commit `.env`, kredensial, API key, atau data pelanggan.
2. Jangan mengubah migration yang sudah dijalankan; buat migration baru. Jangan jalankan perintah penghapus data (`migrate:fresh`, `db:wipe`, `truncate`, `tenants:seed-demo --truncate`, `orders:purge-legacy --force`).
3. Sebelum menghapus file, buktikan tidak dipakai; kalau ragu pindahkan ke `_archive/` dan catat alasannya di `_archive/README.md`.
4. Setelah langkah besar: `composer install`, `route:list`, `config:cache`, `npm run build`, `npx tsc --noEmit`, tes. Perbaiki dulu kalau gagal.
5. Jangan menambah fitur atau mengubah logika bisnis/tampilan kecuali bug yang jelas — dan tanyakan dulu.
6. Keputusan ambigu → tanya pemilik proyek.
7. Commit kecil, conventional commits. **Stage file secara eksplisit** — pemilik sering punya perubahan belum di-commit (mis. `frontend/src/components/landing/HellomspaceLanding.tsx`); jangan `git add <folder>`.
8. Email saat verifikasi: tukar `PlatformMailService` dengan versi no-op di container; jangan pernah mengirim email sungguhan.
