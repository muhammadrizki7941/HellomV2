# Hellom — POS & Self-Order Restoran

Platform SaaS multi-tenant untuk restoran dan UMKM, berjalan di [hellomspace.com](https://hellomspace.com):

- **POS / Kasir** — menu, meja + QR, pesanan, pembayaran (tunai, transfer, QRIS, Dana/GoPay), struk, laporan + ekspor Excel, multi-outlet, staf (shift, absensi QR, kas), member & loyalti, promo, reservasi ruang.
- **Self-order** — pelanggan scan QR meja, memesan dari HP tanpa login, memantau status pesanan.
- **Landing Page Builder** — halaman promosi per organisasi, termasuk penjualan produk digital dengan wallet penjual.
- **Billing** — langganan bulanan/tahunan dan paket **lifetime** (bayar sekali), gateway iPaymu/Xendit/DOKU, transfer manual, dan saldo wallet.

Peran pengguna: **Super Admin** (platform), **POS Admin** (owner/admin organisasi), **Kasir** (terkunci ke satu outlet), dan **Self-Order** (pelanggan, tanpa login).

## Stack

| Bagian | Teknologi | Folder |
|---|---|---|
| Backend / API | Laravel 12, PHP 8.2+, MySQL | [`backend/`](backend) |
| Frontend (UI resmi) | React 19, Vite 6, TypeScript, Tailwind 4, react-router 7 | [`frontend/`](frontend) |
| Realtime | Node.js + Socket.IO (PM2) | [`realtime/`](realtime) |
| Deploy | Nginx (aaPanel), PM2, cron | [`deploy/`](deploy) |

Arsitektur, multi-tenant, role, dan model bisnis: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Menjalankan di lokal (Laragon, Windows)

Prasyarat: Laragon (PHP 8.2+, MySQL, Composer), Node.js 20+.

```bash
# 1. Backend
cd backend
cp .env.example .env            # isi DB_DATABASE, DB_USERNAME, DB_PASSWORD
composer install
php artisan key:generate
php artisan migrate             # JANGAN migrate:fresh pada DB berisi data
php artisan serve --host=127.0.0.1 --port=8000

# 2. Frontend (terminal lain)
cd frontend
cp .env.example .env.local      # VITE_HELLOM_API_BASE=http://127.0.0.1:8000/api/v1/hellom
npm install
npm run dev                     # http://localhost:3000

# 3. Realtime (opsional, untuk notifikasi admin)
cd realtime
npm install
REALTIME_SERVER_SECRET=change-me node server.js   # sama dengan backend/.env
```

Scheduler (langganan, settlement wallet) di lokal: `php artisan schedule:work`.

Build SPA untuk disajikan Laravel (`http://127.0.0.1:8000`): `npm --prefix frontend run build` → `backend/public/hellom/`.

## Pemeriksaan kualitas

```bash
cd frontend && npx tsc --noEmit     # harus 0 error (strict)
cd frontend && npm run build
cd backend && php artisan route:list && php artisan config:cache && php artisan config:clear
cd backend && php artisan test      # lihat catatan tes di docs/ARCHITECTURE.md
```

## Deploy

Ringkas: `bash deploy/deploy.sh` di VPS (pull → composer → migrate → cache → build frontend → PM2). Langkah lengkap dan checklist rilis: [docs/DEPLOY.md](docs/DEPLOY.md).

## Dokumentasi lain

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — arsitektur, tenant, role, realtime, billing
- [docs/DEPLOY.md](docs/DEPLOY.md) — deploy VPS aaPanel + PM2
- [docs/AUDIT.md](docs/AUDIT.md) — audit & checklist refactor
- [docs/proposals/billing-foundation.md](docs/proposals/billing-foundation.md) — desain langganan & lifetime
- [CHANGELOG.md](CHANGELOG.md) — ringkasan perubahan
- [CLAUDE.md](CLAUDE.md) — konteks & aturan kerja untuk asisten AI
