# Update VPS: pembayaran dan Analitik & Iklan

Rilis 29 Oktober 2026. Repository: `muhammadrizki7941/HellomV2`, branch tujuan `main`.
Folder di bawah mengikuti panduan deploy proyek. Sesuaikan jika lokasi repo di VPS berbeda.

## 1. Masuk ke terminal VPS / aaPanel

```bash
cd /www/wwwroot/hellomspace.com
git status --short
git branch --show-current
```

Jika `git status --short` menampilkan perubahan, simpan/periksa perubahan server tersebut sebelum lanjut. Jangan menggunakan `git reset --hard` untuk menghilangkannya.

## 2. Backup lalu tarik dan deploy

Jalankan saat status Git bersih:

```bash
bash deploy/backup.sh &&
git fetch origin &&
git switch main &&
BRANCH=main bash deploy/deploy.sh
```

Script deploy sudah menjalankan `git pull --ff-only origin main`, dependency backend, migrasi database, cache Laravel, build frontend, dan restart proses PM2. Tidak perlu menjalankan `git pull` kedua kali. Jika salah satu langkah gagal, berhenti dan periksa pesan error sebelum melanjutkan.

Pastikan `frontend/.env.production` sudah berisi `VITE_HELLOM_API_BASE=https://hellomspace.com/api/v1/hellom`. File `.env` tidak dikirim melalui GitHub. Hasil build juga tidak disimpan di GitHub, sehingga build pada VPS wajib dijalankan oleh script deploy.

## 3. Periksa hasil

```bash
git log -1 --oneline
pm2 status
php backend/artisan migrate:status
php backend/artisan schedule:list
curl -sS https://hellomspace.com/api/health
```

Migrasi `2026_10_28_120000_create_platform_marketing_events` harus berstatus `Ran`. Daftar jadwal harus memuat `platform-meta-retry`. Jadwal terdaftar saja belum memastikan cron aktif: cek tugas `schedule:run` setiap menit di aaPanel → Cron (atau crontab yang digunakan server). Jangan memasang duplikat cron bila sudah ada.

## 4. Aktifkan Meta

1. Masuk Super Admin → Analitik & Iklan → Integrasi Meta.
2. Isi Pixel/Dataset ID, access token Conversions API, dan aktifkan integrasi. Simpan.
3. Masukkan Test Event Code dari Events Manager, simpan, kemudian klik Kirim event tes.
4. Pastikan status diterima dan event muncul pada Test Events dataset yang benar.
5. Buat tautan kampanye dengan domain produksi pada tab Tautan kampanye.

Analitik internal aktif tanpa token Meta, tetapi hanya merekam pengunjung yang mengizinkan pelacakan. Angka dimulai sejak fitur diaktifkan, bukan data historis.

## Perubahan harga

Checkout baru memakai harga total paket dari Super Admin, tanpa pengali tahunan otomatis ×10. Periksa harga paket tahunan sebelum menerima checkout baru. Invoice, checkout, dan nominal langganan lama tidak diubah otomatis oleh rilis ini.

Panduan lengkap: [PLATFORM_MARKETING.md](PLATFORM_MARKETING.md).
