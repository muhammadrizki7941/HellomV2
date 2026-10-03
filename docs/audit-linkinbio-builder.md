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

## 7. Keputusan pemilik (Fase 0) & status Fase 1
Keputusan: (1) skema v2 di awal Fase 1 — **ya**; (2) editor lama diganti penuh & disempurnakan; (3) font di-*self-host*; (4) domain embed TikTok/IG/Spotify boleh ditambah di CSP; (5) foto berlisensi bebas disimpan di server kita; (6) banner video = **link YouTube saja**, bukan upload; (7) pilihan onboarding teks saja.

**Fase 1 selesai (menunggu konfirmasi):**
- **Skema berversi** — `App\Support\Landing\DocumentMigrator` (`schema_version`, `CURRENT = 2`), dipanggil di `BlockSchema::normalize()`: draft, versi terbit, dan riwayat di-upgrade saat dibaca; baris lama tidak ditulis ulang massal, versi lama tetap bisa dipulihkan. v2 sengaja **aditif** (blok `profile`/`social` dan `theme` tetap): memindahkan profil/sosial ke "header" akan membuat editor lama menghapus profil saat menyimpan sebelum Fase 2 terbit. Field baru masuk per fase lewat langkah migrasi.
- **Onboarding adaptif** — pertanyaan "Sebelumnya kamu terbiasa pakai apa?" (lynk.id / Linktree / OrderHero / Belum pernah) saat pertama membuka builder; disimpan di `users.builder_preference` (+ `builder_tour_done_at`), API `GET|PUT /apps/landing-builder/editor-preference`. Preset (`frontend/.../landing-builder/presets.ts`): istilah (blok / link / bagian), urutan galeri blok, template awal ("Cocok untukmu" di wizard), mode terpandu, dan tata letak yang dipakai editor baru (Fase 2). Tur singkat bisa dilewati (`EditorTour`, target `data-tour`), bisa diulang & pilihan diubah di Pengaturan › Gaya editor. Wizard toko menunggu pertanyaan ini (tidak ada dua dialog bertumpuk).
- **Perbaikan yang ketemu saat tes**: editor desktop untuk halaman kosong kini membuka panel blok (sebelumnya tidak ada tombol tambah yang terlihat); editor tidak lagi terkunci "Paket kamu bisa punya 1 halaman" bila halaman pertama dibuat dua kali bersamaan (dua tab / React dev).
- **Tes**: `tests/Landing/EditorFoundationTest` (upgrade v1 → v2 tanpa kehilangan isi, halaman terbit lama tetap tampil, preferensi per user, kasir ditolak) — suite 106 tes OK; e2e `tests/e2e/builder-onboarding.mjs` 8/8 (1366 px & 360 px), `builder-images.mjs` 6/6.
- **Catatan**: pembuatan halaman di backend belum mengunci baris organisasi, jadi dua permintaan bersamaan secara teori bisa melewati kuota halaman (minor) — diperbaiki bersama Fase 2 bila disetujui.

## 8. Status Fase 2 — editor link-in-bio (menunggu konfirmasi)
Keputusan pemilik: pratinjau dirender server (saran) — **ya**; blok lama tidak ditawarkan lagi di galeri — **ya**; gambar template diatur dinamis oleh owner dari dashboard super admin — dikerjakan di Fase 7 (template = data + unggah gambar).
- **Editor baru** (`landing-builder/Editor.tsx` + `editor/*`), editor lama dihapus (`DesktopEditor`, `MobileEditor`, `BlockRenderer`, `BlockToolbox`, `blockCatalog` — tidak dipakai lagi; ada di riwayat git). Desktop: daftar blok ↔ pengaturan blok ↔ galeri di panel kiri, mockup HP di kanan (preset "preview dulu" membalik urutan). HP: pratinjau penuh + bilah bawah (Daftar · Tambah · Tampilan), daftar/galeri/pengaturan sebagai bottom sheet. Satu bilah atas: halaman, status "Tersimpan", Urungkan/Ulangi (juga Ctrl+Z / Ctrl+Shift+Z), Template, Tampilan, Riwayat, Lihat halaman, Salin link, Terbitkan.
- **Pratinjau = halaman asli**: `POST /apps/landing-builder/site/pages/{id}/render` merender dokumen yang belum disimpan dengan view Blade publik (mode editor: penanda `data-hl-block`, skrip `resources/views/landing/editor.js`, tanpa tracking). Ditampilkan di iframe `sandbox="allow-scripts"` tanpa same-origin (konten penjual terpisah dari sesi dashboard; Chrome menjalankannya di proses sendiri), komunikasi hanya `postMessage`; dua iframe bergantian supaya tidak berkedip dan posisi gulir tetap. Ketuk blok di pratinjau → pengaturannya terbuka; pilih di daftar → pratinjau menyorot & menggulir.
- **Galeri "+ Tambah" bergambar** (`editor/BlockThumb.tsx`, gambar SVG buatan sendiri), "Disarankan untukmu" mengikuti preset, pencarian. Blok lama (hero, features, cta, content, list, gif, html) tetap tampil & bisa diedit di halaman yang memakainya.
- **Daftar blok**: geser (mouse, sentuh tahan, keyboard Spasi+panah), tampil/sembunyi, duplikat, hapus (bisa diurungkan). Panel pengaturan: isi dulu, "Gaya bagian ini" dilipat di bawah.
- **Perbaikan**: tombol tanpa link & blok yang belum lengkap tetap terlihat di pratinjau editor dengan petunjuk "ketuk untuk melengkapi" (di halaman publik tetap disembunyikan); pembuatan halaman mengunci baris toko sehingga kuota tidak bisa dilewati dua permintaan bersamaan.
- **Tes**: `EditorFoundationTest` (render: isi belum disimpan, blok tersembunyi tidak tampil, URL berbahaya dibuang, tidak menyimpan apa pun, toko lain 404; kuota halaman) — suite 108 tes OK; e2e `builder-editor.mjs` 16/16, `builder-images.mjs` 6/6 (foto di dalam pratinjau iframe), `builder-onboarding.mjs` 8/8; helper bersama `tests/e2e/cdp.mjs`.

## 9. Status Fase 3 — jenis blok + ongkir kurir asli (menunggu konfirmasi)
Keputusan pemilik: ongkir produk fisik terhubung ke layanan ongkir **RajaOngkir** (Komerce) dengan estimasi otomatis; **biaya layanan Hellom hanya dari harga produk**, ongkir utuh untuk penjual.
- **Ongkir kurir asli** — `App\Services\Shipping\*`: `ShippingProvider` + `RajaOngkirProvider` (API v1 Komerce: `GET destination/domestic-destination`, `POST calculate/domestic-cost`, header `key`; sesuai dokumentasi resmi), `ShippingSettings` (super admin › Pengaturan › Ongkir; API key terenkripsi, tidak pernah dikirim balik/dicatat; tombol Tes koneksi), `ShippingService` (cache pencarian 7 hari & tarif per rute+berat, default 12 jam, supaya kuota gratis 100 cek/hari cukup; percobaan ulang hanya saat jaringan putus).
- **Penjual**: Pengaturan › Pengiriman (kecamatan asal + kurir yang ditawarkan, `organizations.landing_shipping`); produk fisik mode **Ongkir otomatis** (berat wajib).
- **Pembeli**: di checkout cukup cari kecamatan (kota/kode pos ikut otomatis) → daftar kurir + estimasi hari + harga (termurah terpilih) → total ikut berubah. Server **menghitung ulang** tarif kurir yang dipilih saat pesanan dibuat; harga dari browser tidak dipercaya. Pesanan menyimpan kurir ("JNE REG"), tujuan, dan estimasi.
- **Biaya layanan**: `FeeCalculator::split(…, feeBase)` — persen dihitung dari harga produk saja (berlaku juga untuk ongkir tetap); biaya gateway tetap dari total yang dibayar (ditanggung Hellom).
- **Blok baru**: Spasi, Tombol WhatsApp (nomor sendiri atau nomor halaman, tombol/kartu), Embed (Spotify, TikTok, Instagram, YouTube — iframe resmi saja, `App\Support\Landing\Embed`; CSP `frame-src` ditambah domain tersebut), Video kini menerima link TikTok. Galeri: kartu **Produk digital** & **Produk fisik** (pemilih produk hanya menampilkan jenis itu). Panel memberi tahu langsung apakah link embed dikenali.
- **Tes**: `ShippingTest` (kunci rahasia, aturan penjual, cache + bentuk permintaan, harga dihitung server, biaya dari produk saja), `LinkInBioBlocksTest` (pengenalan link aman, render blok baru, CSP) — suite 114 tes OK; e2e `builder-shipping.mjs` 6/6 (penjual atur asal → pembeli 360 px pilih JNE REG → bayar → pesanan lunas dengan ongkir & biaya benar), `builder-blocks.mjs` 6/6, editor 16/16, gambar 6/6, onboarding 8/8. RajaOngkir tiruan di `mocks.mjs` (`RAJAONGKIR_SANDBOX_URL`).
- **Untuk produksi**: daftar akun RajaOngkir (rajaongkir.com / Komerce), isi API key di super admin › Pengaturan › Ongkir, pilih kurir, Tes koneksi; `php artisan migrate`. Belum termasuk: booking kurir/resi otomatis (RajaOngkir hanya tarif; penjual tetap mengirim & mengisi resi seperti sekarang).
