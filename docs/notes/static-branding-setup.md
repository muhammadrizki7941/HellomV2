# Static Branding Setup Guide

## Lokasi File Statis untuk Logo & Favicon

Untuk menampilkan logo dan favicon tanpa harus upload melalui UI, kamu bisa menempatkan file secara statis di folder:

```
backend/public/brand/
```

## File yang Didukung

### Logo Utama
- **Nama file:** `logo.png` (atau `.jpg`, `.webp`, `.svg`)
- **Lokasi:** `backend/public/brand/logo.png`
- **Ukuran yang disarankan:** 200x60 px (rasio 3:1)
- **Format:** PNG, JPG, WebP, SVG
- **Max size:** 2MB

**Contoh:**
```
C:\laragon\app\SelfOrderResto\backend\public\brand\logo.png
```

### Logo Gelap (opsional)
- **Nama file:** `logo-dark.png`
- **Lokasi:** `backend/public/brand/logo-dark.png`
- **Untuk:** Mode gelap atau background gelap
- **Jika tidak ada:** Akan menggunakan `logo.png` sebagai fallback

**Contoh:**
```
C:\laragon\app\SelfOrderResto\backend\public\brand\logo-dark.png
```

### Favicon
- **Nama file:** `favicon.ico` atau `favicon.png`
- **Lokasi:** `backend/public/brand/favicon.ico`
- **Ukuran yang disarankan:** 32x32 px (untuk .ico) atau 16x16-512x512px (untuk .png)
- **Format:** ICO, PNG, WebP, SVG
- **Max size:** 512KB

**Contoh:**
```
C:\laragon\app\SelfOrderResto\backend\public\brand\favicon.ico
```

## Langkah-Langkah Setup

### 1. Buat Folder (jika belum ada)
```bash
mkdir backend/public/brand
```

### 2. Copy File Logo & Favicon ke Folder
Salin file logo dan favicon kamu ke:
```
backend/public/brand/
```

### 3. Nama File Harus Sesuai
| Jenis | Nama File | Contoh Lengkap Path |
|-------|-----------|---------------------|
| Logo Utama | `logo.png` | `C:\laragon\app\SelfOrderResto\backend\public\brand\logo.png` |
| Logo Gelap | `logo-dark.png` | `C:\laragon\app\SelfOrderResto\backend\public\brand\logo-dark.png` |
| Favicon | `favicon.ico` | `C:\laragon\app\SelfOrderResto\backend\public\brand\favicon.ico` |

### 4. Verify di Branding Settings
- Buka halaman **Branding Settings** di admin panel
- Logo dan favicon akan otomatis muncul di preview
- Jika sudah terupload, file statis ini akan di-override oleh file upload

## Priority Order

Sistem akan mencari file dengan urutan prioritas ini:

1. **Database (Upload)** - File yang di-upload melalui UI Branding Settings
2. **Static Files** - File di `public/brand/` (backup/fallback)

Jadi jika user upload melalui UI, itu akan menimpa static files.

## Troubleshooting

### Logo/Favicon tidak muncul?
1. Periksa nama file: `logo.png`, `logo-dark.png`, `favicon.ico`
2. Periksa path: `backend/public/brand/`
3. Periksa format file supported (PNG, JPG, WebP, SVG untuk logo; ICO, PNG, WebP, SVG untuk favicon)
4. Reload halaman admin

### File terlalu besar?
- Logo: Max 2MB
- Favicon: Max 512KB

### Ingin update file statis?
- Hapus file lama
- Copy file baru dengan nama yang sama ke folder `backend/public/brand/`
- Reload halaman

## Setup Quick Command (Windows)

```powershell
# Navigate to backend
cd C:\laragon\app\SelfOrderResto\backend

# Create directory if not exists
mkdir -Force public\brand

# Copy your files (ganti path sesuai lokasi file kamu)
Copy-Item "C:\path\to\your\logo.png" "public\brand\logo.png"
Copy-Item "C:\path\to\your\favicon.ico" "public\brand\favicon.ico"
```

## Akses URL

Setelah di-setup, file bisa diakses di:
- Logo: `http://localhost:8000/brand/logo.png`
- Favicon: `http://localhost:8000/brand/favicon.ico`
