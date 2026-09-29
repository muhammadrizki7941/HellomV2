# Audit Landing Page Builder + Toko Online (Hellom Page) — Fase 1

Tanggal: 2026-09-29 · Branch `refactor/cleanup` · **Tidak ada kode yang diubah di fase ini.**

Metode: baca kode (backend, frontend), uji API lokal (GET saja di DB dev), dan uji UI di browser
(Chrome headless, lebar 360 & 414 px) memakai penjual & halaman uji di database `hellom_pos_test`
yang sudah dihapus lagi. Tidak ada data pelanggan yang dibaca/ditampilkan; angka di bawah hanya agregat.

> Catatan konteks: repo saat ini memakai **Laravel 12** dan frontend di **`frontend/`** (bukan
> `plans/UI/` — dipindah saat refactor). Landing builder memakai **`organization_id`** (bukan
> `pos_tenant_slug`) sebagai identitas penjual di semua tabelnya — lihat pertanyaan Q1.

---

## Status perbaikan (diperbarui 2026-09-29)

| Fase | ID | Status |
|---|---|---|
| Hotfix | LB-18, LB-19, LB-20, LB-30 | ✅ Selesai (`f84c9b4`): field rahasia blok disaring server, HTML disanitasi server + DOMPurify, SVG ditolak/`sandbox`, editor dikunci saat memuat |
| 2 | LB-01 | ✅ Status & nominal dicek ke API gateway (`getStatus`) sebelum settle; beda nominal/referensi → ditolak & dicatat |
| 2 | LB-02 | ✅ HMAC DOKU diverifikasi untuk order landing (`lps_`) |
| 2 | LB-03 | ✅ Pending ≠ gagal; hanya status gateway `failed/expired` yang mengubah order |
| 2 | LB-04 | ✅ Kedaluwarsa 24 jam (`landing:orders expire`) + rekonsiliasi pending (`landing:orders reconcile`) |
| 2 | LB-05 | ✅ Rilis dompet lama melewati baris yang sudah dirilis |
| 2 | LB-07 | ✅ Ledger append-only `seller_balance_ledger` + cache `seller_balances` + `balance:reconcile` |
| 2 | LB-08 | ✅ Saldo penjualan terpisah; penarikan dompet top-up ditutup (`WITHDRAWAL_MOVED_TO_SELLER_BALANCE`). ⚠️ Top-up mock di dompet lama tetap ada — lihat laporan `seller-balance:opening` |
| 2 | LB-09 | ✅ Semua aksi penarikan dalam transaksi + `lockForUpdate`; tes konkuren 2 proses |
| 2 | LB-10 | ✅ Min Rp50.000, biaya, SLA 24 jam (peringatan 20 jam ke super admin), email tiap perubahan status — semua bisa diatur super admin |
| 2 | LB-11 | ✅ Biaya gateway per metode dicatat per order; rincian harga/biaya/bersih tampil ke penjual |
| 2 | LB-12 | ✅ FK `landing_page_orders.organization_id` → `restrict` |
| 2 | LB-13 | ✅ `payment_webhook_logs` (payload mentah, token disamarkan) |
| 2 | LB-14 | ✅ Email lewat queue (`SendLandingSaleEmails`, afterCommit). Tombol kirim ulang → Fase 3 |
| 2 | LB-16 | ✅ `returnUrl` dari `FrontendUrl`; error gateway → pesan ramah + log |
| 2 | LB-28 | ✅ Tes isolasi saldo antar penjual |
| 2 | UI-02 | ✅ Halaman status `/pesanan/{ref}` untuk semua metode (polling saja) |
| 2 | UI-05 | ◐ Tab "Tarik Saldo" di Pembayaran diarahkan ke Saldo Penjualan; tabel riwayat dompet → Fase 5 |
| 3 | LB-14 | ✅ Tombol "Kirim ulang email" di dashboard penjual, halaman akses, dan "Cek pesanan" (maks 3×/10 menit per pesanan) |
| 3 | LB-15, LB-17 | ✅ Produk jadi entitas sendiri (`landing_products`, harga BIGINT); checkout per produk `/beli/{id}`, bukan "halaman terbit terakhir". Checkout blok lama tetap jalan; `landing:products-from-blocks` memindahkannya |
| 3 | LB-21 | ✅ File produk di disk privat (nama acak, maks 10 MB, allowlist ekstensi), unduh lewat URL bertanda tangan 10 menit + batas unduh |
| 3 | LB-22 | ✅ Email berisi link akses Hellom `/akses/{token}`, bukan link mentah; link Drive dibaca dari produk saat dibuka (ganti link = pembeli lama ikut dapat yang baru); link disimpan terenkripsi |
| 3 | LB-25 | ◐ Checkout produk: limiter per IP + per email/produk. Captcha belum (Fase 5) |
| 3 | LB-32 | ✅ Badge tren palsu dihapus; Overview memakai data penjualan sungguhan |
| 3 | LB-35 | ✅ Daftar pesanan + filter + detail, kirim ulang, tandai terkirim, refund lewat ledger, kupon, halaman akses, cek pesanan, tipe produk link/fisik/jasa, batas buka/unduh |
| 3 | LB-24 | ◐ Endpoint baru (produk, kupon, pesanan, saldo) hanya owner/admin; editor halaman masih terbuka untuk semua anggota (Fase 4) |
| 3 | UI-01, UI-02 | ✅ Checkout satu halaman mobile-first (input 16px, target ≥44px, tombol bayar menempel, pilihan QRIS/VA, ringkasan biaya) |
| 4–5 | lainnya | Belum |

**Catatan Google Drive (Fase 3).** Produk Drive memakai link "Siapa saja yang memiliki link". Link asli tidak pernah dikirim ke halaman publik, API publik, atau sebelum lunas, tetapi setelah pembeli membukanya **tetap bisa diteruskan** ke orang lain. Batas buka dan masa berlaku hanya membatasi halaman akses Hellom. Mode lanjutan disiapkan tapi belum dibangun: `landing_products.delivery_mode = google_grant` → penjual menghubungkan akun Google (OAuth, scope `drive.file`/`drive`), dan saat lunas sistem memberi izin *reader* ke email pembeli lewat Drive API (`permissions.create`), lalu mencabutnya saat refund/kedaluwarsa.

## A. Peta modul

### File utama

| Lapisan | File |
|---|---|
| Route | `backend/routes/api/landing-builder.php` (editor, butuh `canUseApp:landing_builder`), `routes/api/public.php` baris 28–44 (halaman publik, checkout, status, download, QR), `routes/api/wallet.php` (saldo, penarikan, KYC), webhook di `public.php` |
| Controller | `Api/V1/Hellom/LandingBuilderController.php` (1.652 baris: halaman, blok, versi, domain, statistik, publik), `Billing/LandingCheckoutController.php` (checkout pembeli), `LandingSaleController.php` (status/download/QR), `FileAssetController.php` (upload aset), `WalletController.php` (1.375 baris: saldo, penarikan, keuangan platform), `PayoutProfileController.php` (KYC KTP + rekening), `XenditWebhookController.php`, `IpaymuWebhookController.php`, `DokuWebhookController.php` |
| Service | `Services/Hellom/LandingSaleService.php` (buat order, settle, email), `Services/Hellom/PaymentGatewaySettingsService.php` (komisi dari admin), `Services/Hellom/PlatformMailService.php` |
| Command | `ReleasePendingWalletSettlementsCommand` (`hellom:wallet:release-pending-settlements`, tiap 15 menit) |
| Model | `OrganizationLandingPage`, `LandingBlock`, `LandingPageVersion`, `LandingDomain`, `LandingStat`, `LandingPageStat`, `CustomerLandingpage` (lead form), `LandingPageOrder`, `FileAsset`, `OrganizationWallet`, `OrganizationWalletTransaction`, `WalletWithdrawalRequest`, `OrganizationPayoutProfile`, `PlatformFinanceLedger`, `PaymentEvent` |
| Frontend | `pages/apps/LandingBuilder.tsx` (tab Overview/Editor/Pelanggan), `pages/apps/landing-builder/*` (Editor 688 baris, MobileEditor, DesktopEditor, PropertyPanel, BlockRenderer, Overview), `pages/public/PublicPage.tsx` (881 baris: render halaman publik + modal checkout + QRIS), `pages/dashboard/Payments.tsx` (dompet), `services/api/landing.ts` |

### Tabel

`organization_landing_pages` (halaman; `content` JSON tema/pengaturan), `landing_blocks` (blok; `content` JSON bebas),
`landing_page_versions` (snapshot `content` halaman saja), `landing_domains`, `landing_stats`, `landing_page_stats`,
`customer_landingpage` (isian form), `landing_page_orders` (order pembeli), `file_assets`,
`organization_wallets` (kolom saldo `available_balance`/`pending_balance` BIGINT), `organization_wallet_transactions`,
`wallet_withdrawal_requests`, `organization_payout_profiles`, `platform_finance_ledgers`, `payment_events`.

Data dev saat audit (agregat): 83 halaman (16 terbit), 1 order landing (pending), 23 dompet
(Σ tersedia Rp5.218.000, **sebagian dari 20 transaksi `wallet_topup_mock`**), 7 penarikan, 0 profil KYC.

### Alur penjual & pembeli

| Langkah | Status |
|---|---|
| 1. Daftar akun + organisasi | ✅ ada (akun Hellom; langganan app `landing_builder`) |
| 2. Pilih username / URL | ⚠️ URL = **slug organisasi** (`hellomspace.com/{org-slug}`), tidak bisa dipilih khusus, tanpa daftar kata terlarang |
| 3. Buat halaman (editor blok) | ✅ ada; ⚠️ editor hanya membuka **halaman pertama** organisasi |
| 4. Template | ⚠️ hanya 2 template, keduanya restoran (`restaurant-basic`, `reservation-focus`) |
| 5. Tambah produk | ⚠️ produk = **blok** (`product`, `pdf`) dengan harga teks ("Rp 49.000"); tidak ada katalog produk, stok, harga coret, varian, kupon |
| 6. Publish | ⚠️ tidak ada pemisahan draft/live (lihat LB-06) |
| 7. Pembeli checkout (tanpa login) | ✅ modal nama/email/HP → gateway (Xendit/iPaymu/DOKU/QRIS iPaymu) |
| 8. Bayar → webhook → order paid | ✅ ada, ⚠️ nominal tidak dicek, tanpa kedaluwarsa & rekonsiliasi |
| 9. Produk terkirim | ⚠️ email berisi **link file mentah**; tidak ada halaman akses; halaman terima kasih hanya untuk QRIS |
| 10. Saldo penjual | ⚠️ dompet bersama (penjualan + top-up + langganan), kolom saldo di-update langsung |
| 11. Tarik dana | ✅ ada (KYC wajib, admin approve, Xendit payout/manual), ⚠️ minimal Rp100.000 |
| Daftar order untuk penjual | ❌ **belum ada** (tidak ada endpoint maupun UI) |
| Halaman akses / cek pesanan pembeli | ❌ belum ada (endpoint `download` ada tapi tidak dipakai UI) |
| Refund, kupon, produk fisik/jasa/link, upload file produk privat | ❌ belum ada |
| Pixel/iklan, OG per halaman, SSR | ❌ belum ada |

---

## B. Pembayaran

Gateway yang dipakai sekarang: **Xendit** (Payment Session), **iPaymu** (redirect & QRIS direct), **DOKU** (Checkout);
aktif satu, dipilih super admin (`PaymentPolicy`/`PaymentGatewaySettingsService`). Uang masuk ke akun Hellom ✅.

Verifikasi webhook:
- Xendit: header `X-CALLBACK-TOKEN` + `hash_equals` ✅.
- iPaymu: token statis di **query string** URL notifikasi; iPaymu tidak menandatangani notifikasi, jadi status sukses
  tidak dikonfirmasi ulang ke API iPaymu.
- DOKU: token statis di query string; **tanda tangan HMAC resmi DOKU (`Signature`, `Client-Id`, `Request-Timestamp`) tidak diverifikasi**.

Idempotensi: `settlePaidOrderByReference` mengunci order (`lockForUpdate`) dan berhenti bila sudah paid ✅ — webhook
dobel tidak menambah saldo dua kali untuk order landing. `payment_events` menyimpan payload, tetapi `updateOrCreate`
menimpa payload lama pada event id yang sama (riwayat mentah hilang).

## C–E. Temuan

Severity: **KRITIS** = bisa dieksploitasi sekarang untuk mencuri uang/produk/akun · **TINGGI** = kerugian nyata atau
data rusak dalam pemakaian normal · **SEDANG** = celah terbatas / fitur inti kurang · **RENDAH** = kerapian.

### Pembayaran, saldo & penarikan

| ID | Kategori | Severity | File:baris | Masalah | Dampak | Rekomendasi |
|---|---|---|---|---|---|---|
| LB-01 | Pembayaran | **KRITIS** | `LandingSaleService.php:98-191`; pemanggil di `XenditWebhookController.php:96-111`, `IpaymuWebhookController.php:140-145`, `DokuWebhookController.php:59-68` | **Nominal webhook tidak dicocokkan** dengan `landing_page_orders.amount`; order dianggap lunas hanya dari `reference_id` + status | Bila token callback bocor / notifikasi palsu (iPaymu & DOKU hanya token di URL), order bisa lunas dengan nominal lain; saldo penjual bertambah | Cek ulang nominal & mata uang ke payload **dan** ke API gateway (`getStatus`) sebelum settle; tolak bila beda |
| LB-02 | Pembayaran | **TINGGI** | `DokuWebhookController.php:31-35` | Tanda tangan HMAC DOKU tidak diverifikasi; hanya token statis di query | Siapa pun yang tahu URL notifikasi bisa menandai order lunas | Verifikasi header `Signature` DOKU (HMAC-SHA256 dengan secret key) |
| LB-03 | Pembayaran | **TINGGI** | `IpaymuWebhookController.php:78-110` | Notifikasi iPaymu non-sukses (termasuk **pending**) langsung `markFailed` | Order VA/e-wallet yang masih menunggu tampil "gagal" ke pembeli (lalu berubah lunas saat notifikasi sukses) | Bedakan pending vs gagal; hanya `failed/expired/cancelled` yang mengubah status |
| LB-04 | Pembayaran | **TINGGI** | `landing_page_orders` (migration `2026_06_25_000002`), tidak ada scheduler | Order `pending` **tidak pernah kedaluwarsa**; tidak ada **job rekonsiliasi** order pending ke gateway | Webhook yang hilang = pembeli sudah bayar tapi produk tak terkirim & saldo tak masuk, tanpa alarm | Scheduler `expire` (24 jam) + rekonsiliasi `getStatus` untuk pending > 10 menit |
| LB-05 | Saldo | **TINGGI** | `ReleasePendingWalletSettlementsCommand.php:32-41` | Query mengambil 300 transaksi `payment_credit_pending` **terlama** tanpa menyaring yang sudah dirilis | Setelah ±300 penjualan platform-wide, penjualan baru **tidak pernah cair** ke saldo tersedia | Saring yang belum dirilis (kolom status/`settled_at`), atau pindah ke ledger baru (Fase 2) |
| LB-07 | Saldo | **TINGGI** | `LandingSaleService.php:136-176`, `WalletController.php:212-275` | Saldo disimpan sebagai kolom yang di-update langsung; `balance_after` transaksi penjualan diisi saldo **tersedia** padahal kredit ke **pending**; tidak ada rekonsiliasi | Tidak bisa membuktikan saldo = riwayat; selisih tak terdeteksi | Ledger append-only + `balance:reconcile` (Fase 2) |
| LB-08 | Saldo | **TINGGI** | `WalletController.php` (top-up), `BillingMockController.php:363` | Satu dompet dipakai untuk **hasil penjualan, top-up, dan bayar langganan**; top-up bisa ditarik; di dev ada 20 top-up **mock** di saldo tersedia | Top-up kartu/e-wallet lalu tarik ke bank (arbitrase biaya/pencucian); saldo mock bisa ikut ditarik bila KYC disetujui | Pisahkan saldo penjualan (bisa ditarik) dari saldo top-up (tidak bisa ditarik); audit saldo produksi sebelum membuka penarikan |
| LB-09 | Penarikan | SEDANG | `WalletController.php:579-666` | `approveWithdrawal` tanpa `lockForUpdate`; dua klik bersamaan bisa memanggil payout dua kali (tertahan idempotency key Xendit, tapi status bisa balapan) | Risiko payout ganda pada mode manual | Kunci baris penarikan dalam transaksi |
| LB-10 | Penarikan | SEDANG | `config/payments.php:24` | Minimal penarikan **Rp100.000** (brief: Rp50.000), biaya flat Rp5.000 dari config (bukan pengaturan admin); tidak ada SLA/peringatan 1×24 jam, tidak ada email status ke penjual | Tidak sesuai kebijakan | Fase 2 |
| LB-11 | Biaya | SEDANG | `LandingSaleService.php:72` | Hanya komisi platform %; **biaya gateway tidak dicatat** (ditanggung Hellom diam-diam); rincian biaya tidak tampil ke penjual | Margin platform tidak terukur | Biaya gateway per metode + siapa menanggung (Fase 2) |
| LB-12 | Data | SEDANG | migration `2026_06_25_000002:12` | `landing_page_orders.organization_id` **cascadeOnDelete** | Menghapus organisasi menghapus riwayat transaksi keuangan | Ganti ke `restrictOnDelete`/soft delete (migration baru) |
| LB-13 | Webhook | SEDANG | `IpaymuWebhookController.php:61`, `XenditWebhookController.php:58-78` | Event id sama → payload lama ditimpa; tidak ada tabel log mentah terpisah | Jejak audit webhook hilang | `payment_webhook_logs` append-only (Fase 2) |
| LB-14 | Email | SEDANG | `PlatformMailService.php:118-122`, dipanggil dari webhook | Email pembeli & penjual dikirim **sinkron** di request webhook | Webhook lambat/timeout → gateway mengirim ulang; tidak ada "kirim ulang email" | Queue + tombol kirim ulang (Fase 3) |
| LB-15 | Checkout | SEDANG | `LandingCheckoutController.php:30-36` | Checkout selalu memakai halaman **terbit terbaru** organisasi | Produk di halaman terbit lain tidak bisa dibeli (404) | Checkout per halaman/produk (Fase 3) |
| LB-16 | Checkout | RENDAH | `LandingCheckoutController.php:83, 109, 225` | `returnUrl` dari header `Origin` klien; pesan exception gateway dikirim mentah ke pembeli | Info teknis bocor; redirect ke origin lain | Pakai `FrontendUrl`; pesan ramah + log |
| LB-17 | Harga | RENDAH | `LandingSaleService.php:28-36` | Harga disimpan sebagai **teks** di blok dan di-parse (`"Rp 49.000,50"` → 4.900.050) | Salah harga bila penjual menulis desimal | Harga BIGINT di tabel produk (Fase 3) |

### Keamanan

| ID | Kategori | Severity | File:baris | Masalah | Dampak | Rekomendasi |
|---|---|---|---|---|---|---|
| LB-18 | Produk digital | **KRITIS** | `LandingBuilderController.php:1317` (`publicPayload`) | API publik mengembalikan `content` blok **apa adanya**, termasuk `fileUrl` blok PDF **berbayar**. Terbukti di dev: blok #138 (`accessType: paid`) → `fileUrl` terlihat di `GET /public/landing/{org}/{slug}` | **Produk berbayar bisa diambil gratis** oleh siapa pun (buka DevTools) | Saring field rahasia di server (whitelist field publik); link file hanya lewat halaman akses setelah paid |
| LB-19 | XSS | **KRITIS** | `frontend/src/pages/public/PublicPage.tsx:548`, `landing-builder/components/BlockRenderer.tsx:429`; backend tanpa sanitasi (`storeBlock`/`updateBlock`) | Blok HTML kustom dirender dengan `dangerouslySetInnerHTML` **tanpa sanitasi** di halaman publik, yang berjalan di domain yang sama dengan dashboard (`hellomspace.com/{slug}`); token login disimpan di `localStorage` (`hellom_token`) | Penjual jahat menyisipkan skrip → **mencuri token login** siapa pun yang membuka halamannya (termasuk super admin) → ambil alih akun, setujui penarikan | Sanitasi di server (allowlist tag), render publik di server tanpa skrip penjual; jangka panjang: halaman publik di origin terpisah + token di cookie HttpOnly |
| LB-20 | Upload | **TINGGI** | `FileAssetController.php:46` | Upload menerima **SVG** (bisa berisi `<script>`) ke disk publik, disajikan dari domain utama (`/media`, `/storage`) | XSS tersimpan lewat file (buka link SVG → skrip jalan di origin Hellom) | Tolak SVG atau sanitasi + sajikan dengan `Content-Disposition: attachment` / origin lain |
| LB-21 | Produk digital | **TINGGI** | `FileAssetController.php:80-91` | File PDF berbayar diunggah ke disk **publik** (`is_public=true`) | Siapa pun yang tahu/menemukan URL bisa mengunduh tanpa bayar | Disk privat + URL bertanda tangan setelah paid (Fase 3) |
| LB-22 | Produk digital | **TINGGI** | `LandingSaleService.php:227-230`, `LandingSaleController.php:73` | Email & endpoint download mengirim **link file mentah** (mis. Google Drive); link disalin saat order dibuat (`file_url`) | Link asli tersebar; penjual ganti link → pembeli lama dapat link basi | Halaman akses bertoken yang me-resolve link terbaru (Fase 3) |
| LB-23 | Konten | SEDANG | `LandingBuilderController.php:406-470` | `content` blok = array bebas tanpa validasi skema; URL (`href`, `productUrl`, sosial) tanpa cek skema | Data sampah/berbahaya tersimpan (React 19 memblokir `javascript:` di `href`, tapi render lain/email tidak) | Skema per tipe blok + validasi URL http/https |
| LB-24 | Otorisasi | SEDANG | `routes/api/landing-builder.php:13` | Semua anggota organisasi (termasuk role `cashier`/`member`) boleh mengedit, publish, hapus halaman | Kasir POS bisa mengubah toko online | Batasi ke owner/admin (atau izin khusus) |
| LB-25 | Rate limit | SEDANG | `routes/api/public.php:37` | Checkout hanya `hellom-public-write` (60/menit per IP); tiap panggilan membuat order + sesi gateway | Spam membuat ribuan sesi gateway/order pending | Limiter per IP+email+produk, captcha ringan bila berulang |
| LB-26 | URL | SEDANG | `App.tsx:139`, pembuatan slug organisasi | Tidak ada daftar slug terlarang; route statis (`/login`, `/produk`, `/tentang`, `/aplikasi`, …) menutupi slug organisasi yang sama | Toko dengan slug itu tidak bisa diakses; peniruan (`/admin`, `/hellom`) | Daftar reserved + validasi saat daftar/ganti username |
| LB-27 | Header | SEDANG | `deploy/nginx/*.example` | Tidak ada CSP/HSTS/`X-Content-Type-Options` | Memperbesar dampak XSS | CSP khusus halaman publik (Fase 5) |
| LB-28 | IDOR | ✅ baik | `LandingBuilderController::findPageForCurrentOrg`, blok, domain, customers, `WalletController` | Semua query editor/dompet difilter `organization_id` organisasi aktif; aksi admin dicek `super_admin` di controller | — | Tambahkan tes isolasi (Fase 2/5) |

### Editor, data & fungsi

| ID | Kategori | Severity | File:baris | Masalah | Dampak | Rekomendasi |
|---|---|---|---|---|---|---|
| LB-06 | Publish | **TINGGI** | `LandingBuilderController.php:1300-1318`, `Editor.tsx:510-532` | Halaman publik membaca **blok live** (`landing_blocks`); "Simpan draft" langsung mengubah halaman publik; versi hanya menyimpan `content` halaman, **bukan blok**, jadi "kembalikan versi" tidak mengembalikan isi | Tidak ada draft sebenarnya; salah edit langsung tampil; riwayat versi tidak berguna | Draft & versi publik terpisah (snapshot blok saat publish) — Fase 4 |
| LB-29 | Simpan | **TINGGI** | `Editor.tsx:510-532` | Simpan = **hapus semua blok lalu buat ulang satu per satu** (N+1 request, tidak atomik); ID blok berganti setiap simpan | Satu request gagal → halaman live kehilangan blok; checkout yang sedang berjalan (pakai `block_id` lama) gagal | Endpoint simpan massal atomik (transaksi) dengan ID stabil |
| LB-30 | Simpan | **TINGGI** | `Editor.tsx:224, 302-348` | Tanpa status loading: editor menampilkan blok **default** ("Headline yang Menarik Perhatian") selama memuat (±5–9 dtk di dev), tombol Simpan/Terbitkan sudah aktif | Penjual yang menekan simpan terlalu cepat **menimpa halaman live dengan blok default** | Skeleton + kunci tombol sampai data termuat |
| LB-31 | Editor | SEDANG | `Editor.tsx:306-307, 496-507` | Editor hanya membuka halaman **pertama**; judul dipaksa `'Landing Page'` setiap simpan | Multi-halaman di backend tidak bisa dipakai | Pilih halaman / satu toko per penjual (Q3) |
| LB-32 | Dashboard | SEDANG | `Overview.tsx:123, 136` | Badge tren **"+12%"** dan **"+5.4%"** tertulis mati di kode (terlihat saat angka 0) | Data palsu di dashboard penjual | Hitung dari data atau sembunyikan |
| LB-33 | Statistik | SEDANG | `LandingBuilderController.php:1622-1650` | View dihitung per request API (termasuk bot/refresh), increment baca-ubah-tulis tanpa lock; `Cache-Control: public, max-age=60` membuat hitungan meleset | Angka kunjungan tidak akurat | Event ringan terpisah (beacon), dedupe per sesi (Fase 4) |
| LB-34 | Template | SEDANG | `templates` (2 item) | Hanya template restoran | Tidak cocok untuk kreator/e-book/kelas/jasa | Template baru (Fase 4) |
| LB-35 | Fitur | SEDANG | — | Tidak ada daftar order untuk penjual, refund, kupon, halaman akses/cek pesanan pembeli, tipe produk link/fisik/jasa, batas unduh | Belum setara lynk.id/Scalev | Fase 3 |

### D. UI mobile (diukur di Chrome, 360 & 414 px)

Tidak ada scroll horizontal di halaman publik, checkout, overview, dan editor ✅. Editor sudah punya mode HP
(`MobileEditor`, tombol naik/turun & drag) ✅.

| ID | Halaman | Severity | Temuan (360 px) |
|---|---|---|---|
| UI-01 | Checkout (modal di `PublicPage.tsx`) | SEDANG | Input nama/email/HP **font 14px** → iOS auto-zoom saat diketik; tombol tutup **20×20 px**; tidak ada pilihan metode bayar di halaman (dipilih di halaman gateway), tidak ada ringkasan biaya; tombol bayar tidak menempel di bawah |
| UI-02 | Halaman terima kasih | **TINGGI** | Untuk pembayaran redirect (VA/e-wallet) pembeli dikembalikan ke `/{org-slug}` **tanpa status/akses produk**; polling hanya untuk QRIS |
| UI-03 | Editor | SEDANG | 13 target sentuh < 44 px: ikon header 32×32 (Pratinjau, Pengaturan, Terbitkan), "Simpan" 30×26, "AI" 52×28, pegangan geser 24×24, "Edit Block" 32×32; ikon tanpa label teks (Simpan/Terbitkan sulit dikenali) |
| UI-04 | Overview landing | RENDAH | 10 target < 44 px (tab 34 px, tombol "Buka/Edit Halaman" 42 px, logout 24×24); badge tren palsu (LB-32) |
| UI-05 | Dompet penjual (`/dashboard/payments`) | SEDANG | Tabel riwayat **melebar** di 360 px (`TABLE.min-w-full` di dalam container scroll); tab 40 px; status "Dalam Konfigurasi · Hubungi dukungan" membingungkan; halaman ini dompet top-up, bukan "Saldo Penjualan" |
| UI-06 | Semua | SEDANG | Tidak ada skeleton/empty state di editor & publik (spinner saja); pesan error teknis dari gateway tampil apa adanya (LB-16) |

Screenshot tersimpan lokal di `backend/storage/app/audit_lb_shots/` (tidak di-commit).

### E. Performa & iklan

| ID | Severity | Temuan | Rekomendasi |
|---|---|---|---|
| PF-01 | **TINGGI** | Halaman publik = **SPA React**: HTML kosong → JS utama ±65 KB gzip + vendor + CSS 24 KB gzip + font Google → baru fetch API → render. LCP tertunda 2 round-trip; tanpa JS tidak tampil apa pun | Render di server (Blade/HTML statis saat publish) — Fase 4 |
| PF-02 | **TINGGI** | Tidak ada meta per halaman di HTML (`index.html` berjudul "Hellom POS", tanpa `og:*`/`twitter:*`) | Share ke WhatsApp/FB tampil judul generik tanpa gambar; berdampak langsung ke iklan & penjualan |
| PF-03 | SEDANG | Tidak ada dukungan Meta Pixel/CAPI, GA4, Google Ads, TikTok; UTM/fbclid/gclid tidak disimpan di order | Fase 4 |
| PF-04 | RENDAH | Gambar dari upload tidak dikompres/diubah ke WebP; tanpa `srcset` | Fase 3/4 |

---

## F. Ringkasan & urutan prioritas

**Kondisi**: fondasi jualan sudah ada (checkout gateway tanpa login, dompet, KYC, penarikan dengan lock,
webhook Xendit bertoken, isolasi penjual di API editor baik). Tetapi ada **3 celah KRITIS** yang bisa
dieksploitasi hari ini dan beberapa masalah TINGGI yang membuat uang/produk tidak aman atau halaman rusak.

Urutan yang saya sarankan (menyesuaikan fase di brief):

1. **Segera (hotfix kecil, sebelum Fase 2)** — bila Anda setuju:
   LB-18 (sembunyikan `fileUrl` & field rahasia dari API publik), LB-19 (sanitasi blok HTML di server + frontend),
   LB-20 (tolak SVG), LB-30 (kunci tombol simpan sampai editor termuat).
2. **Fase 2 (uang)**: LB-01, LB-02, LB-03, LB-04, LB-05, LB-07, LB-08, LB-09, LB-10, LB-11, LB-12, LB-13 + tes wajib.
3. **Fase 3 (produk & pengiriman)**: LB-14, LB-15, LB-17, LB-21, LB-22, LB-35, UI-01, UI-02.
4. **Fase 4 (builder & publik)**: LB-06, LB-29, LB-31, LB-33, LB-34, PF-01…PF-04, LB-23, LB-26.
5. **Fase 5 (poles)**: UI-03…UI-06, LB-24, LB-25, LB-27, tes E2E.

Jumlah: KRITIS 3 · TINGGI 15 · SEDANG 22 · RENDAH 4. (Di dev sudah ada 1 aset SVG terunggah — relevan untuk LB-20.)

## Pertanyaan untuk pemilik (keputusan bisnis)

- **Q1 — Identitas penjual**: landing builder & dompet memakai `organization_id` di semua tabel (POS memakai `pos_tenant_slug`).
  Saya usul **tetap `organization_id`** untuk modul ini (konsisten dengan dompet & KYC yang sudah ada); aturan
  `pos_tenant_slug` di brief berlaku untuk POS. Setuju?
- **Q2 — Dompet**: apakah saldo top-up (untuk bayar langganan) boleh ditarik? Saran: **tidak** — pisahkan
  "Saldo Penjualan" (bisa ditarik) dari "Saldo Top-up".
- **Q3 — Username & halaman**: satu toko (satu username) per organisasi, atau per user? Dan apakah satu toko boleh
  punya banyak halaman (`/{username}/{halaman}`)? Saat ini backend mendukung banyak halaman, editor hanya satu.
- **Q4 — Saldo mock**: di produksi, apakah pernah ada `wallet_topup_mock`? Perlu dicek sebelum penarikan dibuka
  (`SELECT COUNT(*), SUM(amount) FROM organization_wallet_transactions WHERE type='wallet_topup_mock';`).
- **Q5 — Gateway**: gateway mana yang aktif di produksi saat ini, dan apakah akun Xendit sudah punya fitur
  **Disbursement/Payout** (untuk mode penarikan otomatis) dan **validasi nama rekening**?
- **Q6 — Hotfix**: boleh saya kerjakan 4 hotfix KRITIS/TINGGI di atas lebih dulu (kecil, tanpa migration),
  sebelum Fase 2?
- **Q7 — Komisi**: komisi platform sekarang 5% (dapat diatur admin). Biaya gateway ditanggung siapa — penjual,
  pembeli (ditambahkan ke total), atau Hellom?

## Verifikasi temuan kunci (baca-saja)

PowerShell (ganti `{org}`/`{slug}` dengan halaman terbit yang punya blok PDF berbayar):

```powershell
$r = Invoke-RestMethod "http://127.0.0.1:8000/api/v1/hellom/public/landing/{org}/{slug}"
$r.data.blocks | Where-Object { $_.block_type -eq 'pdf' } | ForEach-Object { $_.content.accessType, [bool]$_.content.fileUrl }
# LB-18: accessType 'paid' + True = link produk berbayar terbuka untuk publik
```

SQL (HeidiSQL):

```sql
-- LB-18/19: blok berbayar yang menyimpan link file, dan blok HTML kustom
SELECT id, landing_page_id, block_type, JSON_EXTRACT(content, '$.accessType') AS access
FROM landing_blocks WHERE block_type IN ('pdf','product','html');

-- LB-04: order pending lama yang tidak pernah kedaluwarsa
SELECT status, COUNT(*), MIN(created_at) FROM landing_page_orders GROUP BY status;

-- LB-05: jumlah penjualan pending yang sudah jatuh tempo tapi belum dirilis
SELECT COUNT(*) FROM organization_wallet_transactions t
WHERE t.type = 'payment_credit_pending'
  AND NOT EXISTS (SELECT 1 FROM organization_wallet_transactions r
                  WHERE r.type = 'payment_settle_release' AND r.reference_type = 'organization_wallet_transactions'
                    AND r.reference_id = CAST(t.id AS CHAR));

-- LB-08 / Q4: saldo dari top-up mock
SELECT COUNT(*), SUM(amount) FROM organization_wallet_transactions WHERE type = 'wallet_topup_mock';

-- LB-20: aset SVG yang sudah terunggah
SELECT COUNT(*) FROM file_assets WHERE mime_type LIKE 'image/svg%';
```
