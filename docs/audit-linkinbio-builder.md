# Audit Hellom Page Builder → gaya link-in-bio (Fase 0)

Branch `feat/linkinbio-builder` (dari `main`). Tanggal: 2026-10-03. Status: **Fase 0 selesai — menunggu konfirmasi sebelum Fase 1.**

## 0. Koreksi premis brief
| Brief | Kenyataan di repo |
|---|---|
| Laravel 11, React 18 | Laravel **12**, React **19** + Vite 6 + TS strict + Tailwind 4 |
| Frontend di `plans/UI/` | UI satu-satunya di **`frontend/`** (`src/pages/apps/landing-builder/*`) |
| Tenant via `pos_tenant_slug` | `pos_tenant_slug` hanya untuk POS. Hellom Page memakai **`organization_id`** (keputusan Q1 sebelumnya) — tetap begitu |
| Cek `php artisan storage:link` | Sengaja **tidak dipakai**: file publik dilayani route `/media/{path}` (Laravel) dan alias Nginx `location ^~ /media`. Jangan jalankan `storage:link` (aturan proyek) |
| Builder "seperti WordPress", belum ada fitur dasar | Banyak fondasi sudah ada (lihat §4): dokumen JSON berversi, autosave + terbit + riwayat, sembunyikan/duplikat/urutkan blok, 24 jenis blok, 5 template, 7 tema, OG meta, statistik kunjungan & klik, WebP + `srcset` |

## 1. Peta struktur saat ini
| Lapisan | Lokasi | Catatan |
|---|---|---|
| Halaman dashboard | `frontend/src/pages/apps/LandingBuilder.tsx` | Tab: Overview, Produk, Pesanan, **Editor**, Kupon, Pelanggan, Saldo, Statistik, Pengaturan |
| Editor | `landing-builder/Editor.tsx` (state, autosave 1,5 dtk, revisi) → `components/DesktopEditor.tsx` (≥1024 px) / `components/MobileEditor.tsx` | Dua UI terpisah untuk desktop & HP |
| Panel properti | `components/PropertyPanel.tsx` (907 baris) | Satu panel besar untuk semua jenis blok, dimulai dengan "Tata letak" & "Warna" |
| Galeri blok | `components/BlockToolbox.tsx` + `blockCatalog.ts` | Kategori Populer/Order/Penjualan/Lainnya, ikon + teks (tanpa gambar) |
| Render pratinjau | `components/BlockRenderer.tsx` | React, terpisah dari render publik |
| Tema & template | `constants.ts` (7 tema warna), `templates.ts` (5 template), `components/SettingsModal.tsx` (huruf 4 pilihan, bentuk tombol 3, gaya tombol 2) | |
| Tipe | `landing-builder/types.ts` (`BlockType`, `Block{id,type,hidden,content,styles}`) | |
| API klien | `frontend/src/services/api/landingSite.ts` | `/apps/landing-builder/site/pages/{id}/document` (GET/PUT), `/publish`, versi, `/assets/upload` |
| Skema & simpan | `App\Support\Landing\BlockSchema` (allowlist field per blok, sanitasi URL), `App\Services\Landing\LandingDocumentService` (draft + revisi, publish → `landing_page_versions`) | Dokumen `{version:1, theme, settings, blocks[]}` |
| Halaman publik | `routes/web.php` `/{username}/{slug?}` → `LandingPublicController` → Blade `resources/views/landing/{layout,block}.blade.php` (SSR, cache, CSP `PageSecurity`) | Render ketiga (Blade) selain editor & `PublicPage.tsx` (fallback dev) |
| Upload | `FileAssetController::upload` → disk `public`, folder `landing-builder/{org}`, foto → WebP ≤1600 px + salinan 480/960 w (`ImageOptimizer`), dedup hash, kuota 100 MB/org | URL disimpan relatif: `/media/landing-builder/{org}/x.webp` |
| Tabel | `organization_landing_pages` (`draft_document`, `draft_revision`, `published_version_id`), `landing_page_versions`, `file_assets`, `landing_products`, `landing_stats_daily`, `landing_blocks` (format lama, baca-saja) | Semua ber-`organization_id` |

## 2. Bug gambar tidak ter-load — akar masalah & perbaikan (commit `ab00c85`)
Direproduksi di browser (headless Chrome) dan lewat API, bukan dari membaca kode saja.

**Akar masalah A — editor menampilkan path relatif apa adanya.** Upload mengembalikan `/media/landing-builder/…webp` (benar, portabel). Editor memasangnya langsung ke `<img src>`; di setup dev (dashboard Vite `:3000`, Laravel `:8000`) browser meminta `localhost:3000/media/…` → Vite menjawab **200 `text/html`** (halaman aplikasi), jadi gambar kosong. Bagian lain aplikasi sudah memakai `getImageUrl()`, builder belum. Terbukti: `naturalWidth = 0` di pratinjau editor sebelum perbaikan, terisi sesudahnya.

**Akar masalah B — foto HP ditolak.** Editor bilang "File maksimal 8 MB", server membatasi **4 MB** dan menjawab dalam bahasa Inggris ("The file field must not be greater than 4096 kilobytes."). Foto kamera HP umumnya 3–8 MB → upload gagal. Ini terjadi juga di **produksi**. Slider masih punya batas 1 MB sisa era base64.

**Yang dicek dan bukan penyebab:** `storage:link` (tidak dipakai, `/media` dilayani route/Nginx), `APP_URL` (URL disimpan relatif), CORS/mixed content (produksi satu origin `https://hellomspace.com`, dicek dari bundle JS; `/media/*.png` produksi menjawab 200 `image/png` lewat Cloudflare), blob URL (state diisi URL server), allowlist skema (path `/media/…` dipertahankan), `srcset` (hanya mencantumkan salinan yang ada), CSP halaman publik (`img-src https:` + `'self'`).

**Perbaikan:**
- Builder (`BlockRenderer`, `PropertyPanel`, `ProductsPanel`) memakai `getImageUrl()`; `url()` CSS dikutip.
- `vite.config.ts`: proxy dev `/media` & `/storage` → origin API (semua halaman lain yang memakai path relatif ikut beres di dev).
- Batas upload **8 MB** (`FileAssetController::MAX_UPLOAD_KB`), pesan Bahasa Indonesia; batas slider ikut 8 MB.

**Tes agar tidak terulang:** `tests/Landing/LandingAssetUploadTest.php` (foto 5 MB → WebP → `/media` menjawab `image/webp` → draft → terbit → `<img>` di halaman SSR; 9 MB/SVG → pesan jelas; terbukti gagal di kode lama) dan e2e `tests/e2e/builder-images.mjs` (6/6: upload 4,7 MB, terbit, SSR, proxy dev, pratinjau editor 1366 px & 360 px).

**Perlu dicek di VPS:** `upload_max_filesize` & `post_max_size` PHP ≥ 10M dan `client_max_body_size` Nginx ≥ 10m — kalau lebih kecil, upload 4–8 MB akan ditolak sebelum sampai ke Laravel.

## 3. Yang membuat editor terasa "WordPress" & usulan
| Sekarang | Usulan (link-in-bio) |
|---|---|
| Dua bilah alat bertumpuk di desktop: atas (Beranda, Pratinjau, Riwayat) + bawah (Mode Draf, 7 titik tema, ID/EN, Template, Pengaturan, Pratinjau, Simpan, Terbitkan). "Pratinjau" muncul dua kali, tombol "Simpan" padahal sudah autosave | Satu bilah tipis: nama halaman · indikator "Tersimpan" · undo/redo · Lihat halaman · Salin link · **Terbitkan**. Tema/huruf pindah ke tab **Tampilan** |
| Kanvas desktop = halaman lebar seperti page builder, blok bisa di-drop di mana saja | Kiri: daftar blok (drag), kanan: **mockup HP** (seperti tampilan mobile sekarang). Satu komponen untuk desktop & HP (HP = pratinjau penuh + bottom sheet) |
| Panel properti dibuka dengan "Tata Letak" (jarak atas-bawah, perataan) + "Warna" (latar, teks, gambar latar per blok) sebelum isi | Isi dulu (teks, link, gambar). Gaya per blok diringkas ke "Gaya" yang bisa dibuka; gaya global di Tampilan |
| 24 jenis blok, beberapa tumpang tindih: `hero`/`banner`/`cta`/`content`/`features`/`list` (pola landing page panjang), `gif` vs `image`, `catalog` vs `product`, `html` | Galeri bergambar ±15 blok inti link-in-bio (Fase 3). Blok lama tetap dibaca & dirender (migrasi skema v2) |
| Istilah teknis: "Block", "Mode Draf", padding `py-8/16/24` | Bahasa sehari-hari: "Tambah", "Belum diterbitkan", Kecil/Sedang/Besar |
| Pengaturan tombol hanya bentuk (3) + solid/outline | Gaya tombol global + per tombol (Fase 5) |
| Belum ada undo/redo | Riwayat perubahan di memori editor (Fase 2) |
| Tiga renderer berbeda (editor React, Blade publik, `PublicPage.tsx` fallback) → tampilan editor ≠ halaman asli | Pratinjau di mockup HP memakai **halaman SSR asli** (draft bertanda tangan) atau satu set komponen yang sama; target "yang dilihat = yang terbit" |

## 4. Brief vs yang sudah ada
| Fitur brief | Status |
|---|---|
| Autosave + "Tersimpan", terbitkan, riwayat versi | **Ada** |
| Sembunyikan/duplikat/hapus/drag urutan | **Ada** |
| Undo/redo | Belum |
| Klik blok di pratinjau → buka pengaturan | **Ada** (desktop & HP) |
| Lihat halaman & salin link | Ada |
| Blok: profil, tombol, produk (terhubung checkout), gambar, slider, video YouTube, teks, divider, FAQ, testimoni, formulir, countdown, embed HTML | **Ada** (24 jenis) |
| WhatsApp CTA | Ada (`cta`/`button` WA + widget mengambang) |
| Spacer, produk fisik terpisah, video TikTok | Belum / sebagian (produk fisik ada di produk, bukan blok khusus) |
| Panel Sosial Media terpisah (18 platform) | Belum — sekarang blok `social` 7 platform |
| Background (gradien, gambar + overlay, pola, animasi) | Belum — hanya warna tema & gambar latar per blok |
| Galeri tema, Google Fonts, gaya tombol variatif | Sebagian: 7 tema warna, 4 huruf sistem, 3 bentuk + 2 gaya |
| Skema berversi + migrasi data lama | Ada `version: 1` + konversi `landing_blocks` lama; v2 perlu dibuat |
| WebP, lazy-load, OG meta | **Ada** |
| Analitik kunjungan & klik per tombol | **Ada** (`landing_stats_daily`, dimensi = label tombol) — perlu per blok/link ID |
| Isolasi tenant | Ada (`organization_id`, tes `test_pages_are_isolated_between_sellers…`) |
| Banner header (gambar/video), animasi masuk, YouTube lite-embed + oEmbed, 12 template premium | Sebagian: YouTube sudah thumbnail dulu + `youtube-nocookie`; sisanya belum |

## 5. Rencana singkat
Skema dokumen **v2** dibuat pertama karena semua fase bergantung padanya: `theme` diperluas (background, font, tombol, animasi), `profile`/`banner`/`social` jadi bagian halaman (bukan blok bebas), setiap blok punya `style` opsional; v1 → v2 dikonversi otomatis saat dimuat (halaman lama tidak rusak, versi terbit lama tetap bisa dipulihkan). Blade publik & pratinjau editor membaca skema yang sama. Lalu fase sesuai brief (1 onboarding → 7 template), commit terpisah, berhenti tiap fase.

## 6. Keputusan yang dibutuhkan
1. **Urutan**: boleh mengerjakan skema v2 (bagian dari Fase 6) di awal Fase 1, karena onboarding/preset, tampilan, sosial media, dan template semuanya menyimpan ke skema itu?
2. **Editor lama**: diganti penuh oleh editor baru (data tetap, dikonversi), atau disediakan sementara tombol "Editor lama" selama masa transisi?
3. **Font**: Google Fonts di-*self-host* (lebih cepat di 3G, CSP tetap `font-src 'self'`, rekomendasi) atau dimuat dari `fonts.googleapis.com` (perlu ubah CSP)?
4. **Embed pihak ketiga** (TikTok, Instagram Reels, Spotify): perlu menambah domain mereka di CSP `frame-src` halaman toko — setuju? (Embed tanpa script; hanya iframe resmi.)
5. **Gambar contoh template**: buat sendiri (ilustrasi/gradien/foto yang Hellom miliki) atau pakai foto berlisensi bebas (mis. Unsplash/Pexels, disimpan di server kita, bukan hotlink)? Kalau ada aset foto milik Hellom, mohon dikirim.
6. **Video banner** (MP4 ≤10 MB) dihitung ke kuota 100 MB per toko — tetap, atau kuota dinaikkan untuk paket berbayar?
7. **Label onboarding** menyebut "lynk.id / Linktree / OrderHero" hanya sebagai teks pilihan (tanpa logo mereka) — setuju?
