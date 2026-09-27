# AUDIT — SelfOrderResto / Hellom (Fase 1)

> Tanggal audit: 2026-09-27 · Branch: `main` @ `a6d0811` · Mode: read-only
> Catatan: folder UI sebenarnya bernama `plans/UI` (huruf kecil), bukan `PLANS/UI`.
> Tidak ada file `.env` yang dibaca. Nilai secret yang ditemukan **tidak** disalin ke dokumen ini.

---

## 0. Ringkasan eksekutif

| # | Temuan paling penting | Prioritas |
|---|---|---|
| 1 | Endpoint billing **mock** aktif di production: user login mana pun bisa **menambah saldo wallet gratis** (`/billing/wallet/topup-mock`) dan **mengaktifkan langganan tanpa bayar** (`/billing/checkout-confirm-mock`, `/billing/subscriptions/{id}/renew-mock`). UI sendiri memanggil `topup-mock` saat gateway belum siap. | **KRITIS** |
| 2 | **Owner tenant bisa menyetujui penarikan dananya sendiri** (`/wallet/withdrawals/{id}/approve`, `mark-paid`): cek role hanya di pivot organisasi (owner/admin). Bila Xendit aktif, approve memicu **disbursement nyata**. Kombinasi #1 + #2 = jalur pencairan uang fiktif (dibatasi hanya oleh KYC). | **KRITIS** |
| 3 | Secret production (`REALTIME_SERVER_SECRET`) ter-commit di `DEPLOYMENT.MD` sejak commit `0501c1f` dan ada di riwayat git. | **Tinggi** (rotasi) |
| 4 | Webhook Xendit menerima token default publik `dev_xendit_callback_token` bila token belum diset (iPaymu/DOKU sudah menolak nilai dev). | **Tinggi** |
| 5 | Realtime (Socket.IO) tanpa autentikasi: klien bisa `join` room `tenant_*` mana pun; `admin.notification.created` di-broadcast ke **semua** socket. | **Tinggi** |
| 6 | Di production, Nginx hanya meneruskan `/api`, `/storage`, `/media` ke Laravel, sehingga **seluruh UI Blade lama (±50 controller, ±120 view, 6 file route) tidak terjangkau**. Kode mati besar, dan di lokal (`php artisan serve`) Blade justru menang atas SPA untuk URL yang sama. | Sedang |
| 7 | Masa berlaku langganan hanya ditegakkan oleh scheduler (`entitlements.ends_at` selalu `null`); cron `schedule:run` tidak terdokumentasi di deploy. | **Tinggi** (verifikasi VPS) |
| 8 | `php artisan` apa pun gagal tanpa DB (query di `AppServiceProvider::boot`); `tsc --noEmit` = **314 error**; tes PHPUnit tidak bisa jalan di sqlite. | Sedang |

---

## 1. Peta proyek

### 1.1 Struktur folder (yang ter-commit)

```
SelfOrderResto/                     (remote: github.com/muhammadrizki7941/HellomV2)
├── backend/                        Laravel 12 (PHP ^8.2) — API + UI Blade lama
│   ├── app/
│   │   ├── Console/Commands/       11 command (billing auto-renew, settlement, analytics, test:*)
│   │   ├── Http/Controllers/
│   │   │   ├── Api/V1/Hellom/      ★ API utama SPA (28 controller)
│   │   │   ├── Api/V1/Hellom/Pos/  ★ API POS (12 controller)
│   │   │   ├── Api/V1/Consumer/    ★ API konsumen (produk digital, notifikasi, onboarding)
│   │   │   ├── Api/V1/Public/      ★ katalog produk publik
│   │   │   ├── Admin/              campuran: 3 dipakai API (DigitalProduct, ProductPurchase, OwnerNotification), sisanya Blade lama
│   │   │   ├── Auth/, Cashier/, Customer/, Marketing/, Public/, ProfileController   — Blade lama (Breeze)
│   │   ├── Http/Middleware/
│   │   │   ├── Api/                ★ AuthenticateApiToken, EnsureAppEntitlement, EnsureSuperAdmin, InjectPosContext
│   │   │   ├── AuthZ/, Tenancy/, Dev/   — "foundation/dummy" (auth & tenant tanpa DB), untuk Blade lama
│   │   ├── Models/                 81 model
│   │   ├── Services/               Hellom/ (gateway, billing, mail, Gemini), OutletService, NotificationService, Realtime/, Payments/ (Midtrans QRIS lama), …
│   │   ├── Policies/               LandingPagePolicy (dipakai), OrganizationPolicy (tidak terdaftar)
│   ├── config/                     + payments.php, realtime.php, tenancy.php, dummy_auth.php
│   ├── database/                   131 migration, 9 seeder, 6 factory
│   ├── resources/views/            Blade lama (admin, cashier, customer, marketing, auth) + emails/ (dipakai)
│   ├── resources/js/               app.js/bootstrap.js (Blade) + hellom/api/*.ts (wallet client, hanya punya typecheck script)
│   ├── routes/                     api.php (★), web.php, admin.php, cashier.php, customer.php, auth.php, auth_global.php (tidak di-load), marketing.php, console.php
│   ├── public/hellom/              ← OUTPUT build SPA (index.html + assets di-ignore; ikon/manifest/sw.js ter-commit)
│   ├── check_*.php, test_*.php, update_tenant.php, _debug_request_constants.php, temp_dashboard.html, scripts/*.php   — skrip debug
├── plans/
│   ├── UI/                         ★ React 19 + Vite 6 + TS + Tailwind 4 — UI RESMI (SPA)
│   │   ├── src/                    App.tsx (router), pages/, components/, layouts/, hooks/, lib/ (hellomApi.ts 1.711 baris)
│   │   ├── public/                 manifest.json, sw.js, assets (duplikat dari backend/public/hellom)
│   │   ├── referensi/              export Figma/Make (desain referensi, tidak di-import)
│   │   ├── server.ts               dev server Express+Vite (npm run dev)
│   │   └── *.png                   screenshot
│   └── backend/public/hellom/      ✗ hasil build nyasar (outDir salah), ter-commit
├── realtime/                       Node Socket.IO (server.js, 1 file) — dijalankan PM2 "hellom-realtime"
├── scripts/                        PowerShell/CMD dev lokal (kiosk Chrome/Edge, start realtime) — path lama `Self-OrderMenu`
├── docs/billing-doku-manual-handoff.md
├── DEPLOYMENT.MD, PROGRESS.md, PRODUCT_FEATURE.md, LandingPage.md, STATIC_BRANDING_SETUP.md, "running this app", ngrok.yml
```

### 1.2 Entry point

| Layer | Entry | Catatan |
|---|---|---|
| Backend | `backend/public/index.php` → `bootstrap/app.php` | Routing: `web.php` (+ require admin/cashier/customer/auth/marketing), `api.php`, `console.php`. `routes/auth_global.php` **tidak pernah di-load**. |
| Frontend | `plans/UI/index.html` → `src/main.tsx` → `src/App.tsx` (react-router v7) | Build: `vite build` → `outDir: ../../backend/public/hellom`, `base: '/'`. API base dari `VITE_HELLOM_API_BASE` (fallback hardcode `http://127.0.0.1:8000/api/v1/hellom`). |
| Realtime | `realtime/server.js` (port 3001) | `POST /emit` (header `X-RT-SECRET`) → `io.emit`/room. Klien memuat `/socket.io/socket.io.js` dari server realtime. |
| Scheduler | `routes/console.php` | `notifications:check-expiry` (harian), `hellom:billing:auto-renew-wallet` (per jam), `hellom:wallet:release-pending-settlements`. |

### 1.3 Alur request

```
Browser (SPA di hellomspace.com)
  │  fetch  Authorization: Bearer <token>  (+ X-Outlet-Id untuk POS)
  ▼
Nginx ── /api/* ──► Laravel public/index.php
  │                   └─ routes/api.php  prefix v1/hellom
  │                        ├─ AuthenticateApiToken   (sha256 token di tabel api_tokens)
  │                        ├─ canUseApp:<slug>       (entitlement org aktif? inject posTenantSlug)
  │                        ├─ InjectPosContext        (kunci outlet: kasir → outlet PosStaff; owner → X-Outlet-Id)
  │                        └─ superAdmin              (users.role === 'super_admin')
  │                   Controller → Eloquent (filter manual where tenant_id / organization_id) → MySQL
  │                   (sebagian) NotificationService → RealtimeClient POST http://127.0.0.1:3001/emit
  ├─ /storage, /media ─► file statis storage/app/public
  └─ selain itu ──► backend/public/hellom/index.html (SPA fallback)

Realtime: Laravel ──HTTP /emit──► realtime/server.js ──socket.io──► browser (hanya NotificationBell admin)
POS order list & tracking order pelanggan memakai POLLING (setInterval), bukan socket.
```

---

## 2. Peta fitur

### 2.1 Auth & role
- **Auth SPA**: token opak buatan sendiri (`api_tokens.token_hash` sha256, `expires_at`), bukan Sanctum. Disimpan di `localStorage` (`hellom_token`). Endpoint: `/auth/register|login|forgot-password|reset-password|sso-login|me|logout`.
- **Dua dimensi role** (membingungkan, lihat temuan N-3):
  - `users.role` (platform): `super_admin`, `admin` (Blade lama; di API bisa melihat & *switch* ke semua organisasi), `tenant_admin`, `cashier`, `member`, `suspended`.
  - `organization_user.role` (pivot): `owner`, `admin`, `member`, `cashier`.
- Pemetaan ke role bisnis:
  | Role bisnis | Implementasi |
  |---|---|
  | Super Admin | `users.role = super_admin` → middleware `superAdmin`, `Gate::before` bypass |
  | POS Admin | pivot `owner`/`admin` pada organisasi → bebas pilih outlet via `X-Outlet-Id` |
  | Kasir | pivot non-manager + `pos_staff.linked_user_id` aktif → dikunci ke 1 outlet (`InjectPosContext`) |
  | Self-Order Kiosk | **tanpa login**: endpoint publik `/pos/customer/*` dengan `table_token` (= `dining_tables.public_id`) |
  | Member loyalti POS | tanpa password: lookup nomor HP (`/pos/public/members/*`) |
- Auth Blade lama: Breeze (`auth`, `auth:cashier`) + `DummyAuthService` (user plaintext di `config/dummy_auth.php`, `DUMMY_AUTH_ENABLED` default `true`). Tidak terjangkau di production.

### 2.2 Multi-tenant
- **Model**: *shared database, shared schema*. Tenant = `organizations`. Data POS memakai kolom `tenant_id` (string) = `organizations.pos_tenant_slug` atau `outlets.tenant_slug` (per-outlet). Data platform (wallet, billing, landing) memakai `organization_id`.
- **Penentuan tenant**: `users.current_organization_id` (tidak bisa diubah user biasa; `organizations/switch` membatasi ke org aktif, kecuali `users.role = admin`).
- **Isolasi**: filter **manual** di setiap query controller (`where('tenant_id', $tenantSlug)` / `where('organization_id', …)`). Sampel `findOrFail` di semua controller POS sudah difilter dengan benar.
- Global scope `tenant` pada `Order/Product/Category/DiningTable/SitePromotion` memakai `auth()->user()` (guard session web). Di API token guard ini **null**, jadi scope **tidak aktif** di API, dan scope itu memakai slug organisasi (bukan outlet). Menyesatkan pembaca kode; isolasi sepenuhnya bergantung pada disiplin filter manual.
- Multi-outlet: `outlets` per organisasi, kuota `plans.max_outlets` / override super admin.

### 2.3 POS / Kasir (SPA `/pos/*`)
Outlet, pesanan (buat, ubah status, bayar, struk), menu/kategori/produk (+opsi), meja + QR, metode bayar (cash/transfer/QRIS statis/Dana/GoPay deep link), member & loyalti (poin, reward rule), promo & reservasi ruang ("customer experience"), staf (shift, absensi QR, kas buka/tutup, undangan login kasir), laporan + export Excel (openspout). PWA (manifest + sw.js).

### 2.4 Self-order
QR meja → `/customer/order/:tableToken` (SPA) → `GET /pos/customer/menu/{tableToken}` → `POST /pos/customer/order` (harga diambil dari DB, produk divalidasi satu tenant, status `unpaid`, dibayar di kasir) → halaman sukses + polling status. Juga menu per organisasi `/customer/:organizationSlug`, klaim promo, reservasi.

### 2.5 Realtime
Hanya `admin.notification.created` (bell super admin) yang dipakai SPA. Event `order.created/updated` hanya di-emit oleh controller Blade lama (dan dengan `tenant_id` string, sehingga server realtime melakukan broadcast global karena hanya menerima `tenant_id` bertipe number). Konfigurasi Nginx di `DEPLOYMENT.MD` **tidak mem-proxy `/socket.io`**, padahal `VITE_REALTIME_PUBLIC_URL=https://hellomspace.com`. Perlu dicek apakah realtime benar-benar tersambung di production.

### 2.6 Billing: kondisi saat ini
**Katalog & paket**: `app_catalogs` (aplikasi: `pos`, `landing_builder`, …) × `plans` (`type`: `free | subscription | one_time | lifetime`; `billing_cycles` monthly/yearly; `duration_days`; `max_outlets`).

**Langganan (SaaS)**:
- `checkout_intents` → gateway (iPaymu utama; Xendit, DOKU; manual transfer + approve super admin; bayar pakai saldo wallet) → webhook/reconcile → `subscriptions` (`starts_at/ends_at`) + `entitlements` (status `active`, **`ends_at = null`**) → `PosProvisioningService` → `invoices`.
- Perpanjangan: `hellom:billing:auto-renew-wallet` (per jam) memotong saldo wallet atau mengubah entitlement ke `expired`.
- Promo code (`promo_campaigns`), notifikasi & email billing reminder.
- Wallet organisasi (`organization_wallets`, ledger transaksi), top-up via gateway, penarikan dengan KYC (`organization_payout_profiles`), settlement penjualan landing page (komisi platform 5%).

**Sekali beli**:
- `plans.type = one_time/lifetime` ada di skema dan helper periode, tetapi tidak ada alur khusus (tetap lewat subscription/entitlement).
- `digital_products` + `product_purchases`: toko produk digital (file download, doc preview), dibeli user via gateway/manual. Ini bentuk "sekali beli" yang sudah berjalan, tetapi untuk **file**, bukan lisensi aplikasi.

**Yang belum ada**:
- Lisensi aplikasi sekali beli (license key, aktivasi per domain/instalasi, batas outlet/perangkat, masa update/support).
- Distribusi self-hosted/on-premise (installer, pengecekan lisensi, update channel).
- Masa berlaku entitlement yang ditegakkan di request (sekarang bergantung scheduler).
- Konsistensi periode: beberapa jalur aktivasi meng-hardcode `addMonth()` walau plan yearly/`duration_days` (helper periode di `BillingController` ±baris 2600 sudah ada tapi tidak dipakai di semua jalur).
- Proration/upgrade nyata (`pricing/preview-upgrade` ada; perlu dicek), pajak/PPN pada invoice, dunning (retry gagal bayar).

---

## 3. Daftar temuan

Legenda status: `[ ]` belum · `[x]` selesai (diisi di Fase 2)

### 3.1 Keamanan & isolasi tenant

| ID | Prio | Temuan | Lokasi | Usulan |
|---|---|---|---|---|
| S-1 | **Kritis** | Endpoint mock billing tanpa guard environment: `topup-mock` menambah `available_balance` wallet secara langsung; `checkout-intent-mock`/`checkout-confirm-mock`/`renew-mock` mengaktifkan entitlement tanpa pembayaran. UI memanggil `walletTopupMock` bila `!gatewayReady`. | `routes/api.php:170-177`, `BillingController::walletTopupMock` (±1339), `renewSubscriptionMock` (±1690), `checkoutConfirmMock` (±2277); `plans/UI/src/pages/dashboard/Payments.tsx:280` | Daftarkan route mock **hanya** bila `app()->isLocal()` (atau flag `BILLING_MOCK_ENABLED=false` default) + ubah UI agar menampilkan "gateway belum tersedia". **Perubahan logika, butuh izin.** Audit juga ledger `wallet_topup_mock` di DB production. |
| S-2 | **Kritis** | `approveWithdrawal`, `markWithdrawalPaid`, `markWithdrawalFailed`, `rejectWithdrawal`, `adminPayoutQueue` hanya mensyaratkan pivot `owner/admin`, sehingga pemilik tenant bisa menyetujui penarikan sendiri dan memicu payout Xendit. | `WalletController.php` ±417-700 | Pindahkan route admin wallet ke grup `superAdmin`; owner hanya `request`/`cancel`. **Butuh izin.** |
| S-3 | Tinggi | Secret realtime production ter-commit di `DEPLOYMENT.MD` (juga di riwayat git). | `DEPLOYMENT.MD` bagian "backend/.env (production)" | **Rotasi** secret di VPS (`backend/.env` + env PM2 realtime), ganti di dokumen jadi placeholder. Riwayat git tidak perlu di-rewrite bila secret sudah dirotasi. |
| S-4 | Tinggi | Xendit webhook menerima default `dev_xendit_callback_token` bila belum dikonfigurasi (iPaymu & DOKU sudah menolak). Default dev juga ada untuk `MOCK_PAYMENT_WEBHOOK_SECRET`. | `Services/Hellom/XenditSettingsService.php:29`, `config/payments.php` | Tolak nilai `dev_*` seperti iPaymu/DOKU; default config `''`. |
| S-5 | Tinggi | Socket.IO tanpa auth: `join` room `tenant_*` bebas; `admin.notification.created` broadcast global (judul/pesan notifikasi keuangan). CORS realtime `origin: true`. | `realtime/server.js`, `NotificationService::emitCreated` | Handshake dengan token (verifikasi ke Laravel atau JWT bertanda tangan), room per org/`super_admin`, CORS dari env. |
| S-6 | Sedang | User yang di-suspend (`users.role = 'suspended'`) tetap bisa memakai token API; `AuthenticateApiToken` tidak cek status dan token tidak dicabut. Suspend juga menimpa role asli. | `SuperAdminController::suspendUser`, `AuthenticateApiToken` | Kolom status terpisah atau `suspended_at`; cabut token saat suspend; tolak di middleware. |
| S-7 | Sedang | `users.role === 'admin'` (warisan Blade) bisa list & switch ke **semua** organisasi via API. Di-assign oleh seeder (`AdminTenantSeeder`, `HellomAdminSeeder`). | `OrganizationController::index/switch` | Pastikan tidak ada akun `admin` di production atau batasi ke `super_admin`. **Tanya.** |
| S-8 | Sedang | Tidak ada rate limiting pada `/auth/login`, `/auth/forgot-password`, endpoint publik order/member lookup/reservasi. `pos/public/members/lookup` = lookup data member via nomor HP tanpa auth (enumerasi). | `routes/api.php` | `throttle:` per route; batasi field yang dikembalikan lookup. |
| S-9 | Sedang | Global scope `tenant` di model tidak aktif di API (guard `web`) dan memakai slug org, bukan outlet, sehingga memberi rasa aman palsu. | `Models/Order.php:99` dst. | Ganti ke scope berbasis `request()->attributes('posTenantSlug')` atau hapus & dokumentasikan filter manual + tes isolasi. |
| S-10 | Rendah | `OrderController` (Hellom) berisi method tidak ter-route; `updateStatus` di dalamnya memakai `Order::findOrFail` tanpa filter tenant. Kalau suatu saat di-route, jadi IDOR. `webhookMockPayment` juga tidak ter-route. | `Api/V1/Hellom/OrderController.php:57`, `BillingController::webhookMockPayment` | Hapus method mati. |
| S-11 | Rendah | `vite.config.ts` mem-`define` `process.env.GEMINI_API_KEY` ke bundle (saat ini tidak direferensikan di `src`, tapi berisiko bocor jika dipakai). Gemini sebenarnya dipanggil dari backend. | `plans/UI/vite.config.ts` | Hapus `define`. |
| S-12 | Rendah | `DUMMY_AUTH_ENABLED` default `true` dengan password plaintext di config (hanya untuk route Blade lama). | `config/dummy_auth.php` | Hilang bersama pembersihan Blade. |

### 3.2 File/folder mati, duplikat, versi lama

| ID | Prio | Item | Bukti tidak dipakai | Usulan |
|---|---|---|---|---|
| D-1 | Tinggi | `plans/backend/public/hellom/**` (build nyasar, index + js/css) | Hasil `outDir` salah (lihat DEPLOYMENT.MD "Build ke folder salah"); tidak direferensikan | Hapus |
| D-2 | Sedang | Skrip debug di root backend: `check_demo.php`, `check_img.php`, `check_landing_page.php`, `check_order.php`, `check_products.php`, `check_tenant_data.php`, `test_context.php`, `test_customer_tenant.php`, `test_middleware.php`, `test_request.php`, `test_route.php`, `test_tenant.php`, `update_tenant.php`, `_debug_request_constants.php`, `temp_dashboard.html`, `backend/scripts/*.php` | Skrip CLI ad-hoc (bootstrap manual), tidak di-route/di-require | `_archive/backend-debug-scripts/` |
| D-3 | Sedang | **UI Blade lama** + route: `routes/{admin,cashier,customer,auth,marketing,auth_global}.php`, controller `Admin/*` (kecuali DigitalProduct, ProductPurchase, OwnerNotification), `Auth/*`, `Cashier/*`, `Customer/*`, `Marketing/*`, `Public/*`, `ProfileController`, middleware `AuthZ/*`, `Tenancy/*`, `Dev/*`, `EnsureUserIsAdmin`, `EnsureWebPosEntitlement`, `SetCustomerTenant`, `Services/Auth/DummyAuthService`, `Services/Tenancy/*`, `Services/Gateway/*`, `config/dummy_auth.php`, bagian dummy di `config/tenancy.php`, `resources/views/**` kecuali `emails/`, `errors/`, `resources/js|css` Blade, `backend/{vite,tailwind,postcss}.config.js`, tes Blade (`tests/Feature/Auth/*`, `Admin/*`, `ProfileTest`) | Nginx production hanya meneruskan `/api`, `/storage`, `/media` ke Laravel. **Perlu konfirmasi config Nginx aktual di VPS.** | Arsip bertahap setelah konfirmasi (**berisiko**) |
| D-4 | Sedang | Kelas PHP tanpa referensi: `Api/AnalyticsController`, `Admin/ManageMenuController`, `Customer/PendingOrderController`, `Customer/TablePendingOrderController`, `Services/PosContextService`, `Services/GoogleMaps/GoogleMapsService`, `Policies/OrganizationPolicy` (tidak didaftarkan), controller Breeze email-verification/password | grep referensi nol | Arsip |
| D-5 | Sedang | Command dev/test di app: `test:end-to-end-flows`, `test:tenant-isolation` (memakai tenant dummy alpha/beta). `tenant:backfill-settings` **terdaftar dua kali** (closure `console.php` + class). Closure lama di `console.php` (`tenants:seed-demo --truncate`, `orders:purge-legacy`, `tenant:backfill-*`) | Artefak migrasi single→multi tenant | Arsip; hapus duplikat |
| D-6 | Sedang | UI SPA tak terjangkau dari `main.tsx`: `components/EnvironmentDetails.tsx`, `components/landing/SaasProductsSection.tsx`, `components/ui/FormInput.tsx`, `lib/mockData.ts`, `pages/produk/coreRouter.tsx`, `pages/produk/router.tsx`, `landing-builder/components/.keep` | Graf import dari `src/main.tsx` | Hapus |
| D-7 | Rendah | `plans/UI/referensi/` (export Figma Make, punya package.json sendiri) | Tidak di-import; ikut ter-typecheck (menambah error `tsc`) | Pindah ke `docs/design-reference/` atau `_archive/` |
| D-8 | Rendah | `plans/UI/server.ts` (Express dev server, port 3000 hardcode) | `npm run dev` memakainya, tapi `vite` biasa sudah cukup | Ganti `dev` → `vite`; hapus express dari deps |
| D-9 | Rendah | Aset duplikat `plans/UI/public/*` vs `backend/public/hellom/{assets,fonts,manifest.json,sw.js}` ter-commit | Build menyalin `public/` ke outDir (emptyOutDir) | Hanya simpan di `plans/UI/public`; ignore seluruh `backend/public/hellom/` |
| D-10 | Rendah | `backend/resources/js/hellom/api/*.ts` + `tsconfig.hellom-wallet.json` | Tidak di-import oleh Vite entry mana pun | Arsip |
| D-11 | Rendah | Dokumen tercecer/usang di root: `PROGRESS.md`, `PRODUCT_FEATURE.md`, `LandingPage.md`, `STATIC_BRANDING_SETUP.md`, `running this app` (path `Self-OrderMenu`, Midtrans), `ngrok.yml`, screenshot `plans/UI/*.png`, `plans/UI/metadata.json` (AI Studio), `tsconfig.tsbuildinfo` | Konten usang (mis. `pos_base_url` sudah tidak ada) | Pindah ke `docs/` / `_archive/docs/` |
| D-12 | ~~Rendah~~ | ~~`backend/server.php`~~ **Koreksi:** file ini router kustom `php artisan serve` (memperbaiki routing SPA di bawah `public/hellom/`), dipakai via `ServeCommand` bila ada di root | Dipakai | **Pertahankan** |

### 3.3 Dependensi

| ID | Paket | Status | Usulan |
|---|---|---|---|
| P-1 | UI: `@google/genai`, `better-sqlite3` (native, berat), `dotenv`, `motion` (duplikat `framer-motion`) | Tidak di-import di `src` | Hapus |
| P-2 | UI: `express`, `@types/express`, `tsx` | Hanya untuk `server.ts` | Hapus bersama D-8 |
| P-3 | UI: `@types/react-router-dom@5` | Salah versi (v7 membawa tipe sendiri) | Hapus |
| P-4 | UI: `vite`, `@vitejs/plugin-react`, `@tailwindcss/vite` di `dependencies`; `vite` tercantum dua kali | Build-time | Pindah ke `devDependencies` |
| P-5 | UI: `package.json` `name: "react-example"`, `build:laravel` memakai `--base=/hellom/` (bertentangan dengan `base: '/'`) | Membingungkan | Rapikan nama & script |
| P-6 | Backend npm: seluruh `backend/package.json` (alpine, tailwind 3 **dan** @tailwindcss/vite 4, laravel-vite-plugin, axios, concurrently) | Hanya untuk Blade | Hapus bersama D-3 |
| P-7 | Composer: `doctrine/dbal` | Laravel 12 tidak butuh untuk `change()/rename`; tidak ada `use Doctrine` | Hapus setelah `composer install` + migrate diverifikasi |
| P-8 | Composer dev: `laravel/breeze` (scaffolding), `laravel/sail` (Docker, tidak dipakai di Laragon/aaPanel) | — | Hapus |
| P-9 | Dipakai, pertahankan: `openspout/openspout` (export laporan), `simplesoftwareio/simple-qrcode` (QR absensi staf) | — | — |

### 3.4 Penamaan & struktur

| ID | Prio | Temuan |
|---|---|---|
| N-1 | Sedang | Controller raksasa: `BillingController` 2.966 baris, `LandingBuilderController` 1.652, `WalletController` 1.388, `PosStaffController` 1.258, `OrganizationTeamController` 900. Validasi inline, tidak ada Form Request (hanya 1, milik Breeze), Policy hanya 1. |
| N-2 | Sedang | Namespace `Api/V1/Hellom` menampung semua domain (billing, wallet, landing, POS, admin, webhook); `Admin/` dipakai API dan Blade sekaligus; dua `OrderController`, dua `BrandSettingController`, dua `MemberDashboardController`, `BrandSetting` vs `HellomBrandSetting`, `LoyaltySetting` vs `PosLoyaltySetting`, `PointTransaction` vs `PosPointTransaction` (lama vs POS). |
| N-3 | Sedang | Dua sistem role (`users.role` & pivot) tanpa enum/konstanta; string role tersebar (`'super_admin'`, `'admin'`, `'tenant_admin'`, `'owner'` …). |
| N-4 | Sedang | Route API: middleware `canUseApp:landing_builder` diulang di 31 route (bukan group); indentasi rusak `api.php:338`; nama route campur (`purchase_settings.` vs tanpa nama untuk semua route POS). |
| N-5 | Rendah | Frontend: `lib/hellomApi.ts` 1.711 baris (semua endpoint); halaman 1.000-2.300 baris (`OrderPage.tsx` 2.325, `PosStaff.tsx` 1.720); `pages/produk` vs `pages/dashboard/products` vs `pages/admin/products`; nama file campur (`[slug].tsx`, `my-purchases.tsx`, PascalCase). |
| N-6 | Rendah | Folder `plans/UI` untuk kode produksi (nama menyiratkan draf); nama produk campur: SelfOrderResto / Hellom / HellomV2 / "Self Order" (`APP_NAME` di `.env.example`). |
| N-7 | Rendah | `DEPLOYMENT.MD` menyebut Laravel 11 dan `config('app.pos_base_url')`, padahal proyek memakai Laravel 12 dan config itu sudah dihapus (commit `fe36da3`). |

### 3.5 Hardcode yang seharusnya di env/config

| ID | Lokasi | Nilai | Usulan |
|---|---|---|---|
| H-1 | `plans/UI/src/lib/hellomApi.ts:1-15` | fallback `http://127.0.0.1:8000/api/v1/hellom`, realtime `:3001` | Wajibkan `VITE_HELLOM_API_BASE`; default ke relatif `/api/v1/hellom` (same-origin di production) |
| H-2 | `backend/config/cors.php` | origin `127.0.0.1:3000`, `localhost:3000` | `CORS_ALLOWED_ORIGINS` di env |
| H-3 | `realtime/server.js` | CORS `origin: true`, secret default `change-me` | `REALTIME_ALLOWED_ORIGINS`; gagal start bila secret kosong di production |
| H-4 | `plans/UI/vite.config.ts` / `server.ts` | port 3000, outDir relatif ke backend | `VITE_OUT_DIR` opsional; dokumentasikan |
| H-5 | `config/payments.php` | default token `dev_*` | default `''` |
| H-6 | `PaymentModal.tsx` | deep link Dana/GoPay | Wajar sebagai konstanta; pindah ke `lib/constants` |
| H-7 | `scripts/*.ps1` | path `C:\laragon\app\Self-OrderMenu` | Pakai `$PSScriptRoot` |
| H-8 | `config/tenancy.php` | tenant dummy alpha/beta/expired + tanggal | Hapus (D-3) |
| H-9 | `lib/companyInfo.ts`, legal pages | data perusahaan | Periksa di Fase 2; bila ingin white-label pindah ke brand settings (**fitur → tanya**) |

### 3.6 Kesiapan deploy

| ID | Prio | Temuan | Usulan |
|---|---|---|---|
| R-1 | **Tinggi** | Cron scheduler (`* * * * * php artisan schedule:run`) tidak terdokumentasi, padahal masa berlaku langganan & settlement wallet bergantung padanya. | Tambah ke `docs/DEPLOY.md` + cek VPS. **Tanya status VPS.** |
| R-2 | Sedang | Queue: `.env.example` `sync`, DEPLOYMENT.MD `database`, tidak ada worker di PM2. Saat ini tidak ada job yang benar-benar di-queue (`ShouldQueue` hanya di-import, tidak di-implement), jadi dengan `database` pun aman, tapi tidak konsisten. | Tetapkan `database` + worker PM2 (`queue:work`) atau `sync`; dokumentasikan. |
| R-3 | Sedang | `AppServiceProvider::boot` → `BrandSetting::current()` query DB tanpa try/catch, sehingga `config:cache`, `route:list`, `package:discover` gagal bila DB mati (terbukti saat audit, MySQL lokal off). `View::share` ini hanya untuk Blade. | Lazy (`View::composer`) atau hapus bersama Blade. |
| R-4 | Sedang | Tidak ada `ecosystem.config.js`; PM2 dijalankan manual `pm2 start server.js --name hellom-realtime`, env realtime tidak terdokumentasi. | Buat `ecosystem.config.js` (realtime + opsional queue worker). |
| R-5 | Sedang | Tidak ada `.env.example` untuk `realtime/`; `plans/UI/.env.example` masih template AI Studio (GEMINI_API_KEY, APP_URL) tanpa `VITE_HELLOM_API_BASE`. `backend/.env.example` kurang: iPaymu, Xendit, DOKU, Gemini, TENANCY_*, PLATFORM_*, WALLET_*, CORS. | Lengkapi (tanpa nilai rahasia). |
| R-6 | Sedang | `npx tsc --noEmit` = **314 error** (130 TS2339, 76 TS2345, 47 TS2307 sebagian dari `referensi/`). Build tetap lolos karena Vite tidak typecheck. | Batasi `include: ["src"]`, lalu turunkan error bertahap (bukan blocker deploy). |
| R-7 | Sedang | PHPUnit tidak bisa jalan di sqlite `:memory:` (3 migration memakai `information_schema` MySQL). | `phpunit.xml` → DB MySQL khusus tes (`resto_test`) atau guard driver di migration **baru**. |
| R-8 | Sedang | `/socket.io` tidak di-proxy di Nginx contoh; `VITE_REALTIME_PUBLIC_URL=https://hellomspace.com`. | Tambah `location /socket.io/ { proxy_pass http://127.0.0.1:3001; upgrade headers }`. **Cek VPS.** |
| R-9 | Rendah | Storage: Nginx `alias` ke `storage/app/public` (tanpa `storage:link`) plus route Laravel `/media/{path}` sebagai fallback. Dua mekanisme. | Dokumentasikan; pilih satu. |
| R-10 | Rendah | Deploy manual; `npm install` (bukan `npm ci`); `route:cache` dijalankan padahal `web.php` memakai closure (Laravel 12 bisa, tapi `/{slug}` catch-all perlu diuji). | `scripts/deploy.sh` idempoten. |
| R-11 | Rendah | `.kilo/` (worktree tool) tidak di-ignore; `realtime/*.log` sudah ter-ignore. | Tambah `.kilo/` ke `.gitignore`. |
| R-12 | Info | Perubahan belum di-commit milik Anda: `plans/UI/src/components/landing/HellomspaceLanding.tsx`. Tidak akan saya sentuh. | — |
| R-13 | Sedang | `php artisan route:cache` **gagal** di lokal: `routes/marketing.php` mendaftarkan route per domain di `TENANCY_APP_DOMAINS` (lokal: `localhost`, `127.0.0.1`) → nama `marketing.landing` duplikat. Di production (1 domain) kemungkinan lolos. `customer.php` juga mendaftarkan URI `/pos` & `/reservations` dua kali. | Hilang bersama arsip Blade (D-3); verifikasi `route:cache` di setiap langkah. |
| R-14 | Sedang | Bundle SPA tanpa code-splitting per route: chunk utama **1,71 MB** (424 KB gzip). Halaman self-order pelanggan (dibuka lewat HP) ikut memuat seluruh dashboard admin. | `React.lazy` per route di `App.tsx` (tanpa mengubah tampilan). |

---

## 4. Usulan struktur akhir

```
SelfOrderResto/
├── backend/                         Laravel API-only (+ view email)
│   ├── app/
│   │   ├── Actions/                 aksi bisnis tunggal (ActivateSubscription, ApproveWithdrawal, CreatePosOrder…)
│   │   ├── Enums/                   PlatformRole, OrgRole, PlanType, EntitlementStatus, PaymentStatus
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/
│   │   │   │   ├── Auth/            AuthController
│   │   │   │   ├── Platform/        Organization, Team, Member dashboard, Notifications, Onboarding
│   │   │   │   ├── Billing/         Checkout, Subscription, Invoice, Pricing, Promo, Wallet, PayoutProfile
│   │   │   │   ├── Pos/             (tetap) + CustomerOrder → Pos/Public
│   │   │   │   ├── LandingBuilder/  Builder, Sale, FileAsset
│   │   │   │   ├── Store/           DigitalProduct (consumer + public)
│   │   │   │   ├── Admin/           SuperAdmin, Showcase, LandingContent, Brand, Mail, DigitalProduct, Purchases, Notifications
│   │   │   │   └── Webhooks/        Xendit, Ipaymu, Doku
│   │   │   ├── Middleware/Api/      (tetap)
│   │   │   └── Requests/<Modul>/    Form Request per endpoint tulis
│   │   ├── Policies/                Organization, Outlet, Withdrawal, Subscription, LandingPage
│   │   ├── Services/
│   │   │   ├── Billing/             SubscriptionService, EntitlementService, PeriodCalculator, LicenseService (Fase 2 #6)
│   │   │   ├── Payments/Gateways/   Xendit, Ipaymu, Doku (+Settings)
│   │   │   ├── Wallet/, Pos/, Landing/, Notifications/, Realtime/, Ai/Gemini
│   ├── routes/
│   │   ├── api.php                  hanya require per modul
│   │   └── api/{auth,platform,billing,pos,landing,store,admin,public,webhooks}.php
│   ├── resources/views/emails/      (satu-satunya view tersisa)
│   └── .env.example
├── frontend/                        ← dari plans/UI  (berisiko: path deploy VPS berubah)
│   ├── src/
│   │   ├── app/                     App.tsx, routes.tsx, providers
│   │   ├── pages/                   halaman tipis per route
│   │   ├── features/{auth,pos,self-order,billing,wallet,landing-builder,store,admin,public-site}/
│   │   │     components/ hooks/ api.ts types.ts
│   │   ├── components/{ui,layout}/  komponen generik
│   │   ├── hooks/  lib/  types/
│   │   └── services/api/            client.ts (fetch, token, outlet header) + re-export per modul
│   ├── public/
│   └── .env.example
├── realtime/                        server.js (+ auth handshake) · .env.example
├── deploy/                          ecosystem.config.js, nginx.hellomspace.conf.example, deploy.sh, crontab.example
├── scripts/dev/                     skrip Windows (kiosk, start/stop)
├── docs/                            AUDIT, ARCHITECTURE, DEPLOY, design-reference/, billing-*.md
├── _archive/                        file yang diragukan + README alasan
├── CLAUDE.md  README.md  CHANGELOG.md
```

**Alasan**: (1) memisahkan domain, supaya developer baru menemukan billing/POS/landing di tempat yang dapat ditebak; (2) API-only backend menghapus dua UI yang bertabrakan di URL yang sama; (3) `frontend/` menghilangkan kesan "plans/draft"; (4) `deploy/` membuat deploy dapat direproduksi; (5) `Enums` + `Policies` menggantikan string role tersebar yang menjadi sumber bug otorisasi S-2/S-7.

---

## 5. Rencana eksekusi Fase 2

Legenda: 🟢 aman (tanpa perubahan perilaku) · 🟡 perlu verifikasi · 🔴 berisiko / mengubah perilaku, **butuh izin eksplisit**

### Langkah 0: Hotfix keamanan (disarankan SEBELUM cleanup, commit terpisah & bisa langsung di-deploy)
- [x] 🔴 S-1 Route mock billing → middleware `billing.mock` (404 kecuali `BILLING_MOCK_ENABLED=true`); UI tidak memanggil `topup-mock` (`3e09ce6`)
- [x] 🔴 S-2 Aksi admin wallet → hanya `super_admin` (route + controller); antrean lintas organisasi + field `organization` (`62ca425`). Catatan: sebelumnya super admin pun hanya melihat penarikan organisasinya sendiri
- [x] 🔴 S-4 Tolak token `dev_*` Xendit; default token dev dihapus dari config (`4bd424e`)
- [x] 🟡 S-3 Secret di `DEPLOYMENT.MD` → placeholder (`2633b09`). **Rotasi di VPS masih harus Anda lakukan**
- [ ] 🟡 Query audit read-only di production: transaksi `wallet_topup_mock`, invoice `payment_method = mock`, withdrawal yang di-approve non-super-admin

### Langkah 1: Arsip & dependensi
- [x] 🟢 `git checkout -b refactor/cleanup`
- [x] 🟢 D-1 hapus `plans/backend/`
- [x] 🟢 D-2 skrip debug → `_archive/backend-debug-scripts/`
- [x] 🟢 D-6 file UI mati
- [x] 🟢 D-11 dokumen & file usang → `docs/notes/`, `docs/screenshots/`, `scripts/ngrok.yml`, `_archive/docs/` (D-12 dibatalkan: `server.php` dipakai)
- [x] 🟢 R-11/D-9 `.gitignore`: `.kilo/`, `*.tsbuildinfo`, seluruh `backend/public/hellom/` (salinan ter-commit ternyata usang: `sw.js` v3 vs sumber v4) (`a2b2d02`)
- [x] 🟡 P-1..P-5, D-8, S-11 `plans/UI/package.json`: −201 paket, `server.ts` dihapus (`npm run dev` = vite), `define` GEMINI dihapus. Bonus: `tsc` 313 → **231** error (tipe react-router v5 yang salah) (`18dbcbd`). ⚠ vite kini di devDependencies → di VPS pakai `npm ci --include=dev`
- [x] 🟡 D-4/D-5/D-10 kelas & command tak terpakai → `_archive/backend-unused/` (+ controller/view/tes Breeze yang tidak ter-route). `OrganizationPolicy` dipertahankan (auto-discovered, akan dipakai Langkah 2) (`a5e8b29`)
- [x] 🟡 P-7/P-8 composer: hapus `doctrine/dbal`, `laravel/breeze`, `laravel/sail` (`f6bfc3a`)
- [ ] 🔴 D-3 arsip UI Blade + route + middleware dummy + tes Blade + `backend/package.json` — **DITAHAN** sampai config Nginx VPS dikonfirmasi (keputusan #2)

### Langkah 2: Struktur & penamaan backend
- [ ] 🟢 `Enums` role/plan/status (tanpa mengubah nilai DB)
- [ ] 🟢 Pecah `routes/api.php` per modul (URL & nama route tidak berubah; diff `route:list` sebelum/sesudah harus identik)
- [ ] 🟡 Form Request untuk endpoint tulis (aturan validasi disalin 1:1)
- [ ] 🟡 Pecah `BillingController`/`WalletController`/`LandingBuilderController` → Services/Actions (perilaku identik)
- [ ] 🟡 Policy: Withdrawal, Outlet, Organization
- [ ] 🟡 R-3 lazy brand share / hapus bersama Blade

### Langkah 3: Frontend resmi
- [ ] 🔴 Pindah `plans/UI` → `frontend/` (butuh ubah path build di VPS & outDir)
- [ ] 🟢 `tsconfig` `include: ["src"]`; `referensi/` → `docs/design-reference/`
- [ ] 🟡 Pecah `hellomApi.ts` → `services/api/*` (re-export kompatibel)
- [ ] 🟡 Susun `features/<modul>`; update import (tanpa ubah tampilan)
- [ ] 🟡 Turunkan error `tsc` bertahap

### Langkah 4: Konfigurasi
- [ ] 🟢 H-1..H-5, H-7 ke env/config
- [ ] 🟢 `.env.example` lengkap: backend, frontend, realtime

### Langkah 5: Keamanan lanjutan
- [ ] 🔴 S-5 auth Socket.IO + room per org
- [ ] 🔴 S-6 suspend → cabut token, cek status
- [ ] 🟡 S-7 batasi role `admin`
- [ ] 🟡 S-8 throttle
- [ ] 🟡 S-9 scope tenant / tes isolasi
- [ ] 🟢 S-10/S-11/S-12 hapus kode mati berisiko

### Langkah 6: Fondasi penjualan (desain dulu, tidak menulis ulang)
- [ ] 🔴 Proposal desain: `plans` (langganan) vs `licenses` (sekali beli) + `EntitlementService` tunggal yang menegakkan `ends_at`; konsistensi periode (bukan `addMonth` hardcode). **Diajukan ke Anda sebelum implementasi.**

### Langkah 7: Deploy
- [ ] 🟢 `deploy/ecosystem.config.js`, `deploy/nginx.conf.example` (+ `/socket.io`), `deploy/crontab.example`, `deploy/deploy.sh`
- [ ] 🟡 `phpunit.xml` → MySQL tes

### Verifikasi setiap langkah
`composer install` · `php artisan route:list` · `php artisan config:cache && php artisan config:clear` · `npm run build` (plans/UI) · `npx tsc --noEmit` (tidak boleh bertambah) · `php artisan test` (MySQL tes).
**Baseline (2026-09-27, MySQL Laragon menyala)**: angka acuan, tidak boleh memburuk:

| Cek | Hasil |
|---|---|
| `composer validate` / `composer install --dry-run` | OK (peringatan: versi exact `openspout 4.28`); tidak ada paket yang perlu di-install |
| `php artisan route:list` | OK: **498 route** (314 `api/*`, 184 web/lainnya) |
| `php artisan config:cache` | OK (lalu `config:clear`) |
| `php artisan route:cache` | **GAGAL** di lokal (R-13) |
| `php artisan migrate:status` | Semua migration sudah `Ran`, 0 pending |
| `vite build` (ke folder temp) | OK, 19,8 dtk, chunk utama 1,71 MB (R-14) |
| `npx tsc --noEmit` | **314 error** (261 di `src/`, 53 di `referensi/`) |
| `php artisan test` (sqlite :memory:) | 8 lulus, 76 gagal; semua gagal karena migration MySQL-only (R-7), bukan logika |

---

## 6. Keputusan pemilik proyek (2026-09-27)

1. **Hotfix keamanan (Langkah 0)**: disetujui; approval penarikan dana wajib melalui super admin. ✅ selesai
2. **Nginx production**: **belum pasti, harus diperiksa ulang di VPS**. D-3 (arsip UI Blade) **ditahan** sampai config Nginx aktual dikonfirmasi.
3. **Rename `plans/UI` → `frontend/`**: disetujui (perintah build di VPS ikut berubah).
4. **"Sekali beli"** = paket **lifetime** di SaaS yang sama (bayar sekali, akses selamanya di hellomspace.com). Bukan lisensi self-hosted, tidak perlu license key.
5. **Realtime/cron production**: "sepertinya sudah"; masuk checklist verifikasi VPS (`/socket.io` proxy + `schedule:run`).
6. **`users.role = admin`**: masih ada dan dibutuhkan. S-7 **tidak diubah**, cukup didokumentasikan (role ini sengaja bisa melihat/berpindah ke semua organisasi).

### Perintah pemeriksaan VPS (read-only, dijalankan pemilik)
```bash
# Nginx aktual untuk hellomspace.com
cat /www/server/panel/vhost/nginx/hellomspace.com.conf
nginx -T 2>/dev/null | grep -nE "server_name|root |location|proxy_pass|fastcgi_pass" | grep -A3 -B3 hellomspace
# Apakah URL Blade lama dijawab Laravel? (200 = Laravel, index.html SPA = sudah mati)
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://hellomspace.com/cashier/login
curl -s https://hellomspace.com/cashier/login | head -c 300
# Cron scheduler & realtime
crontab -l | grep -i artisan
pm2 describe hellom-realtime | grep -E "status|script path|exec cwd"
curl -s -o /dev/null -w "%{http_code}\n" "https://hellomspace.com/socket.io/?EIO=4&transport=polling"
```
