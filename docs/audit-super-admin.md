# Audit Dashboard Super Admin — Fase 1

> Tanggal: 2026-10-02 · Branch: `fix/super-admin-overhaul` (dari `refactor/cleanup` @ `3e3068b`) · Mode: **read-only, belum ada kode yang diubah**
> Tidak ada `.env` yang dibaca. Verifikasi runtime dijalankan di DB tes `hellom_pos_test` dalam `DB::beginTransaction()/rollBack()` (skrip `backend/storage/app/audit_sa_*.php`, git-ignored).

## Koreksi terhadap brief
| Brief | Kenyataan di repo | Sikap |
|---|---|---|
| Frontend di `plans/UI/`, React 18 | `frontend/` (React 19 + Vite 6 + TS strict); `plans/UI` kosong | Audit `frontend/` |
| Laravel 11 | Laravel 12 | — |
| Gateway = DOKU | Gateway aktif = **iPaymu** (keputusan pemilik Q6). Kode DOKU & Xendit masih ada dan bisa dipilih dari Admin › Settings | DOKU tetap diaudit |
| Fase 3: `php artisan migrate:fresh --seed` | **Dilarang** di CLAUDE.md (aturan kerja #2) | Diganti `migrate` + `phpunit -c phpunit.pos.xml` di `hellom_pos_test` |
| `npx eslint` | ESLint **tidak terpasang** (`npm run lint` = `tsc --noEmit`) | Lihat P3-10 (perlu keputusan) |

## Peta Super Admin
- **Backend**:
  - Semua route `/api/v1/hellom/admin/*` (± 110 route di `routes/api/admin.php`) berada di belakang `AuthenticateApiToken` + `superAdmin` (`EnsureSuperAdmin`: `users.role === 'super_admin'`).
  - Ada **9 route admin di luar grup itu** di `routes/api/wallet.php`. Dua tipe:
    - pakai middleware: `wallet/admin/payout-queue`, `wallet/withdrawals/{id}/approve|reject|mark-paid|mark-failed`;
    - cek role di dalam controller: `admin/payout-profiles*`, `platform/*`.
  - Semuanya sudah aman, tetapi gayanya tidak seragam.
- **Frontend**:
  - `/admin/*` di `src/App.tsx:194-212` → `layouts/AdminLayout.tsx`. Ada 19 halaman, semuanya `lazy()`.
  - Klien API: `services/api/admin.ts` (46 fungsi, semuanya dipakai), `content.ts`, `store.ts`, `sellerFinance.ts`, `landingStore.ts`, `billing.ts`.
- **Kecocokan frontend ↔ backend**:
  - Skrip `audit_api_match.mjs` mencocokkan 264 panggilan API frontend dengan `route:list`. Hasilnya **0 endpoint salah alamat**; 3 "selisih" hanya artefak query string.
  - Ada **endpoint admin tanpa UI** (lihat P2-9).
- **Status pemeriksaan**:
  - `npx tsc --noEmit` = **0 error**.
  - `route:list` jalan (429 route API).
  - Bundle: semua halaman admin sudah lazy. Chunk terbesar: `AdminSettings` 39 KB, `UserManagement` 33 KB, `AppManagement` 33 KB. `vendor-charts` 372 KB hanya dimuat oleh Overview.

## Ringkasan
| Prioritas | Jumlah | Inti |
|---|---|---|
| **P0** kritis/keamanan | 3 | Pemilik toko biasa bisa masuk ke organisasi lain; hapus paket/user menghapus riwayat pembayaran & ledger uang; webhook iPaymu (gateway aktif) mempercayai isi notifikasi untuk langganan & top-up |
| **P1** bug fungsional | 10 | Approve manual ganda tanpa lock, webhook DOKU bisa menurunkan status yang sudah lunas, KTP tidak bisa dilihat, Organisasi tanpa menu, System Health berisi angka palsu, XSS artikel, refund produk tanpa cek status … |
| **P2** performa/UX | 12 | `per_page` tanpa batas, audit log salah/tidak ada, error API tidak seragam, broadcast email sinkron, endpoint tanpa UI … |
| **P3** cleanup | 10 | Teks campur Inggris, kode mati kecil, filter yang tidak berfungsi, ESLint belum ada … |

## Temuan

### P0 — kritis / keamanan
| ID | Area | File:baris | Masalah | Dampak | Prioritas | Rencana perbaikan |
|---|---|---|---|---|---|---|
| P0-1 | Auth / isolasi tenant | `AuthController.php:206`; `OrganizationController.php:25,144`; `OrganizationTeamController.php:785,841`; `WalletController.php:1246`; `PayoutProfileController.php:215` | **Setiap pemilik yang daftar sendiri mendapat `users.role = 'admin'`**. Kode memperlakukan `admin` sebagai admin platform: bisa melihat **semua** organisasi, `switch` ke organisasi mana pun, lalu dianggap **owner** di Tim, Dompet, Profil Payout (KTP/rekening) dan Pengaturan organisasi itu. **Terverifikasi**: akun owner baru melihat 48 organisasi, pindah ke organisasi korban, dan mendapat 200 untuk `team`, `wallet/overview`, `payout-profile`, `settings`. Di DB lokal 12 dari 13 akun `admin` adalah owner toko biasa. POS aman karena butuh pivot. | Kebocoran data lintas tenant (anggota tim, saldo, **KTP & rekening**). Lewat Tim, penyerang bisa mengundang dirinya ke toko korban. Keputusan lama di `docs/AUDIT.md` §S-7 ("role admin sengaja bisa pindah ke semua organisasi") dibuat dengan anggapan `admin` = staf platform. **Ternyata tidak demikian.** | P0 | (a) Register memberi `member` (role platform netral). (b) Hapus cabang `role === 'admin'` di 6 tempat di atas; akses hanya lewat pivot, kecuali `super_admin`. (c) Migration data: `users.role admin → member` untuk akun yang punya pivot owner (dengan `down()`). **Perlu keputusan: apakah masih ada staf Hellom yang memang butuh role `admin` lintas organisasi?** |
| P0-2 | Super admin › User & Paket | `SuperAdminController.php:400` (`deleteUser`), `:626` (`deletePlan`); FK `cascadeOnDelete` di `subscriptions.plan_id`, `checkout_intents.plan_id/user_id`, `user_wallet_ledgers.user_id`, `product_purchases.user_id` | **Hapus permanen tanpa pengaman.** Terverifikasi:<br>• **Hapus paket** yang hanya punya langganan non-aktif ikut menghapus langganan + checkout intent **lunas**.<br>• **Hapus user** ikut menghapus **ledger dompet**, pembelian produk dan riwayat checkout, lalu meninggalkan organisasi **tanpa owner**.<br>• Super admin bisa **menghapus dirinya sendiri**. | Riwayat pembayaran & ledger uang hilang permanen (tidak bisa direkonsiliasi/diaudit); toko yatim. | P0 | Paket: tolak hapus bila ada langganan/intent apa pun, dan sediakan **arsip** (`is_active=false`, `is_visible=false`). User: tolak hapus diri sendiri, super admin terakhir, owner tunggal, dan user dengan riwayat keuangan; tawarkan **suspend**. Tes untuk semua kasus. |
| P0-3 | Billing / webhook iPaymu (gateway aktif) | `IpaymuWebhookController.php:103` (`isSuccessPayload`), `:185` (langganan), `:218` (`creditWalletTopup`, nominal dari `payload['amount']`), `:330` (produk digital) | Selain penjualan Hellom Page, notifikasi iPaymu **tidak diverifikasi ke API iPaymu**. Status sukses dan **nominal top-up** diambil dari isi request, dan organisasi/intent dari query string. Satu-satunya pengaman adalah `callback_token` statis. Notifikasi iPaymu tidak bertanda tangan, dan CLAUDE.md mewajibkan "selalu cek status + nominal ke API gateway". | Siapa pun yang tahu/bocor token bisa mengaktifkan langganan, menandai produk lunas, atau **menambah saldo top-up sembarang nominal** ke organisasi mana pun. | P0 | Terapkan pola `LandingPaymentService` untuk ketiga jalur: cek transaksi ke API iPaymu (status, nominal = `intent.amount`/top-up yang tercatat, referensi), lalu proses dalam `DB::transaction` + `lockForUpdate`. Tes dengan `Http::fake`. |

### P1 — bug fungsional
| ID | Area | File:baris | Masalah | Dampak | Prioritas | Rencana perbaikan |
|---|---|---|---|---|---|---|
| P1-1 | Billing › Approve manual | `ManualCheckoutReviewController.php:75-150` | Approve menyalin logika `SubscriptionCheckoutActivationService::approveManualCheckout`, tetapi **tanpa `lockForUpdate`** (cek status di luar transaksi). Ada dua jalur approve yang berbeda: halaman Billing dan tombol di Notifikasi. | Klik ganda / dua admin → hak akses diberikan dobel, **pendapatan dobel di `platform_finance_ledger`, invoice dobel**. | P1 | Controller memanggil service (satu jalur, sudah ber-lock); tes konkuren. |
| P1-2 | Billing › Tolak manual | `ManualCheckoutReviewController.php:156` | Reject mengubah `subscription.status = cancelled`. Bila intent itu perpanjangan langganan yang **sedang aktif**, langganan aktifnya ikut dibatalkan. | Pelanggan aktif kehilangan status langganan karena bukti transfer ditolak. | P1 | Hanya batalkan langganan yang berstatus `pending_payment`/`draft`; tes. |
| P1-3 | Webhook DOKU | `DokuWebhookController.php:173,221,299` | (a) Langganan & produk digital hanya dijaga `callback_token`: tanpa HMAC dan tanpa cek ke API DOKU (yang ada baru jalur `lps_`). (b) Notifikasi **FAILED/EXPIRED/PENDING yang datang belakangan menurunkan** intent/langganan/pembelian yang sudah `confirmed/paid`. (c) Cek idempotensi di luar lock. | Bila DOKU diaktifkan: akses pelanggan dicabut oleh notifikasi tak berurutan, aktivasi dobel. | P1 | `verifyWebhook` + cek status ke API untuk semua jalur; transisi status satu arah (lunas tidak bisa turun); lock; tes dengan mock. |
| P1-4 | DOKU `merchant_inactive` | `Services/Hellom/DokuService.php:109` | Error DOKU diteruskan mentah sebagai `"DOKU API error: …"`. Tidak ada pemetaan `401 merchant_inactive`. | Admin & pembeli melihat pesan teknis; tidak jelas bahwa akun DOKU masih diverifikasi. | P1 | Petakan `merchant_inactive`/401 → "Akun DOKU production masih dalam verifikasi. Pakai mode sandbox atau gateway lain."; tampilkan di Admin › Settings. |
| P1-5 | KYC payout | `frontend/src/pages/admin/PayoutKycReview.tsx:92` | "Lihat foto KTP" berupa `<a href>` biasa ke endpoint API. API hanya menerima **Bearer token**, jadi selalu **401**. | Super admin tidak bisa memeriksa KTP sebelum menyetujui KYC. | P1 | Pakai `fetchAuthorizedBlobUrl` (pola yang sudah ada di `products/[id]/edit.tsx:250`). |
| P1-6 | Navigasi | `layouts/AdminLayout.tsx:132-149` | Halaman **Organisasi** (`/admin/organizations`: detail, override kuota outlet, buka akses) **tidak ada di sidebar**. Suspend/aktifkan organisasi punya API tetapi **tidak punya tombol** di UI. | Fitur hanya bisa dicapai dengan mengetik URL; suspend toko tidak bisa dilakukan dari UI. | P1 | Tambah menu "Organisasi"; tombol suspend/aktifkan + konfirmasi (fitur backend sudah ada). **Konfirmasi: boleh tambah tombol?** |
| P1-7 | System Health | `pages/admin/SystemHealth.tsx:45-52` | Uptime/latency (`99.90%`, `55ms` …) **ditulis tetap di kode**. Statusnya ditebak dari antrean payout lama. | Info palsu bagi super admin; masalah sungguhan (scheduler, queue, DB) tidak terlihat. | P1 | Pakai `GET /api/health` (DB, cache, heartbeat scheduler, backlog queue) + `failed_jobs`; hapus angka statis. |
| P1-8 | Artikel (Landing Content) | `pages/site/WawasanDetailPage.tsx:161`; `LandingContentController.php:281` | Isi artikel (termasuk keluaran AI Gemini) dirender `dangerouslySetInnerHTML` **tanpa `safeHtml`** di domain utama, tempat token login tersimpan di `localStorage`. | XSS tersimpan di hellomspace.com bila akun SA bocor atau AI menghasilkan HTML berbahaya → pencurian token pengunjung yang login. | P1 | Bungkus dengan `safeHtml()` (sudah ada di `lib/safeHtml.ts`) + sanitasi di server saat simpan. |
| P1-9 | Pembelian produk digital | `Admin/ProductPurchaseController.php:69,106` | `refund` tidak memeriksa status: pembelian `pending/failed/refunded` bisa "di-refund" dan pembeli tetap menerima email "refund diproses". `approve` tanpa lock (`total_purchases` dobel). Keduanya tanpa audit log. | Email menyesatkan; statistik salah; tidak ada jejak siapa yang menyetujui. | P1 | Validasi transisi (`paid → refunded` saja), `lockForUpdate`, `AuditLog`. |
| P1-10 | Override akses | `SuperAdminController.php:417,681` | Menulis `entitlements` langsung (bukan lewat `EntitlementService`). Override `active` membuat `ends_at = null`, sehingga **akses seumur hidup tanpa disadari**. Status `trialing` dicek tetapi tidak lolos validasi. | Melanggar aturan "akses berbayar hanya lewat EntitlementService"; akses gratis tanpa batas. | P1 | Pakai `EntitlementService::grant()` dengan `ends_at` eksplisit (input wajib, atau dari paket); hapus cabang mati. |

### P2 — performa / UX
| ID | Area | File:baris | Masalah | Dampak | Prioritas | Rencana perbaikan |
|---|---|---|---|---|---|---|
| P2-1 | Pagination | `ProductPurchaseController.php:48`, `OwnerNotificationController.php:32`, `DigitalProductController.php:35` | `per_page` dari query **tanpa batas atas**. | `?per_page=100000` memuat semua baris + relasi. | P2 | `min(max(1,…),100)`. |
| P2-2 | List tanpa pagination | `InvoiceController.php` (`adminIndex`, limit 200), `PromoCampaignController::index` (limit 30), `ManualCheckoutReviewController::adminPendingCheckouts` (limit 50), `ShowcaseController::index*`, `listPlans` | Data di atas batas **diam-diam terpotong**. | Promo/invoice lama tidak terlihat. | P2 | Paginasi server + format `{items, pagination}` yang sama. |
| P2-3 | Audit log | `SuperAdminController.php:770`; `PromoCampaignController.php:184-191` | `organization_id` diisi org **milik super admin**, bukan org target. Promo menulis `target_type/target_id` yang **bukan kolom** (dibuang diam-diam). | Filter audit per organisasi salah; log promo tanpa target. | P2 | Isi org/entitas target; pakai `AuditLog::record`. |
| P2-4 | Audit log tidak ada | Gateway config, manual payment config (**nomor rekening transfer**), brand, mail, banner, showcase, artikel, produk digital, notifikasi execute | Perubahan sensitif tidak tercatat. | Bila akun SA disalahgunakan (mis. ganti rekening transfer manual), tidak ada jejak. | P2 | `AuditLog::record` di setiap aksi tulis (tanpa nilai secret). |
| P2-5 | Format error API | `bootstrap/app.php:31` (`withExceptions` kosong) | `findOrFail`, 404/405/500 dan throttle mengembalikan format bawaan Laravel `{message}`, bukan amplop `{success,message,data,error}`. | Frontend harus menebak dua format; pesan "Server Error" mentah. | P2 | Renderer JSON untuk `api/*` (tanpa stack trace) + satu penanganan 401/403/422/500 di `services/api/client.ts`. |
| P2-6 | Broadcast email | `AdminMailController.php:79` (`sendPromo`) | Mengirim ke **semua user** (termasuk suspended/kasir/member) secara **sinkron** dalam satu request, tanpa throttle/idempotensi/opt-out. | Timeout di Nginx/PHP, kiriman separuh, double-send; risiko spam/reputasi domain. Saat ini belum ada UI-nya. | P2 | Antrikan (job per batch), filter penerima, opt-out; atau nonaktifkan sampai dibutuhkan. **Perlu keputusan.** |
| P2-7 | Brand | `BrandSettingController.php:29-30,89` | Aturan `image` di Laravel 12 **menolak SVG** (dan `.ico`) walau ada di `mimes` → unggah logo SVG/favicon ICO gagal 422. Respons brand publik menyertakan `logo_base64` (sampai ~2,7 MB) di setiap muat halaman. Warna tidak divalidasi hex. | Gagal unggah; halaman lambat. | P2 | `image:allow_svg` atau `mimes` saja (SVG disandbox di `/media`); hapus `logo_base64` dari respons publik (email sudah pakai embed CID); regex hex. |
| P2-8 | AI artikel | `LandingContentController.php:372` | Pesan exception Gemini diteruskan ke klien; tanpa rate limit. | Detail internal bocor; biaya API tak terbatas. | P2 | Pesan generik + `report()`; `throttle:10,1`. |
| P2-9 | Endpoint tanpa UI | `admin/audit-logs`, `admin/invoices`, `mail-settings/promo`, `mail-settings/billing-reminder/{id}`, `seller-finance/sellers/{id}/adjustment`, `plans/{id}/subscriptions`, `promos/{id}`, `product-purchases/{id}`, organisasi suspend/reactivate | Backend ada, tidak dipanggil frontend mana pun (hasil `audit_api_match.mjs`). | Fitur "ada" tetapi tidak bisa dipakai; kode tak teruji. | P2 | Per endpoint: buat UI kecil (audit log, invoice, penyesuaian saldo) **atau** pensiunkan. **Perlu keputusan** (daftar di bawah). |
| P2-10 | Konfirmasi aksi destruktif | `LandingContentManagement.tsx:151,170`; `OrganizationManagement.tsx:254` (buka akses) | Hapus layanan/artikel tanpa konfirmasi & tanpa penanganan error (promise ditolak diam-diam). | Salah klik = data hilang; gagal tanpa pesan. | P2 | Dialog konfirmasi + toast sukses/gagal. |
| P2-11 | Unggah media | `ShowcaseController.php:187` | Maks 50 MB, padahal Nginx produksi `client_max_body_size 20m`. | Unggah >20 MB gagal dengan 413 tanpa pesan jelas. | P2 | Samakan batas (20 MB) + pesan di UI. |
| P2-12 | Notifikasi › Execute | `OwnerNotificationController.php` | Approve checkout lewat notifikasi = jalur kedua (lihat P1-1), tanpa audit log. | Sulit melacak siapa menyetujui. | P2 | Satu service + audit. |

### P3 — cleanup
| ID | Area | File:baris | Masalah | Dampak | Prioritas | Rencana perbaikan |
|---|---|---|---|---|---|---|
| P3-1 | Bahasa UI | `AdminLayout.tsx:133-148` ("User Management", "Settings", "Sign Out", "Context Organization"), `OrganizationManagement`, `ShowcaseManagement`, `UserManagement`, pesan API Inggris (`'Organization not found'` …) | Campur Inggris–Indonesia. | Tidak konsisten dengan aturan brief. | P3 | Seragamkan Bahasa Indonesia santai-profesional. |
| P3-2 | Sidebar aktif | `AdminLayout.tsx:175,198` | `pathname === path` → halaman turunan (`/admin/products/12/edit`) tidak menandai menu. | UX. | P3 | `startsWith` untuk sub-rute. |
| P3-3 | Efek ganda | `AdminLayout.tsx:42-92` | Dua `useEffect` mengulang cek sesi/role. | Kode ganda. | P3 | Satukan. |
| P3-4 | Mode gelap | `AdminLayout.tsx` | Area admin belum ikut `useDashboardColorScheme`/`hl-dash`. | Tidak konsisten dengan dashboard. | P3 | Pasang hook yang sama (opsional). |
| P3-5 | Filter paket | `SuperAdminController.php:552` | `app_slug` memfilter lewat `entitlements.app`; paket baru tanpa entitlement tidak pernah muncul. | Filter menyesatkan. | P3 | Hapus filter atau beri relasi app yang benar. |
| P3-6 | Pivot `super_admin` | `OrganizationTeamController.php:75,128,511` | Owner boleh memberi peran tim `super_admin` (pivot). Perannya sama dengan admin toko, tetapi namanya menyesatkan. | Kebingungan peran. | P3 | Batasi ke `admin,member`. |
| P3-7 | Hapus banner | `DigitalProductController.php:318` | `deletePublicUrl` mencari `/storage/`, padahal URL memakai `/media/` → file banner lama tidak terhapus. | File yatim di disk. | P3 | Kenali `/media/`. |
| P3-8 | Validasi kecil | `PromoCampaignController.php` (persen >100; `ends_at` tanpa `after_or_equal` di update); `Showcase`/`LandingContent` (URL tanpa skema `http(s)`) | — | Data aneh. | P3 | Tambah aturan. |
| P3-9 | Keuangan lama | `pages/admin/FinanceManagement.tsx`, antrean `wallet/admin/payout-queue` | Penarikan saldo top-up sudah dimatikan (`WalletController` menolak), tetapi UI antrean payout lama masih tampil. | Menu membingungkan di samping "Keuangan Penjual". | P3 | Sederhanakan jadi ringkasan pendapatan platform. **Perlu keputusan.** |
| P3-10 | Lint | `frontend/package.json` | ESLint tidak terpasang. | Tidak ada cek hook deps / unused import otomatis. | P3 | Tambah ESLint (`typescript-eslint`, `react-hooks`) sebagai dev dependency. **Perlu izin (dependensi baru).** |

## Kode yang diusulkan dihapus / dipensiunkan
| Kandidat | Bukti tidak dipakai | Usul |
|---|---|---|
| `store.ts`: `getPublicProductBySlug`, `getProductCategories` | `grep -rlw` di `src/` (selain `services/api/`) = 0 | Hapus (bukan area admin, tetapi aman). |
| Cabang `role === 'admin'` (6 tempat, P0-1) | Menjadi celah, bukan fitur | Hapus bersama perbaikan P0-1. |
| Logika approve duplikat di `ManualCheckoutReviewController` | Duplikat `SubscriptionCheckoutActivationService::approveManualCheckout` | Ganti dengan pemanggilan service. |
| Angka statis `SystemHealth.tsx:45-70` | Tidak berasal dari API | Ganti dengan `/api/health`. |
| Endpoint P2-9 tanpa UI | `audit_api_match.mjs`: 0 pemanggil | **Tidak dihapus tanpa keputusan** (beberapa layak diberi UI). |

Tidak ada controller/model/job/command super admin yang benar-benar yatim. Semua route admin punya controller, dan semua 46 fungsi `admin.ts` dipakai halaman.

## Status perbaikan (Fase 2)
Keputusan pemilik (2026-10-02): "kerjakan bertahap" → semua usulan di bawah dijalankan sesuai rekomendasi.

| ID | Status | Commit | Catatan |
|---|---|---|---|
| P0-1 | ✅ fixed | `906210f` | Role `admin` tetap (tanpa migrasi role), tetapi akses selalu dari pivot. Migration `2026_10_05_000001` mengembalikan `current_organization_id` asing (tabel cadangan, `down()` memulihkan). Pengaturan organisasi butuh owner/admin. Tes `tests/Admin/OrganizationIsolationTest`. |
| P0-2 | ✅ fixed | `625b409` | Paket yang punya riwayat → diarsipkan. User: tolak hapus diri sendiri, super admin, owner tunggal, dan yang punya riwayat uang. Tes `DeleteSafetyTest`. |
| P0-3 | ✅ fixed | `d306053` | `IpaymuPaymentVerifier` (status + referensi + nominal + transaksi tidak dipakai ulang) untuk langganan, produk digital, top-up dan reconcile. Notify URL ditandatangani HMAC. Tes `tests/Landing/IpaymuBillingWebhookTest`. |
| P0-4 *(baru)* | ✅ fixed | `d306053` | Ditemukan saat perbaikan: langganan **tahunan** lewat iPaymu/Xendit ditagih **harga bulanan** (`$plan->price`), padahal akses 12 bulan. Sekarang ditagih `intent.amount`. |
| P1-1 | ✅ fixed | `215fdf3` | Satu jalur approve (service ber-lock) untuk halaman Billing & tombol Notifikasi; klik kedua → "sudah disetujui". Tes `ManualCheckoutTest`. |
| P1-2 | ✅ fixed | `215fdf3` | Reject hanya membatalkan langganan `pending_payment/draft`. (Catatan: setiap checkout membuat langganan baru, jadi risikonya lebih kecil dari dugaan awal.) |
| P1-3 | ✅ fixed | `1983e0f` | Semua notifikasi DOKU wajib HMAC; status/nominal dari API DOKU; status lunas tidak pernah turun. Tes `DokuBillingWebhookTest`. |
| P1-4 | ✅ fixed | `1983e0f` | `merchant_inactive` → pesan jelas (production vs sandbox). |
| P1-5 | ✅ fixed | `b51e3ca` | Foto KTP dimuat dengan token dan ditampilkan inline; approve minta konfirmasi. |
| P1-6 | ✅ fixed | `531b33d` | Menu Organisasi + tombol suspend/aktifkan (+ konfirmasi), paginasi, pencarian debounce, tidak crash bila paket null. |
| P1-7 | ✅ fixed | `fbccfd2` | Kesehatan Sistem membaca `/api/health` (DB, cache, scheduler, queue), refresh tiap menit. |
| P1-8 | ✅ fixed | `7ba8c36` | Artikel disanitasi di server (simpan + hasil AI) dan di browser. Bonus: buat artikel/layanan tanpa slug tidak 500 lagi; judul kembar → slug `-2`. Tes `ContentSecurityTest`. |
| P1-9 | ✅ fixed | `e89deb3` | Refund hanya untuk yang lunas; konfirmasi ber-lock; audit log. Tes `ProductPurchaseAdminTest`. |
| P1-10 | ✅ fixed | `531b33d` | Override akses lewat `EntitlementService`, wajib tanggal berakhir atau "seumur hidup" eksplisit. Tes `OrganizationAdminTest`. (Form akses di detail Pengguna sudah menampilkan tanggal berakhir secara eksplisit, jadi dibiarkan.) |
| P2-3 | ✅ fixed | `531b33d` | Audit SuperAdmin mencatat organisasi target. Log promo menyusul di P2-4. |
| P2-8 | ✅ fixed | `7ba8c36` | Pesan error AI generik + `report()`, `throttle:10,1`. |
| P2-9 (sebagian) | ✅ | `531b33d` | Halaman baru **Log Audit** dan **Invoice** (invoice kini berpaginasi + cari). |
| P2-12 | ✅ fixed | `215fdf3` | Execute notifikasi memakai service yang sama + audit. |
| P2-1 | ✅ fixed | `215fdf3`, `e89deb3`, `f4bd95d` | `per_page` maks 100 (notifikasi, pembelian, produk digital). |
| P2-2 | ✅ fixed | `531b33d`, `56755a9` | Invoice berpaginasi + cari; promo & transfer manual sampai 100 + total (UI memberi tahu bila ada yang tersembunyi). Showcase/paket/klien dibiarkan tanpa paginasi (jumlahnya kecil). |
| P2-4 | ✅ fixed | `f4bd95d` | `BaseApiController::adminAudit()`; gateway (nama field saja, tanpa nilai rahasia), transfer manual, runtime checkout, branding, email, banner, showcase, konten situs, produk digital, promo. Tes `AdminAuditTrailTest`. |
| P2-5 | ✅ fixed | `eed0737` | Renderer error `api/*` → amplop seragam (tanpa stack trace); klien frontend mengakhiri sesi untuk 401 `UNAUTHORIZED` di endpoint mana pun. Tes `ApiErrorFormatTest`. |
| P2-6 | ✅ dipensiunkan | `48ce718` | Broadcast promo & pengingat tagihan dihapus (0 pemanggil). |
| P2-7 | ✅ fixed | `8e0face` | SVG/ICO diterima, warna wajib hex, `logo_base64` publik = null. Tes `BrandSettingsTest`. |
| P2-9 | ✅ fixed | `531b33d`, `4101730` | UI Log Audit, Invoice, penyesuaian saldo penjual. `plans/{id}/subscriptions`, `promos/{id}`, `product-purchases/{id}`, `DELETE notifications/{id}` dibiarkan (endpoint baca/hapus kecil, aman). |
| P2-10 | ✅ fixed | `8e0face` | Konfirmasi hapus + pesan sukses/gagal di Konten Situs. |
| P2-11 | ✅ fixed | `8e0face` | Batas unggah 20 MB di server & klien. |
| P3-1 | ✅ fixed | `531b33d`, `b849bd6`, `56c7fa6` | Teks admin Bahasa Indonesia (istilah teknis seperti API Key/Webhook dibiarkan). |
| P3-2, P3-3 | ✅ fixed | `531b33d` | Menu aktif di sub-halaman; satu effect sesi. |
| P3-4 | ⏭️ skipped | — | Mode gelap area admin: fitur baru, tidak diminta; sidebar admin sudah gelap. |
| P3-5, P3-6 | ✅ fixed | `c2b21fc` | Filter `app_slug` paket dihapus; peran tim `super_admin` (pivot) tidak bisa diberikan lagi (0 data). |
| P3-7, P3-8 | ✅ fixed | `f4bd95d` | File banner `/media` terhapus; promo persen ≤ 100, tanggal akhir ≥ mulai. |
| P3-9 | ✅ fixed | `b849bd6` | Keuangan Platform: antrean transfer manual di atas; penarikan dompet lama dilipat (terbuka otomatis bila masih ada). |
| P3-10 | ✅ fixed | `89fab44`, `616f873` | ESLint (flat config). Area admin 0 error/0 warning (`npm run lint:admin`). |
| Ringkasan *(baru)* | ✅ fixed | `89fab44` | Kartu "Active App Cards" ternyata kartu milik super admin sendiri & tombol ⋯ tidak berfungsi → angka platform + "Perlu tindakan". |
| Log *(baru)* | ✅ fixed | `56c7fa6` | Setiap buka halaman keuangan menulis ERROR "Failed to capture Xendit balance" bila Xendit tidak dipakai → dilewati. |

## Hasil Fase 3 (verifikasi)
| Cek | Hasil |
|---|---|
| `composer dump-autoload`, `optimize:clear`, `config:cache`, `route:cache` | OK (435 route API) |
| `php artisan migrate` (dev + `hellom_pos_test`) | OK (`2026_10_05_000001`; 0 baris direset di DB dev) |
| `php vendor/bin/phpunit -c phpunit.pos.xml` | **99 tes, 856 asersi, OK** (sebelum: 76) · unit `php artisan test --testsuite=Unit` 28 OK |
| `npx tsc --noEmit` | 0 error |
| `npm run lint:admin` (ESLint, area admin) | 0 error, 0 warning |
| `npm run build` | OK |
| Smoke browser `tests/e2e/admin-smoke.mjs` | **23/23** (19 halaman + 3 aksi + tablet 820 px), 0 error console, 0 API gagal |
| `storage/logs/laravel.log` selama smoke | 0 error setelah perbaikan Xendit |

`migrate:fresh --seed` **tidak** dijalankan (dilarang CLAUDE.md).

**Ukuran bundle admin (lazy, setelah):** Ringkasan 8,6 KB (grafik tetap di `vendor-charts`), Pengguna 34,7 KB, Aplikasi & Paket 34,4 KB, Pengaturan 40,4 KB, Organisasi 12,4 KB, Log Audit 4,8 KB, Invoice 4,6 KB, Kesehatan Sistem 4,4 KB. Halaman lama hampir sama (+1–2 KB karena fitur baru); `lib/adminFinance.ts` dihapus.
**Query:** daftar invoice/promo/transfer manual kini `paginate` (sebelumnya memuat 30–200 baris + relasi penuh); daftar organisasi/log audit eager-load relasi (tanpa N+1); respons branding publik tidak lagi membaca file logo dari disk setiap request.

## Tindakan saat deploy ke VPS
1. `git pull` + `bash deploy/deploy.sh` (menjalankan `composer install`, `php artisan migrate --force` → migration `2026_10_05_000001_reset_foreign_current_organizations`, cache, `npm ci && npm run build`, restart PM2 realtime & queue).
2. `php artisan optimize:clear && php artisan optimize`.
3. Tidak ada key `.env` baru. ESLint hanya dev dependency (`npm ci --include=dev` di deploy.sh sudah memasangnya; tidak dipakai saat build).
4. **iPaymu**: checkout langganan/produk/top-up yang dibuat **sebelum** deploy tidak punya tanda tangan di notify URL — langganan & produk tetap diverifikasi ke API iPaymu (aman); **top-up lama tanpa tanda tangan tidak dikreditkan otomatis** (tercatat `unsigned` di Payment Events) → cek manual bila ada.
5. Langganan tahunan iPaymu yang dibayar **sebelum** deploy dengan harga bulanan kini ditolak verifikasi nominal (`amount_mismatch`) bila notifikasinya datang setelah deploy — cek Payment Events.
6. Setelah deploy: purge Cloudflare untuk halaman admin tidak diperlukan (aset ber-hash).

## Bug di luar Super Admin (dicatat, tidak diperbaiki — aturan kerja #7)
| Area | File | Temuan |
|---|---|---|
| POS / member / publik | ESLint `npm run lint:eslint` | 40 error (variabel/impor tak terpakai, `no-case-declarations`, `no-empty`) + 193 warning (`any`, deps hook) di POS (`PosReports`, `PosSettings`, `OrderPage`, `NewOrderModal`…), `pages/dashboard/Payments.tsx`, `MemberProfile`, `PublicPage`, `lib/sellerPixels.ts`. Tidak ada yang memblokir build/tsc. |
| Auth (tak terpakai) | `components/auth/AuthLeftPanel.tsx`, `PromoBanner.tsx` | Masih kandidat arsip (pertanyaan lama, belum dijawab). |
| Organisasi | `OrganizationController::settings/updateSettings` | `logo_url` memakai `url('storage/…')`, padahal disk publik kini `/media`. |
| Moderasi | `LandingModeration.tsx` | Tautan toko memakai `/${slug}`, bukan `landing_username`. |
| Billing | `BillingController::reconcileCheckout` | Memakai `payment_session_id` iPaymu sebagai id transaksi bila `transaction_id` belum tersimpan (SessionID ≠ TransactionId) → reconcile dari halaman kembali bisa "menunggu" sampai webhook datang. Tidak berbahaya. |

## Butuh keputusan pemilik sebelum Fase 2
1. **P0-1**: register memberi `member`, dan role `admin` tidak lagi lintas organisasi. Apakah ada staf Hellom yang benar-benar butuh akses lintas organisasi selain super admin?
2. **P0-2**: hapus paket = arsip, hapus user = ditolak bila ada riwayat keuangan (sarankan suspend). Setuju?
3. **P1-6 / P2-9**: tambah menu Organisasi + tombol suspend. Untuk endpoint tanpa UI, mana yang dibuatkan UI (usul: Audit Log, Invoice, Penyesuaian saldo penjual) dan mana yang dipensiunkan (usul: broadcast promo & billing reminder sampai ada antrean)?
4. **P3-9**: sederhanakan halaman Finance lama?
5. **P3-10**: boleh menambah ESLint sebagai dev dependency?
