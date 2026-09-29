# Uji Hellom Page — otomatis & manual di HP

## 1. Uji otomatis (sudah dijalankan, Fase 5 — 2026-09-29)

| Uji | Hasil |
|---|---|
| PHPUnit `phpunit.pos.xml` (POS + Landing) | 62 tes OK |
| PHPUnit `--testsuite Unit` | 28 tes OK |
| Perjalanan lengkap di browser (`backend/tests/e2e/journey.mjs`, 390 px) | **16/16 langkah OK** |
| Audit UI 360 px (`backend/tests/e2e/ui-audit.mjs`, 29 halaman) | Tanpa scroll horizontal, 0 target < 44 px, 0 input < 16 px |
| Lighthouse mobile halaman toko (SSR) | Performance 98–100, Accessibility 100, Best Practices 100, SEO 100; 31 KB |
| CSP halaman toko dengan pixel Meta/GA4/TikTok | 0 pelanggaran; pixel baru dimuat setelah "Terima" |
| `balance:reconcile` setelah perjalanan | Saldo = ledger |
| Mode gelap & terang dashboard (`dark-audit.mjs`, 20 tampilan) | 0 teks di bawah 4,5:1; halaman publik tetap terang |
| Captcha Turnstile di browser (`captcha.mjs`, test key Cloudflare) | Muncul setelah 3 checkout, tombol Bayar terkunci sampai lolos, lalu lanjut ke pembayaran |

*Diperbarui 30 Sep 2026: perjalanan 16/16 diulang dengan KTP & rekening dari Saldo; audit 360 px 32 halaman bersih.*

Perjalanan yang diuji (di database uji, gateway & email diganti tiruan lokal):
daftar penjual → wizard (username, template, produk Drive) → produk file → halaman terbit →
pembeli checkout **tanpa login** → bayar di halaman sandbox iPaymu → webhook → cek status & nominal ke
API gateway → email akses terkirim → halaman akses / unduh file → Saldo Penjualan (2 penjualan:
Rp75.000 + Rp60.000, biaya Rp4.500/penjualan) → verifikasi email lewat link → isi KTP & rekening →
super admin setujui → tarik **Rp50.000** → super admin "Proses" + "Tandai berhasil" dengan bukti →
penjual melihat "Berhasil" + email. Sisa saldo Rp76.000 sesuai hitungan.

Cara menjalankan ulang: [backend/tests/e2e/README.md](../backend/tests/e2e/README.md).

## 2. Uji manual di HP (sebelum rilis)

Pakai **HP sungguhan** (bukan hanya mode HP di laptop): 1 Android (Chrome) dan 1 iPhone (Safari).
Pakai toko uji dan nominal kecil di gateway **sandbox** (atau produksi dengan produk Rp10.000 lalu refund).
Centang tiap baris di kedua HP.

### A. Penjual baru

| # | Langkah | Yang dicek | Android | iPhone |
|---|---|---|---|---|
| A1 | Daftar di `/register` | Keyboard email muncul untuk kolom email; layar **tidak zoom** saat mengetik | ☐ | ☐ |
| A2 | Buka Hellom Page | Wizard "Pilih alamat toko kamu" muncul sendiri; tombol "Simpan & lanjut" terlihat di bawah (tidak tertutup keyboard setelah keyboard ditutup) | ☐ | ☐ |
| A3 | Username sudah dipakai / pakai huruf besar | Pesan jelas dalam Bahasa Indonesia; huruf besar otomatis jadi kecil | ☐ | ☐ |
| A4 | Pilih template, tambah produk Drive | Harga terformat "75.000"; panduan akses Drive bisa dibuka | ☐ | ☐ |
| A5 | "Terbitkan halaman" | Layar "Halaman kamu sudah online!"; **Salin link**, **WhatsApp**, **Bagikan** (share sheet HP) semua jalan | ☐ | ☐ |
| A6 | Produk → Tambah produk → Upload file (PDF dari galeri/Files) | Pemilih file HP terbuka; file tersimpan; notifikasi "Produk dibuat" | ☐ | ☐ |
| A7 | Overview | Checklist "Siapkan toko kamu" benar (username, produk, halaman ✔) | ☐ | ☐ |
| A8 | Editor: ubah teks, tunggu | "Tersimpan …" muncul; halaman publik **belum** berubah sampai "Terbitkan" | ☐ | ☐ |

### B. Pembeli (buka link toko dari WhatsApp / Instagram, tanpa login)

| # | Langkah | Yang dicek | Android | iPhone |
|---|---|---|---|---|
| B1 | Buka link toko dari chat WhatsApp | Pratinjau link WhatsApp berisi judul + gambar; halaman terbuka cepat (< 2 dtk di 4G) | ☐ | ☐ |
| B2 | Buka dari **browser dalam aplikasi** Instagram/TikTok | Tampilan tidak rusak; tombol Beli jalan | ☐ | ☐ |
| B3 | Banner cookie | "Terima"/"Tolak" bisa ditekan; tidak muncul lagi setelah memilih | ☐ | ☐ |
| B4 | Beli → form checkout | Tidak ada zoom saat mengetik; salah ketik email (mis. `gmial.com`) diberi saran; tombol **Bayar sekarang** menempel di bawah | ☐ | ☐ |
| B5 | Pilih VA / e-wallet → bayar | Pindah ke halaman gateway; setelah bayar kembali ke `/pesanan/…` dan status berubah jadi berhasil tanpa refresh | ☐ | ☐ |
| B6 | Pilih QRIS | QR tampil di halaman; bisa discan dari HP lain; status berubah otomatis | ☐ | ☐ |
| B7 | Email "Pembayaran berhasil" (Gmail app) | Link akses terbuka di browser; tombol **Buka Google Drive** / **Unduh** jalan; file PDF bisa dibuka (iPhone: Files/Preview) | ☐ | ☐ |
| B8 | `/cek-pesanan` dengan email + nomor pesanan | Email akses dikirim ulang | ☐ | ☐ |
| B9 | Putar HP ke landscape di checkout & halaman toko | Tidak ada scroll ke samping | ☐ | ☐ |

### C. Uang & penarikan

| # | Langkah | Yang dicek | Android | iPhone |
|---|---|---|---|---|
| C1 | Tab Saldo setelah penjualan | Rincian harga – biaya = bersih; saldo tersedia sesuai | ☐ | ☐ |
| C2 | Checklist → "Verifikasi email" → buka link di email | Status jadi terverifikasi | ☐ | ☐ |
| C3 | Checklist → "Data diri & rekening" | Terbuka formulir KTP & rekening di tab Saldo; foto KTP bisa diambil dari kamera; setelah terverifikasi ada tombol "Ganti rekening" | ☐ | ☐ |
| C4 | Setelah disetujui admin: Tarik dana Rp50.000 | Sheet tarik dana: tombol nominal cepat, "Ajukan penarikan"; email "diajukan" | ☐ | ☐ |
| C5 | Admin tandai berhasil (dari laptop) | Penjual melihat "Berhasil" + email berisi info transfer | ☐ | ☐ |

### D. Umum

| # | Yang dicek | Android | iPhone |
|---|---|---|---|
| D1 | Semua tombol mudah ditekan dengan jempol (tidak salah tekan) | ☐ | ☐ |
| D2 | Pesan error berbahasa Indonesia dan jelas (coba matikan internet saat simpan) | ☐ | ☐ |
| D3 | Notifikasi sukses (toast) muncul setelah simpan/salin | ☐ | ☐ |
| D4 | Menu › Tema: **Gelap** dan **Ikuti sistem** (ubah mode gelap HP) — semua teks terbaca, halaman toko & checkout tetap terang, editor menampilkan warna asli halaman | ☐ | ☐ |
| D6 | Checkout 4× berturut-turut dari HP yang sama: kotak "pastikan kamu bukan robot" muncul, lolos, lalu pembayaran jalan (setelah kunci Turnstile diisi) | ☐ | ☐ |
| D5 | Safari iPhone: bar alamat bawah tidak menutupi tombol yang menempel di bawah | ☐ | ☐ |
