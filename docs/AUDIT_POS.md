# Audit POS — Self-order, Member & Poin, Sinkronisasi (F, G, H)

Tanggal: 2026-09-28 · Cakupan: `CustomerOrderController` (self-order publik), `Pos\PosOrderController` (kasir),
`OrderController::confirmPayment` (pembayaran), `Pos\PosMemberController`, `Pos\PosLoyaltyController`,
model `Order`, `PosMember`, `PosPointTransaction`, `DiningTable`, `Outlet`, `realtime/server.js`, frontend `PosOrders`, `useOrderTracking`.

Catatan konteks: di kode ini **"tenant" POS = outlet**. Setiap outlet punya `tenant_slug` sendiri; `organizations.pos_tenant_slug`
= slug outlet utama. Produk, meja, order, member disimpan dengan `tenant_id` = slug outlet. Kolom `outlet_id` sudah ada
di tabel POS (migration 2026_06_23) tetapi **tidak diisi** oleh alur order.

Skala data lokal saat audit (hanya hitungan): 16 meja, 64 order, 9 member, 14 transaksi poin.

Prioritas: 🔴 kritis (uang/poin/data salah) · 🟠 tinggi · 🟡 sedang.

---

## F. Self-order

| ID | Prio | Temuan | Bukti |
|---|---|---|---|
| F-1 | 🟠 | QR memakai `dining_tables.public_id`. Meja baru: 12 karakter acak (`Str::random(12)`) → tidak bisa ditebak. **Tetapi 11 dari 16 meja punya token yang bisa ditebak** (`table00001`, `table-1` dari seeder/data lama). Tidak ada fitur regenerate token; QR tidak bisa dicabut. | `DiningTable::creating`, `PosTableController::store`, seeder `AdminTenantSeeder` |
| F-2 | 🟡 | Token tidak menyimpan outlet secara eksplisit; outlet diturunkan dari `dining_tables.tenant_id` (slug outlet). 6 meja tanpa `tenant_id` sama sekali (tidak bisa dipakai / ambigu). `dining_tables.code` **unik global** (tenant B tidak bisa memakai kode "T1" jika tenant A sudah). | migration `create_dining_tables_table` |
| F-3 | ✅ | Produk divalidasi milik tenant meja (`where tenant_id = table.tenant_id`), harga diambil dari DB. Token outlet A tidak bisa memesan produk outlet B. | `createOrder` |
| F-4 | 🟠 | Menu tidak mengikuti ketersediaan waktu: `isAvailableNow()` dikirim ke frontend, tetapi **tidak dicek saat submit**. Tidak ada jam buka outlet (model `Outlet` tidak punya jam operasional). | `menuResponse`, `createOrder` |
| F-5 | 🟠 | Self-order **tidak mendukung add-on/opsi** (kasir mendukung `items.*.options`) → produk dengan opsi wajib bisa dipesan tanpa opsi, dan harga bisa beda dengan kasir. | `createOrder` vs `PosOrderController::store` |
| F-6 | 🟡 | `customer_phone` divalidasi tetapi **tidak disimpan** dan tidak ditautkan ke member → poin tidak bisa diberikan untuk order self-order. | `createOrder` |
| F-7 | 🟠 | Anti-spam hanya `throttle:hellom-public-write` (60/menit per IP). Tidak ada limit per token meja, tidak ada batas order "menunggu" per meja. Order langsung **mengurangi stok**, jadi spam bisa menghabiskan stok. | routes `public.php`, `createOrder` |
| F-8 | 🔴 | **Tidak ada event realtime untuk order** (tidak ada emit di backend). Kasir mengandalkan polling 30 detik (`PosOrders`), pelanggan polling (`useOrderTracking`). Order baru bisa terlambat hingga 30 detik; tidak ada suara/badge. | grep `emit` di `app/`; `PosOrders.tsx:79` |
| F-9 | 🟡 | `realtime/server.js` masih mengizinkan socket anonim `join` ke room `tenant_*` (legacy Blade). Saat ini tidak ada emit ke room itu, tetapi jika nanti order di-broadcast ke `tenant_*`, siapa pun bisa mendengarkan. | `server.js:132` |
| F-10 | 🟡 | Total self-order = Σ harga × qty; **tidak ada pajak/service/pembulatan** di mana pun (kasir juga tidak). Konsisten, tapi fitur belum ada. Diskon hanya di kasir (reward rule). | `createOrder`, `store` |
| F-11 | 🟡 | "Order dari link organisasi" (`/customer/{org}`) memilih meja pertama/"umum" secara otomatis → order tanpa QR tercatat ke meja sungguhan. | `resolvePublicEntryTable` |
| F-12 | 🟡 | Branding self-order campur: nama/logo/banner dari organisasi, tetapi warna, Instagram, rating Google dari `BrandSetting` **platform** (bukan tenant). Tidak bocor antar tenant, tapi bukan branding toko. | `buildCustomerExperience` |
| F-13 | 🟡 | Metode bayar self-order mengikuti `pos_payment_settings` per slug outlet ✅; tetapi `require_paid_before_submit` dari `PaymentSetting` platform, dan `createOrder` mengabaikannya (`$requirePayment = false`). | `createOrder` |
| F-14 | 🟡 | Meja "sedang dipakai": tidak ada konsep tagihan meja; order baru selalu dibuat terpisah. Tidak ada penggabungan tagihan per meja. | — |

## G. Member & Poin

| ID | Prio | Temuan | Bukti |
|---|---|---|---|
| G-1 | 🔴 | **Scope member tidak konsisten.** Kasir (`search`, `store`) dan daftar publik memakai slug **utama organisasi** (`getOrg()->pos_tenant_slug`), sedangkan `PosOrderController::store` dan pemberian poin mencari member dengan slug **outlet aktif**. Di outlet kedua: member tidak ditemukan saat order (`MEMBER_NOT_FOUND`) dan poin tidak diberikan. | `PosMemberController`, `PosOrderController::store`, `OrderController::awardLoyaltyPoints` |
| G-2 | 🟠 | Unik `(tenant_id, phone)` memakai nomor **mentah**: `08123`, `628123`, `+628123` dianggap orang berbeda. 9/9 nomor lokal belum berformat 628. Unik per tenant (bukan global) ✅. | migration `create_pos_members_table` |
| G-3 | 🔴 | **Poin bisa diberikan dua kali**: `confirmPayment` memberi poin saat lunas, `updateStatus` juga memberi poin saat status → `completed` (tanpa melihat pembayaran). Selesai lalu bayar = poin ganda. | `OrderController::confirmPayment`, `PosOrderController::updateStatus` |
| G-4 | 🔴 | `updateStatus` menerima transisi apa pun (`completed → new → completed`) → poin diberikan lagi setiap kali kembali ke `completed`. | `updateStatus` |
| G-5 | 🔴 | Poin **tidak pernah dikurangi**: tidak ada alur penukaran poin. Reward rule (ambang poin/order/belanja) bisa dipakai member mana pun **tanpa cek ambang** dan berulang kali. `PosRedemption.points_used` selalu 0. | `store` (reward_rule_id), `PosLoyaltyController::applyReward` |
| G-6 | 🔴 | Batal/void/refund: poin **tidak ditarik**, stok **tidak dikembalikan**. Tidak ada status refund. | — |
| G-7 | 🟠 | Saldo disimpan di dua kolom (`total_points`, `redeemable_points`) + ledger `pos_point_transactions`, di-update terpisah tanpa lock → rawan race; tipe ledger tidak punya `reversal`/`adjust`; `balance_after` dihitung dari model yang mungkin basi. Saat audit saldo = Σ ledger untuk semua member ✅. | `awardPointsForOrder`, enum `type` |
| G-8 | 🟡 | `PosLoyaltyController::applyReward` memakai slug utama (bukan outlet aktif) dan hanya menghitung, tidak mencatat. | `applyReward` |
| G-9 | 🟡 | Perhitungan poin di-copy 4× (`PosOrderController`, `OrderController`, `CustomerOrderController`, `PosExperienceController`). Hasil sama hari ini, tapi mudah berbeda. | — |
| G-10 | 🟡 | `publicLookup` (cari member by nomor HP + org) publik; di-rate-limit, tapi mengembalikan data member → enumerasi nomor. | routes `public.php` |
| G-11 | ✅ | Data member tidak bisa diakses lintas tenant lewat endpoint kasir (selalu difilter `tenant_id`). | — |

## H. Sinkronisasi antar modul

| ID | Prio | Temuan |
|---|---|---|
| H-1 | 🔴 | **Tiga kode pembuatan/penyelesaian order terpisah**: `CustomerOrderController::createOrder` (tanpa opsi, tanpa member), `PosOrderController::store` (opsi, reward), `OrderController::confirmPayment` (bayar + status selesai + poin). Tidak ada `OrderService`/event. |
| H-2 | 🔴 | Laporan menghitung omzet dari `status = completed`, **bukan `payment_status = paid`** → order selesai tapi belum dibayar masuk omzet; order lunas yang kemudian di-set ke status lain hilang dari laporan. |
| H-3 | 🟠 | Stok dikurangi saat order **dibuat** (kasir & self-order), tidak pernah dikembalikan saat batal. |
| H-4 | 🟠 | Membayar langsung menandai order `completed` (melompati dapur); belum ada tampilan dapur/KDS. |
| H-5 | 🟠 | Nomor order `ORD-YYYYMMDD-NNN` **global lintas semua tenant**, 3 digit, dibuat dengan query "terakhir + 1" tanpa lock → bentrok saat order bersamaan dan rusak setelah 999 order/hari di seluruh platform. |
| H-6 | 🟡 | `outlet_id` tidak diisi di `orders` (2 order lokal tanpa outlet); laporan per outlet bergantung pada slug. |
| H-7 | 🟡 | Tidak ada audit log untuk perubahan status/pembayaran/poin. |

### Alur data saat ini (dan titik rawan)

```
Self-order (QR) ──createOrder──► orders(status=new, unpaid) ─ stok −  ✗ member ✗ realtime
Kasir ───────────store─────────► orders(status=new, unpaid) ─ stok −  (member, reward tanpa potong poin)
                                          │
         updateStatus(any→any) ───────────┤  completed → poin + (G-3/G-4: ganda)
         confirmPayment ─────────────────►┤  paid + completed + poin (G-3)
                                          │
Batal (cancelled) ────────────────────────┘  stok ✗ dikembalikan, poin ✗ ditarik (G-6)
Dapur: tidak ada · Laporan: status=completed (H-2) · Socket: tidak ada (F-8)
```

## Keterkaitan dengan fase lain
- "Split bill (Fase 2)" dan "tagihan meja" **belum ada** di kode ini.
- Tidak ada pajak/service charge (F-10) → perlu pengaturan baru.

## Status perbaikan — Fase 2B (2026-09-29)

Alur baru, endpoint, verifikasi dan tes: [ALUR_ORDER.md](ALUR_ORDER.md).

| Temuan | Status | Perbaikan |
|---|---|---|
| F-1 | ✅ kode · ⏳ data | Token baru 24 karakter acak; tombol **Ganti QR** + cetak massal per outlet. 11 meja lama tetap memakai token lemah sampai owner menekan Ganti QR (`pos:report weak-tokens`) — tidak diputar otomatis agar QR yang sudah tercetak tidak mati tiba-tiba. |
| F-2 | ✅ · ⏳ data | `dining_tables.outlet_id` diisi dari slug; kode meja unik per tenant. 11 meja (`tenant_id` kosong / `alpha`) tidak cocok dengan outlet mana pun → dilaporkan (`pos:report orphans`), tidak diubah. |
| F-3 | ✅ | Tetap; ditambah cek meja milik outlet yang sama (`TABLE_NOT_FOUND`). |
| F-4 | ✅ | Jam buka per outlet + ketersediaan dicek saat submit (`OUTLET_CLOSED`, pesan ramah di halaman). |
| F-5 | ✅ | Self-order mendukung add-on (validasi wajib/satu pilihan/nilai aktif) lewat `PricingService` yang sama dengan kasir. |
| F-6 | ✅ | Nomor HP disimpan dan ditautkan/didaftarkan sebagai member (`MemberService`). |
| F-7 | ✅ | Limiter per token+IP / token / IP, batas order menunggu per meja, stok dipesan & dikembalikan saat batal. |
| F-8 | ✅ | Event → socket `tenant:{slug}:outlet:{id}` (bunyi + badge) dan `table:{id}`; polling cadangan. |
| F-9 | ✅ | `join` anonim dihapus dari `server.js`. |
| F-10 | ✅ | Pajak, service charge, pembulatan per outlet. |
| F-11 | ✅ | Link toko memakai meja semu `counter` per outlet. |
| F-12 | ✅ | Branding dari organisasi/outlet; palet & Instagram platform hanya untuk meja tanpa organisasi. Tenant belum punya pengaturan warna → warna netral. |
| F-13 | ✅ sebagian | Metode bayar dibatasi ke metode aktif outlet (frontend & server). `require_paid_before_submit` tetap tidak dipakai — keputusan pemilik: bayar terpisah dari dapur, dibayar di kasir. |
| F-14 | ✅ | `table_bills`: satu tagihan terbuka per meja untuk order QR + kasir; bayar sekaligus. Split bill: fase berikutnya. |
| G-1 | ✅ | Member per organisasi (`organization_id`) di semua endpoint (kasir, publik, promo/reservasi). |
| G-2 | ✅ | `phone_normalized` 628…; duplikat dilaporkan + gabung manual ber-audit. |
| G-3, G-4 | ✅ | Poin hanya dari `OrderPaid`, idempoten; transisi status maju saja. |
| G-5 | ✅ | Tukar poin (nilai/min/maks/kedaluwarsa per organisasi, konfirmasi nama); reward rule dicek ambangnya di server. |
| G-6 | ✅ | Void/refund: poin ditarik/dikembalikan; stok kembali saat void. |
| G-7 | ✅ | Ledger `member_point_transactions` + lock + FIFO; saldo awal dimigrasi; `pos:points reconcile`. Ledger lama `pos_point_transactions` dibiarkan (read-only). |
| G-8, G-9 | ✅ | Satu `LoyaltyService`/`PricingService`; `applyReward` hanya pratinjau dengan aturan yang sama. |
| G-10 | ✅ | Lookup publik tidak lagi mengembalikan email utuh/total belanja (email disamarkan). |
| H-1 | ✅ | `OrderService` + event `OrderCreated/Confirmed/StatusChanged/Paid/Voided/Refunded`. |
| H-2 | ✅ | Laporan memakai `payment_status = paid`. Catatan: omzet masih menjumlah `total_amount` (= subtotal sebelum diskon/pajak) seperti sebelumnya — perlu keputusan apakah omzet = `final_amount`. |
| H-3 | ✅ | Stok kembali saat void (sekali, ditandai di `payment_meta`). |
| H-4 | ✅ | Bayar tidak mengubah status dapur. |
| H-5 | ✅ | Nomor order per outlet per hari, 4 digit, `order_number_sequences` + row lock. Format `ORD-YYYYMMDD-NNNN` tidak unik lintas outlet (unik bersama `tenant_id`). |
| H-6 | ✅ · ⏳ data | `orders.outlet_id` diisi; 2 order lama tanpa slug dilaporkan. |
| H-7 | ✅ | Audit log untuk semua event order, ubah poin, gabung member, ganti QR, pengaturan outlet, ekspor member. |

Keterbatasan yang diketahui:
- Migration lama `2026_04_26_013439_change_tenant_id_to_string_in_pos_tables` gagal pada database **kosong** (CASE tanpa WHEN) → instalasi baru dari nol tidak bisa `migrate` tanpa perbaikan; tidak diubah (aturan: migration yang sudah jalan tidak disentuh). Database tes dibuat dengan menyalin struktur.
- Refund selalu penuh; refund sebagian dan split bill belum ada.
- OTP WhatsApp untuk tukar poin baru berupa kelas kosong (`WhatsappOtpVerifier`), belum ada provider.
