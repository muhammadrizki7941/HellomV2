# Deploy ke VPS (aaPanel + PM2)

Produksi: `hellomspace.com` di VPS Contabo dengan aaPanel (Nginx, PHP 8.2+, MySQL), Node.js 20+ dan PM2.
Path contoh di bawah: `/www/wwwroot/hellomspace.com` (root repo).

## 1. Update rutin (setiap rilis)

```bash
cd /www/wwwroot/hellomspace.com
bash deploy/deploy.sh            # BRANCH=main (default), SKIP_MIGRATE=1 untuk melewati migrate
```

`deploy.sh` menjalankan: `git pull --ff-only` → `composer install --no-dev` → `php artisan migrate --force` → `config:cache`, `route:cache`, `view:cache` → build `frontend/` ke `backend/public/hellom` → `npm ci` realtime → `pm2 startOrReload deploy/ecosystem.config.js`. Tidak ada perintah yang menghapus data.

Setelah deploy, periksa:
```bash
pm2 status                                   # hellom-realtime online
curl -I https://hellomspace.com              # 200, HTML SPA
curl -s https://hellomspace.com/api/v1/hellom/public/brand | head -c 200
curl -s -o /dev/null -w "%{http_code}\n" "https://hellomspace.com/socket.io/?EIO=4&transport=polling"   # 200
crontab -l | grep schedule:run
```

## 2. Instalasi pertama

1. **Repo**: `git clone https://github.com/muhammadrizki7941/HellomV2.git /www/wwwroot/hellomspace.com`.
2. **Backend**: `cp backend/.env.example backend/.env`, lalu isi (lihat §4), kemudian:
   ```bash
   cd backend && composer install --no-dev --optimize-autoloader
   php artisan key:generate && php artisan migrate --force
   chown -R www:www storage bootstrap/cache
   ```
3. **Frontend**: buat `frontend/.env.production`:
   ```env
   VITE_HELLOM_API_BASE=https://hellomspace.com/api/v1/hellom
   VITE_REALTIME_PUBLIC_URL=https://hellomspace.com
   ```
4. **Realtime**: `cp realtime/.env.example realtime/.env`; isi `REALTIME_SERVER_SECRET` (sama dengan backend), `REALTIME_ALLOWED_ORIGINS=https://hellomspace.com`, `REALTIME_REQUIRE_AUTH=true`, `HOST=127.0.0.1`.
5. **Nginx**: salin isi [`deploy/nginx/hellomspace.com.conf.example`](../deploy/nginx/hellomspace.com.conf.example) ke konfigurasi situs aaPanel, sesuaikan path socket PHP-FPM, lalu `nginx -t && nginx -s reload`. SSL via aaPanel (Let's Encrypt).
6. **PM2**: `pm2 start deploy/ecosystem.config.js && pm2 save && pm2 startup`.
7. **Cron** (wajib — masa berlaku langganan & settlement wallet): pasang baris di [`deploy/crontab.example`](../deploy/crontab.example) (aaPanel → Cron → Shell Script, setiap menit).
8. Jalankan `bash deploy/deploy.sh` sekali untuk build frontend dan cache.

## 3. Catatan khusus rilis refactor (branch `refactor/cleanup`)

Lakukan **sekali** saat pertama kali men-deploy hasil refactor:

1. **Rotasi secret realtime**: nilai lama pernah ter-commit di `DEPLOYMENT.MD`. Buat secret baru (`openssl rand -hex 32`), isi di `backend/.env` (`REALTIME_SERVER_SECRET`) **dan** `realtime/.env`.
2. **Folder UI berubah**: `plans/UI` → `frontend/`. Jika `plans/UI/vite.config.ts` pernah diedit langsung di server, buang dulu: `git checkout -- plans/UI` sebelum `git pull`. Folder lama `plans/UI/node_modules` boleh dihapus setelah pull.
3. **`backend/public/hellom/` tidak lagi di-git**: `git pull` akan menghapus salinan lama (ikon, `sw.js`), lalu `deploy.sh` membangunnya ulang. Jalankan pull dan build berurutan (deploy.sh sudah melakukannya).
4. **PM2 lama**: proses `hellom-realtime` yang dulu dibuat manual diganti definisi ecosystem:
   ```bash
   pm2 delete hellom-realtime && pm2 start deploy/ecosystem.config.js && pm2 save
   ```
5. **Paket composer dihapus** (dbal, breeze, sail). Jika artisan error menyebut `SailServiceProvider`: `rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && php artisan package:discover` (deploy.sh sudah melakukannya).
6. **Migration baru**: `users.role_before_suspension` (hanya menambah kolom).
7. **Masa berlaku langganan lama**: jalankan laporan dulu, putuskan, baru terapkan:
   ```bash
   cd backend
   php artisan hellom:billing:backfill-entitlement-ends          # laporan: siapa yang LOCKS NOW
   php artisan hellom:billing:backfill-entitlement-ends --force  # setelah diputuskan
   ```
8. **Periksa data dari celah lama** (read-only, lihat `docs/AUDIT.md` Langkah 0): transaksi `wallet_topup_mock`, invoice `payment_method = mock`, penarikan yang disetujui non-super-admin.

## 4. Variabel lingkungan penting

| File | Variabel | Nilai produksi |
|---|---|---|
| `backend/.env` | `APP_ENV` / `APP_DEBUG` | `production` / `false` (wajib) |
| | `APP_URL`, `FRONTEND_URL` | `https://hellomspace.com` |
| | `DB_*` | kredensial MySQL |
| | `QUEUE_CONNECTION` | `sync` atau `database` (saat ini tidak ada job ber-queue; bila memakai `database`, aktifkan worker di `deploy/ecosystem.config.js`) |
| | `REALTIME_SERVER_URL` / `REALTIME_PUBLIC_URL` / `REALTIME_SERVER_SECRET` | `http://127.0.0.1:3001` / `https://hellomspace.com` / secret baru |
| | `BILLING_MOCK_ENABLED` | `false` (jangan pernah `true`) |
| | `BILLING_GRACE_DAYS` | `0` (atau sesuai kebijakan) |
| | `IPAYMU_*`, `XENDIT_*`, `DOKU_*` | kredensial gateway; callback token acak panjang (atau diisi via dashboard super admin) |
| | `PLATFORM_SALE_COMMISSION_PERCENT`, `WALLET_MIN_WITHDRAWAL`, `WALLET_SETTLEMENT_DELAY_HOURS` | kebijakan platform |
| | `CORS_ALLOWED_ORIGINS` | boleh dikosongkan di produksi (same-origin) |
| `frontend/.env.production` | `VITE_HELLOM_API_BASE`, `VITE_REALTIME_PUBLIC_URL` | lihat §2 |
| `realtime/.env` | `REALTIME_SERVER_SECRET`, `REALTIME_ALLOWED_ORIGINS`, `REALTIME_REQUIRE_AUTH`, `HOST`, `PORT` | lihat §2 |

Daftar lengkap ada di masing-masing `.env.example`.

## 5. Checklist sebelum rilis

- [ ] `frontend`: `npx tsc --noEmit` = 0 error dan `npm run build` sukses
- [ ] `backend`: `php artisan route:list`, `config:cache`, `route:cache` sukses; migration baru dibaca
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `BILLING_MOCK_ENABLED` tidak `true`
- [ ] Secret realtime sudah dirotasi dan sama di backend & realtime
- [ ] Callback token iPaymu/Xendit/DOKU terisi (bukan `dev_*`)
- [ ] Cron `schedule:run` aktif (`php artisan schedule:list` menampilkan 4 jadwal)
- [ ] PM2 `hellom-realtime` online dan `pm2 save` sudah dijalankan
- [ ] Nginx mem-proxy `/socket.io` dan `sw.js` tidak di-cache
- [ ] Backup database sebelum `migrate --force`

## 6. Rollback

```bash
cd /www/wwwroot/hellomspace.com
git log --oneline -5                          # pilih commit yang terakhir berjalan baik
git checkout <commit>                         # server tidak boleh punya perubahan lokal
SKIP_PULL=1 SKIP_MIGRATE=1 bash deploy/deploy.sh
# kembali ke jalur normal nanti: git checkout main && bash deploy/deploy.sh
```
Migration di rilis ini hanya menambah kolom, sehingga kode lama tetap berjalan tanpa rollback database.
