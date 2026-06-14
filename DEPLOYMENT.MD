# HellomV2 — Deployment Documentation
> Last updated: 14 Juni 2026 | Production: hellomspace.com

---

## 📁 Struktur Monorepo

```
SelfOrderResto/ (root repo: github.com/muhammadrizki7941/HellomV2)
├── backend/              ← Laravel (PHP 8.3) — API + SSR
├── plans/
│   └── UI/              ← React + Vite (Frontend SPA)
├── realtime/            ← Node.js Socket.IO server
├── scripts/             ← Helper scripts (PowerShell/CMD)
└── docs/                ← Dokumentasi teknis
```

---

## 🌐 Arsitektur Production

| Layer | Tech | Path di VPS |
|-------|------|-------------|
| Web Server | Nginx 1.24 | aaPanel config |
| Backend API | Laravel 11 (PHP 8.3) | `/www/wwwroot/hellomspace.com/backend/` |
| Frontend SPA | React + Vite | `/www/wwwroot/hellomspace.com/backend/public/hellom/` (hasil build) |
| Realtime | Node.js Socket.IO | `/www/wwwroot/hellomspace.com/realtime/` |
| Process Manager | PM2 | `hellom-realtime` |
| Database | MySQL (aaPanel) | localhost:3306 |
| SSL | Let's Encrypt | aaPanel auto-renew |

---

## 🔀 URL Routing

| URL | Menuju |
|-----|--------|
| `hellomspace.com/` | Landing page (React SPA) |
| `hellomspace.com/login` | Login page (React SPA) |
| `hellomspace.com/dashboard` | Member workspace (React SPA) |
| `hellomspace.com/dashboard/apps/pos` | POS Access Center (React SPA) |
| `hellomspace.com/pos/admin` | POS Admin Dashboard (React SPA) |
| `hellomspace.com/pos/cashier` | POS Cashier (React SPA) |
| `hellomspace.com/pos/member/:slug` | Portal member loyalty POS (phone-based, tanpa akun Hellom) |
| `hellomspace.com/api/v1/hellom/*` | Laravel API (butuh token) |
| `hellomspace.com/api/v1/hellom/pos/public/*` | API publik POS member (tanpa token) |
| `hellomspace.com/storage/*` | Laravel storage publik |

---

## ⚙️ Konfigurasi Penting

### 1. vite.config.ts (`plans/UI/vite.config.ts`)
```typescript
// WAJIB: base harus '/' bukan '/hellom/'
base: '/',

// WAJIB: outDir pakai absolute path ke backend/public/hellom
build: {
  outDir: '/www/wwwroot/hellomspace.com/backend/public/hellom',
  emptyOutDir: true,
}
```

> ⚠️ **Lokal:** `outDir` di lokal harus diubah ke path lokal atau relative `../../backend/public/hellom`

### 2. plans/UI/.env.production
```env
VITE_HELLOM_API_BASE=https://hellomspace.com/api/v1/hellom
VITE_REALTIME_PUBLIC_URL=https://hellomspace.com
```

### 3. backend/.env (production)
```env
APP_NAME=Hellom
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hellomspace.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=[nama_db]
DB_USERNAME=[user_db]
DB_PASSWORD=[password_db]

# Realtime Socket.IO
REALTIME_ENABLED=true
REALTIME_SERVER_URL=http://127.0.0.1:3001
REALTIME_PUBLIC_URL=https://hellomspace.com
REALTIME_SERVER_SECRET=hellom1811@#!$
REALTIME_TIMEOUT_SECONDS=1

# POS URL — WAJIB diisi agar POS tidak redirect ke localhost
POS_BASE_URL=https://hellomspace.com

QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

### 4. backend/config/app.php
```php
// Tambahan manual — wajib ada untuk POS SSO URL
'pos_base_url' => env('POS_BASE_URL', 'http://127.0.0.1:3000'),
```

### 5. Nginx Config (`hellomspace.com`)
```nginx
server {
    listen 80;
    server_name hellomspace.com www.hellomspace.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name hellomspace.com www.hellomspace.com;
    root /www/wwwroot/hellomspace.com/backend/public/hellom;
    index index.html;

    ssl_certificate /www/server/panel/vhost/cert/hellomspace.com/fullchain.pem;
    ssl_certificate_key /www/server/panel/vhost/cert/hellomspace.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    charset utf-8;

    # PWA service worker — harus dilayani tanpa cache
    location = /sw.js {
        add_header Cache-Control "no-cache, no-store, must-revalidate";
        try_files $uri =404;
    }

    location ^~ /.well-known/acme-challenge/ {
        root /www/wwwroot/hellomspace.com/backend/public/hellom;
        default_type "text/plain";
        try_files $uri =404;
    }

    location ^~ /api {
        root /www/wwwroot/hellomspace.com/backend/public;
        rewrite ^/api/(.*)$ /index.php break;
        fastcgi_pass unix:/tmp/php-cgi-83.sock;
        fastcgi_param SCRIPT_FILENAME /www/wwwroot/hellomspace.com/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param REQUEST_URI $request_uri;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ^~ /storage {
        alias /www/wwwroot/hellomspace.com/backend/storage/app/public;
        try_files $uri =404;
    }

    location ^~ /media {
        alias /www/wwwroot/hellomspace.com/backend/storage/app/public;
        try_files $uri =404;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|webp)$ {
        expires max;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

---

## 🔄 Alur Deploy Update

### Deploy ke Production (VPS)
```bash
# 1. SSH ke VPS
ssh root@IP_VPS

# 2. Pull update dari GitHub
cd /www/wwwroot/hellomspace.com
git pull origin main

# 3. Update Laravel backend
cd backend
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Build React frontend
cd /www/wwwroot/hellomspace.com/plans/UI
npm install
npm run build
# (output otomatis ke backend/public/hellom/)

# 5. Restart realtime server
pm2 restart hellom-realtime

# 6. Reload Nginx
nginx -s reload
```

---

## 🖥️ Setup Lokal (Laragon)

### Yang perlu berbeda dari production:

**1. vite.config.ts — ubah outDir untuk lokal:**
```typescript
// Lokal: pakai relative path atau path Laragon
build: {
  outDir: '../../backend/public/hellom',
  emptyOutDir: true,
}
```

**2. plans/UI/.env.local (buat file baru):**
```env
VITE_HELLOM_API_BASE=http://127.0.0.1:8000/api/v1/hellom
VITE_REALTIME_PUBLIC_URL=http://127.0.0.1:3001
```

**3. backend/.env (lokal):**
```env
APP_URL=http://127.0.0.1:8000
APP_ENV=local
APP_DEBUG=true

POS_BASE_URL=http://127.0.0.1:3000

REALTIME_SERVER_URL=http://127.0.0.1:3001
REALTIME_PUBLIC_URL=http://127.0.0.1:3001
```

**4. Jalankan semua service lokal:**
```powershell
# Terminal 1 — Laravel
cd C:\laragon\app\SelfOrderResto\backend
php artisan serve

# Terminal 2 — Vite dev server
cd C:\laragon\app\SelfOrderResto\plans\UI
npm run dev

# Terminal 3 — Realtime server
cd C:\laragon\app\SelfOrderResto\realtime
node server.js
```

---

## 📦 Fitur Baru & Perubahan (Juni 2026)

### 🔐 Sistem Dua Auth — Hellom Platform vs POS Member

Sebelumnya member POS bisa mendapat akun Hellom platform yang memberi akses ke `/dashboard`. Ini sudah diperbaiki:

| | Hellom Platform Member | POS Loyalty Member |
|---|---|---|
| Registrasi | Email + password via `/register` | Nama + nomor HP via portal POS |
| Login | Token Hellom (JWT) | Phone lookup — **tanpa password** |
| Akses | `/dashboard/*` | `/pos/member/:slug` (per-organisasi) |
| Data disimpan | `users` table | `pos_members` table |
| Auth storage | `hellom_session` di localStorage | `pos_member_session_{slug}` di localStorage |

**File yang diubah (backend):**
- `backend/app/Http/Controllers/Api/V1/Hellom/Pos/PosMemberController.php` — Tambah method `publicRegister` dan `publicLookup` (tanpa auth token)
- `backend/routes/api.php` — Route publik baru:
  ```
  POST /api/v1/hellom/pos/public/members/register
  GET  /api/v1/hellom/pos/public/members/lookup
  ```

**File yang diubah (frontend):**
- `plans/UI/src/lib/hellomApi.ts` — Tambah `registerPublicPosMember()` dan `lookupPublicPosMember()`
- `plans/UI/src/pages/pos/customer/MemberPortalPage.tsx` — Ditulis ulang: hapus Hellom auth, pakai phone-based session
- `plans/UI/src/pages/pos/PosMemberList.tsx` — Hapus `register()` Hellom, hanya `createPosMember()` biasa

---

### 📱 PWA (Progressive Web App) untuk POS

Aplikasi POS sekarang bisa di-install di HP tanpa membuka Chrome setiap kali.

**File baru:**
- `plans/UI/public/manifest.json` — App manifest (nama, icon, start_url, display: standalone)
- `plans/UI/public/sw.js` — Service worker (cache-first untuk asset, network-first untuk navigasi SPA)
- `plans/UI/src/hooks/usePWAInstall.ts` — Hook untuk mendeteksi dan memicu install prompt

**File yang diubah:**
- `plans/UI/index.html` — Tambah `<link rel="manifest">`, `<meta name="theme-color">`, apple-touch-icon, registrasi SW
- `plans/UI/src/layouts/PosLayout.tsx` — Tambah tombol "📲 Install" di header mobile (muncul otomatis jika browser mendukung), panduan khusus untuk iOS Safari

> ⚠️ **Nginx:** Tambahkan block khusus untuk `sw.js` agar tidak di-cache (lihat bagian Nginx di atas).

---

### 💳 Dana & GoPay Deep Link di Payment POS

Saat kasir memilih Dana atau GoPay sebagai metode bayar, tombol "Buka Aplikasi" akan membuka langsung ke app yang bersangkutan.

**File yang diubah:**
- `plans/UI/src/components/pos/PaymentModal.tsx`
  - Tambah tipe `'dana' | 'gopay'` ke `PaymentMethod`
  - Panel berwarna (biru Dana / hijau GoPay) dengan tombol deep link
  - Dana: `https://link.dana.id/`
  - GoPay: `https://gojek.onelink.me/iAHD/gopay`
  - Icon dari `public/assets/DANA-ICON.png` dan `GOPAY-ICON.jpg`

---

### 📋 UI POS Orders — Card Layout Mobile

Tabel pesanan di `/pos/orders` didesain ulang agar tidak perlu scroll horizontal di mode portrait mobile.

**File yang diubah:**
- `plans/UI/src/pages/pos/PosOrders.tsx` — Ubah dari `<table>` ke card-list per order
  - Baris 1: nama pelanggan + total (kanan)
  - Baris 2: nomor meja · jumlah item · waktu (satu baris)
  - Baris 3: badge status + action bar (Bayar kiri, Print + ubah status kanan)
- `plans/UI/src/layouts/PosLayout.tsx` — Kurangi padding mobile dari `p-6` ke `p-3 sm:p-5 lg:p-6`

---

### 🏠 Dashboard Production-Ready (Hapus Data Statis)

Dashboard member (`/dashboard`) dibersihkan dari data mock yang muncul ke pengguna.

**File yang diubah:**
- `plans/UI/src/pages/member/DashboardHome.tsx`
  - Hapus import `PROMOS` dari `mockData.ts`
  - Pengumuman/promo sekarang dari `getConsumerNotifications()` — **hanya muncul jika super admin mengaktifkan**
  - Kolom tabel riwayat diubah ke Bahasa Indonesia (Keterangan, Tanggal, Nominal, Status)
  - Tipe transaksi `wallet_transaction` → label ramah pengguna

- `plans/UI/src/pages/dashboard/Payments.tsx`
  - Hapus semua teks teknis developer (webhook path, "Ledger", "Gateway credentials", "Kesiapan integrasi")
  - Tab rename: Overview → Ringkasan, Deposit → Isi Saldo, Withdraw → Tarik Saldo
  - Kolom tabel: Type/Direction dihapus → hanya Keterangan + Nominal berwarna (hijau masuk, abu keluar)
  - Kartu hero mobile: stack vertikal agar tidak overflow di portrait

- `plans/UI/src/pages/dashboard/my-purchases.tsx`
  - Mobile (< md): card list per pembelian (tidak ada scroll horizontal)
  - Desktop (md+): tabel tetap ada

- `plans/UI/src/pages/dashboard/products/[slug].tsx`
  - "Gateway iPaymu" → "Pembayaran via iPaymu"
  - "Manual Payment" → "Bayar Manual (Transfer / QRIS)"
  - "Konfirmasi checkout" → "Konfirmasi & Bayar"
  - "Buat ulang checkout" → "Coba Bayar Lagi"

---

## 🚨 Masalah yang Pernah Terjadi & Solusinya

| Masalah | Penyebab | Solusi |
|---------|----------|--------|
| White screen, MIME type error | `base: '/hellom/'` di vite.config → asset path salah | Ubah ke `base: '/'` |
| Build ke folder salah | `outDir: '../backend/public/hellom'` resolve ke `plans/backend/` | Pakai absolute path |
| POS redirect ke localhost | `POS_BASE_URL` tidak ada di `.env` & `config/app.php` | Tambah keduanya |
| Realtime error `ERR_CONNECTION_REFUSED` | Realtime server belum jalan | `pm2 start server.js --name hellom-realtime` |
| SSH key already in use | 1 deploy key hanya untuk 1 repo | Generate key baru di aaPanel |
| POS member dapat akun Hellom platform | `register()` hellomApi dipanggil saat tambah member POS | Hapus — pakai `publicRegister` endpoint baru |
| Poin member di portal selalu 0 | Session localStorage stale, tidak refresh dari server | `refreshFromServer()` dipanggil background tiap load |
| PWA service worker tidak update | Browser cache `sw.js` terlalu lama | Tambahkan `Cache-Control: no-cache` untuk `sw.js` di Nginx |
| Dana/GoPay deep link tidak jalan di iOS | iOS butuh `window.open()` dalam handler user gesture | Pastikan tombol "Buka Aplikasi" ter-trigger langsung dari `onClick` |

---

## 📦 PM2 Services

```bash
# Cek semua service
pm2 list

# Service yang harus running:
# - hellom-realtime (port 3001)
# - himperra-whatsapp
# - maco-wa-service

# Restart jika mati
pm2 restart hellom-realtime
pm2 save
```

---

## 🔧 File yang TIDAK boleh di-commit ke GitHub

```gitignore
# Sudah ada di .gitignore — jangan hapus!
/backend/.env
/backend/vendor/
/backend/node_modules/
/backend/public/build
/plans/UI/node_modules/
/realtime/node_modules/
/realtime/.env
```

> ⚠️ File `backend/public/hellom/` (hasil build) juga sebaiknya masuk `.gitignore` — build selalu dilakukan di VPS setelah pull.

---

## 📝 Checklist Deploy Production

- [x] Buat `plans/UI/.env.production`
- [x] PWA manifest dan service worker (`public/manifest.json`, `public/sw.js`)
- [x] Route publik POS member di `backend/routes/api.php`
- [x] Dana/GoPay deep link di `PaymentModal.tsx`
- [x] Dashboard dibersihkan dari data statis/mock
- [ ] Buat `plans/UI/.env.local` untuk development
- [ ] Pisahkan `vite.config.ts` untuk lokal vs production (atau pakai env untuk `outDir`)
- [ ] Tambah `backend/public/hellom/` ke `.gitignore`
- [ ] Buat script deploy otomatis `scripts/deploy.sh`
- [ ] Tambah Nginx block `no-cache` untuk `sw.js` di server production
- [ ] Jalankan `php artisan migrate` setelah deploy (tabel `pos_members` harus ada)
