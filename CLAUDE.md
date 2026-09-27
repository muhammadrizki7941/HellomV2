# CLAUDE.md — SelfOrderResto (Hellom)

Aplikasi kasir (POS) + self-order restoran, bagian dari platform **Hellom** (hellomspace.com). Dijual sebagai SaaS multi-tenant (langganan) dan, direncanakan, lisensi sekali beli. Hasil audit lengkap: `docs/AUDIT.md`.

## Stack & layout
| Folder | Isi |
|---|---|
| `backend/` | Laravel 12, PHP ^8.2, MySQL. API di `routes/api.php` (prefix `/api/v1/hellom`). Juga berisi UI **Blade lama** (routes `admin/cashier/customer/auth/marketing.php`) yang tidak terjangkau di production. |
| `frontend/` | **UI resmi**: React 19 + Vite 6 + TypeScript + Tailwind 4, react-router v7. Build → `backend/public/hellom/` (base `/`). |
| `realtime/` | Node Socket.IO (`server.js`, port 3001), PM2 name `hellom-realtime`. Laravel POST `/emit` dengan header `X-RT-SECRET`. |
| `scripts/` | Skrip PowerShell/CMD dev Windows. |
| `docs/` | Dokumentasi (AUDIT.md, billing handoff). |

Production: VPS Contabo + aaPanel, Nginx root `backend/public/hellom` (SPA); `/api`, `/storage`, `/media` → Laravel. Lokal: Laragon (Windows).

## Perintah penting
```bash
# Backend (dari backend/) — butuh MySQL Laragon menyala (AppServiceProvider query DB saat boot)
composer install
php artisan migrate            # JANGAN migrate:fresh / db:wipe
php artisan route:list
php artisan config:cache && php artisan config:clear
php artisan serve --host=127.0.0.1 --port=8000
php artisan schedule:work      # lokal; production: cron schedule:run tiap menit

# Frontend (dari frontend/)
npm install
npm run dev                    # vite dev server, port 3000
npm run build                  # → ../backend/public/hellom (menimpa build lama)
npx tsc --noEmit               # baseline: 178 error, semua di src/ (jangan menambah)

# Realtime (dari realtime/)
node server.js                 # env: PORT, HOST, REALTIME_SERVER_SECRET
```
Tes PHPUnit **tidak bisa** jalan di sqlite `:memory:` (migration memakai `information_schema` MySQL). Verifikasi backend: `php -l`, `route:list`, dan `tinker` di dalam `DB::beginTransaction()/rollBack()`.

## Konsep kunci
- **Auth API**: token opak di `api_tokens` (sha256), middleware `AuthenticateApiToken`. Frontend simpan di `localStorage` (`hellom_token`).
- **Tenant** = `organizations`; tenant aktif = `users.current_organization_id`. Data POS difilter `tenant_id` = `organizations.pos_tenant_slug` / `outlets.tenant_slug`; data platform difilter `organization_id`. **Isolasi dilakukan manual di setiap query**; global scope `tenant` di model TIDAK aktif pada API token.
- **Role**: `users.role` (`super_admin`, `admin`, `tenant_admin`, `cashier`, `member`, `suspended`) + pivot `organization_user.role` (`owner`, `admin`, `member`, `cashier`). Kasir dikunci ke satu outlet oleh `InjectPosContext` (lewat `pos_staff.linked_user_id`); owner/admin memilih outlet via header `X-Outlet-Id`.
- **Entitlement**: `canUseApp:<slug>` (`pos`, `landing_builder`) mengecek `entitlements` org. Masa berlaku ditegakkan oleh scheduler `hellom:billing:auto-renew-wallet`.
- **Billing**: `plans` × `app_catalogs` → `checkout_intents` → gateway (iPaymu utama, Xendit, DOKU, manual, wallet) → `subscriptions` + `entitlements` + `invoices`. Produk digital sekali beli: `digital_products` + `product_purchases`.
- **Self-order**: publik tanpa login via `dining_tables.public_id` (`/customer/order/:tableToken`).

## Konvensi
- Respons API: `{ success, message, data, error }` via `BaseApiController::ok()/fail()`.
- Komentar/teks UI berbahasa Indonesia; kode & nama variabel berbahasa Inggris.
- Frontend: alias `@/` → `frontend/src`; semua panggilan API lewat `src/lib/hellomApi.ts`.
- Commit: conventional commits (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`), kecil per langkah.

## Aturan kerja (wajib)
1. Jangan membaca, menampilkan, atau meng-commit `.env`, kredensial, API key, atau data pelanggan.
2. Jangan mengubah migration yang sudah dijalankan; buat migration baru. Jangan jalankan perintah penghapus data (`migrate:fresh`, `db:wipe`, `truncate`, `tenants:seed-demo --truncate`, `orders:purge-legacy --force`).
3. Sebelum menghapus file, buktikan tidak dipakai (import, route, config, blade, entry Vite, script package.json, PM2). Kalau ragu, pindahkan ke `_archive/` dengan catatan alasan.
4. Setelah langkah besar, verifikasi: `composer install`, `php artisan route:list`, `php artisan config:cache`, `npm run build`, `npx tsc --noEmit`, `php artisan test` (bila DB tes tersedia). Perbaiki dulu kalau gagal.
5. Jangan menambah fitur atau mengubah logika bisnis/tampilan, kecuali memperbaiki bug yang jelas, dan tanyakan dulu.
6. Keputusan ambigu → tanya pemilik proyek, jangan menebak.
7. Refactor dikerjakan di branch `refactor/cleanup`, checklist ada di `docs/AUDIT.md` §5.
8. Jangan menyentuh perubahan yang belum di-commit milik pemilik proyek tanpa izin.
