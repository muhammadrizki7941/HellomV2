# Arsitektur Hellom

## 1. Gambaran umum

```
Browser (React SPA, hellomspace.com)
   │  fetch /api/v1/hellom/*   Authorization: Bearer <token>   X-Outlet-Id: <outlet>
   ▼
Nginx ── /api, /storage, /media ─────────────► Laravel (backend/public/index.php)
   │  ── /socket.io ─────────────────────────► realtime/server.js (PM2, :3001)
   └─ semua path lain ► backend/public/hellom/index.html (SPA)

Laravel ──HTTP POST /emit (X-RT-SECRET)──► realtime ──socket.io (room privat)──► browser
Laravel scheduler (cron schedule:run): perpanjangan/kedaluwarsa langganan, settlement wallet
```

- **Satu UI**: React SPA di `frontend/`, di-build ke `backend/public/hellom/`. UI Blade lama sudah diarsipkan (`_archive/blade-ui/`). Laravel hanya melayani API, file storage, email, dan (untuk `php artisan serve`) fallback SPA di `routes/web.php`.
- **Satu database MySQL**, *shared schema*: semua tenant di tabel yang sama, dipisah oleh kolom tenant (lihat §3).

## 2. Struktur kode

```
backend/
  app/Http/Controllers/Api/V1/
    BaseApiController.php        amplop respons { success, message, data, error }
    Hellom/                      platform: auth, organisasi, tim, billing, wallet, landing, admin, webhook
    Hellom/Billing/              checkout & overview, konfigurasi gateway, review checkout manual (super admin),
                                 bayar via wallet, checkout landing publik, mock (dev); helper bersama di Concerns/
    Hellom/Pos/                  POS: outlet, menu, meja, pesanan, member, loyalti, staf, laporan
    Consumer/, Public/           produk digital (konsumen & katalog publik)
  app/Http/Controllers/Admin/    3 controller super admin yang dipakai API (produk digital, pembelian, notifikasi)
  app/Http/Middleware/Api/       AuthenticateApiToken, EnsureAppEntitlement (canUseApp), InjectPosContext,
                                 EnsureSuperAdmin, EnsureBillingMockEnabled
  app/Services/Billing/          EntitlementService (satu pintu aktivasi/kedaluwarsa akses), CheckoutNotifier (email billing)
  app/Services/Hellom/           gateway (iPaymu/Xendit/DOKU), mail, provisioning POS, landing sale, Gemini
  app/Services/                  OutletService (outlet & slug tenant), NotificationService, Realtime/, Reservations/
  routes/api.php                 prefix v1/hellom + grup auth; me-require routes/api/*.php per modul
  routes/api/{public,account,wallet,billing,consumer,landing-builder,member,pos,admin}.php
  routes/console.php             command & jadwal scheduler
  resources/views/emails/        satu-satunya view (email)
frontend/src/
  App.tsx                        router; halaman di-lazy-load per route
  pages/, components/, layouts/, hooks/
  services/api/                  klien API per modul (client, auth, organizations, billing, admin, consumer,
                                 landing, store, pos, posCustomer, posStaff, content, member) + index barrel
  lib/hellomApi.ts               barrel kompatibilitas → services/api
realtime/server.js               Socket.IO + endpoint /emit
deploy/                          PM2, contoh Nginx, crontab, deploy.sh
```

## 3. Multi-tenant

| Konsep | Implementasi |
|---|---|
| Tenant | `organizations`. Tenant aktif user = `users.current_organization_id` (tidak bisa diganti sendiri oleh user biasa). |
| Data platform | difilter `organization_id` (wallet, billing, landing, tim). |
| Data POS | difilter `tenant_id` = slug tenant: `organizations.pos_tenant_slug` untuk org, `outlets.tenant_slug` per outlet. |
| Outlet | `outlets` per organisasi; kuota dari `plans.max_outlets` atau override super admin. |
| Kunci outlet | `InjectPosContext`: owner/admin memilih outlet via header `X-Outlet-Id`; kasir **selalu** dikunci ke outlet di `pos_staff` miliknya. Controller memakai `$request->attributes->get('posTenantSlug')`. |
| Query level organisasi | `OutletService::tenantSlugs($org)` = slug org + semua outlet; `tenantSlugsForTenant($slug)` dari satu slug. |

> **Penting:** global scope Eloquent `tenant` pada `Order/Product/Category/DiningTable/SitePromotion` membaca `auth()->user()` (guard session), sehingga **tidak aktif** untuk request API bertoken. Setiap query API **wajib** memfilter tenant secara eksplisit (`where('tenant_id', …)` / `whereIn('tenant_id', OutletService::tenantSlugs(...))`). Endpoint publik mencari data lewat token yang dibawa pengguna (`dining_tables.public_id`), bukan ID berurutan.

## 4. Autentikasi & role

- **Token API**: token opak, disimpan sha256 di `api_tokens`, dikirim sebagai `Bearer`. Frontend menyimpannya di `localStorage` (`hellom_token`). Login/registrasi/reset password dibatasi *rate limit* (§8).
- **Suspend**: `users.role = suspended` + `role_before_suspension`; suspend mencabut semua token; login, SSO, dan middleware menolak akun suspended; reactivate memulihkan role.

| Role bisnis | Implementasi |
|---|---|
| Super Admin | `users.role = super_admin` → middleware `superAdmin`, `Gate::before` bypass. Satu-satunya yang dapat meninjau/menyetujui penarikan dana. |
| Admin platform (lama) | `users.role = admin` → dapat melihat/berpindah ke semua organisasi (dipertahankan atas keputusan pemilik). |
| POS Admin | pivot `organization_user.role` = `owner`/`admin` → semua outlet organisasi. |
| Kasir | anggota non-manager dengan `pos_staff.linked_user_id` aktif → satu outlet. |
| Self-Order | tanpa login: `/customer/order/:tableToken` → endpoint publik `/pos/customer/*`. |
| Member loyalti | tanpa password: lookup nomor HP (`/pos/public/members/*`). |

## 5. Alur utama

**Self-order**: QR meja → `GET /pos/customer/menu/{tableToken}` → `POST /pos/customer/order` (harga dari DB, produk harus milik tenant meja, status `unpaid`) → halaman sukses mem-*poll* `GET /pos/customer/order/{orderNumber}?table_token=…` (token meja wajib; nomor order saja tidak cukup).

**Kasir**: `PosOrders` → `PATCH /pos/orders/{id}/status` `{status}` → `POST /pos/orders/{id}/payment` → struk `GET /pos/orders/{id}/receipt`. Poin loyalti diberikan saat order selesai.

## 6. Realtime

- Laravel memanggil `RealtimeClient::emitToRoom($room, $event, $data)` → `POST {REALTIME_SERVER_URL}/emit` dengan header `X-RT-SECRET`.
- Browser mengambil token `GET /api/v1/hellom/realtime/token` (berlaku 10 menit, HMAC-SHA256 dengan `REALTIME_SERVER_SECRET`) dan mengirimnya saat handshake; server memverifikasi lalu memasukkan socket ke room privatnya (`user_<id>`, `admins` untuk super admin). Token palsu/kedaluwarsa ditolak.
- Event saat ini: `admin.notification.created` → room `admins` (bell super admin). Pesanan POS di SPA memakai *polling*.
- `REALTIME_REQUIRE_AUTH=true` (disarankan) menolak socket tanpa token.

## 7. Model bisnis & billing

| Tipe plan | Arti | Akses berakhir |
|---|---|---|
| `subscription` | langganan bulanan/tahunan (`billing_cycles`) | `starts_at + periode` |
| `one_time` + `duration_days` | prabayar (mis. 365 hari), tanpa perpanjangan otomatis | `starts_at + duration_days` |
| `lifetime` | **sekali beli**, akses selamanya (mis. `pos_lifetime`) | tidak pernah |
| `free` | gratis | tidak pernah |

- **Satu sumber periode**: `Plan::accessEndsAt($start, $billingCycle)` (siklus yang dipilih pembeli menang).
- **Satu pintu akses**: `App\Services\Billing\EntitlementService` — semua aktivasi (iPaymu/Xendit/DOKU webhook, reconcile, transfer manual, saldo wallet, perpanjangan otomatis) menulis `entitlements.ends_at` = `subscriptions.ends_at`.
- **Penegakan**: `Entitlement::effectiveStatus()/allowsAccess()` — `active/trialing` yang lewat `ends_at` (+ `BILLING_GRACE_DAYS`, default 0) dianggap `expired`. Dipakai middleware `canUseApp` dan endpoint tampilan, sehingga akses berhenti tepat waktu walau cron mati.
- **Scheduler**: `hellom:billing:auto-renew-wallet` (per jam, bulanan dari saldo wallet), `hellom:billing:expire-subscriptions` (per jam, tahunan/prabayar dan bulanan tanpa auto-renew), `notifications:check-expiry` (harian).
- **Data lama**: `hellom:billing:backfill-entitlement-ends` (laporan dulu, tulis dengan `--force`).
- **Endpoint mock** (top-up/checkout palsu) hanya aktif bila `BILLING_MOCK_ENABLED=true` (lokal saja).
- **Produk digital** (`digital_products`, `product_purchases`): penjualan file sekali beli di katalog Hellom.

**Wallet & penarikan**: saldo per organisasi (`organization_wallets` + ledger). Penjualan produk landing page masuk `pending` → dirilis ke `available` oleh `hellom:wallet:release-pending-settlements` setelah jeda settlement (hari kerja), dipotong komisi platform (`PLATFORM_SALE_COMMISSION_PERCENT`). Penarikan butuh KYC terverifikasi dan **hanya super admin** yang dapat menyetujui/menandai dibayar (lintas organisasi).

## 8. Keamanan

- Rate limit (`AppServiceProvider`): `hellom-auth` 10/menit per email+IP; `hellom-public-write` 60/menit per IP; `hellom-public-lookup` 30/menit per IP.
- Webhook gateway: token callback wajib diset (nilai `dev_*` ditolak).
- CORS: `CORS_ALLOWED_ORIGINS` (produksi same-origin).
- `APP_ENV=production` wajib di server (endpoint lupa password hanya mengembalikan token debug di `local`).

## 9. Konvensi

- Respons API: `BaseApiController::ok()/fail()` (Hellom) atau `success()/error()` (POS/konsumen) — amplop sama.
- Endpoint baru: validasi di Form Request **hanya** bila otorisasi juga dipindah ke `authorize()`, supaya urutan 401/403 → 422 tetap sama dengan endpoint lain.
- Frontend: panggil API lewat `services/api/*` (atau `@/lib/hellomApi`); tipe respons diambil dari payload backend. `npx tsc --noEmit` harus 0 error dengan `strict: true`; jangan menambah `any` untuk membungkam error.
- Commit: conventional commits, kecil per langkah.

## 10. Tes

PHPUnit (`backend/phpunit.xml`) memakai sqlite `:memory:`, tetapi tiga migration memakai `information_schema` MySQL sehingga tes fitur gagal di sqlite. Tes unit (`tests/Unit`) berjalan. Untuk verifikasi backend gunakan DB MySQL terpisah atau skrip `tinker` di dalam transaksi yang di-rollback. **Pastikan `bootstrap/cache/config.php` tidak ada sebelum `php artisan test`** — config ter-cache bisa membuat tes memakai DB sungguhan.
