# Audit Keuangan Multi-Gateway — Fase 1

> Tanggal: 2026-10-03 · Basis: `main` = `34e2c03` (sudah termasuk perbaikan checkout Hellom Page) · Mode: **read-only, tidak ada kode yang diubah**.
> Tidak ada `.env`/kredensial yang dibaca.

## 0. Koreksi premis brief
| Brief | Kenyataan di kode |
|---|---|
| "Hanya Xendit yang terintegrasi" | **Tidak.** Ketiga gateway (iPaymu, Xendit, DOKU) sudah punya service API, pengaturan terenkripsi di dashboard, webhook, dan adapter `PaymentGateway`. **Gateway aktif produksi = iPaymu** (keputusan pemilik Q6), terverifikasi di VPS (`landing:payments-check`: iPaymu production, siap). |
| Frontend `plans/UI/`, React 18, Laravel 11 | `frontend/` (React 19 + Vite 6), Laravel 12. |
| Isolasi `pos_tenant_slug` untuk keuangan | Uang Hellom Page dikunci per **`organization_id`** (keputusan Q1); `pos_tenant_slug` hanya untuk data POS. |
| Withdraw min Rp50.000, maks 1×24 jam | **Sudah ada**: `FinanceSettings` default `min_withdrawal = 50000`, `sla_hours = 24` (bisa diubah super admin). |
| Ledger, saldo seller, fee platform | **Sudah ada** untuk penjualan Hellom Page (lihat §4); belum ada untuk produk digital owner & langganan (lihat §3). |

## 1. Modul keuangan di dashboard super admin saat ini
| Menu | Halaman React | Endpoint (prefix `/api/v1/hellom`) | Controller | Data |
|---|---|---|---|---|
| **Keuangan Platform** `/admin/finance` | `pages/admin/FinanceManagement.tsx` (+ `PayoutKycReview.tsx`) | `GET platform/finance-summary`, `POST platform/payouts`, `GET admin/billing/manual-checkouts` (+approve/reject), `GET wallet/admin/payout-queue`, `admin/payout-profiles*` | `WalletController::platformFinanceSummary/createPlatformPayout`, `ManualCheckoutReviewController`, `PayoutProfileController` | `platform_finance_ledgers`, `xendit_balance_snapshots`, `organization_wallets`, `user_wallet_ledgers`, `checkout_intents`, `organization_payout_profiles` |
| **Keuangan Penjual** `/admin/keuangan-penjual` | `pages/admin/SellerFinance.tsx` (+ `RefundsQueue.tsx`) | `admin/seller-finance/*` (summary, withdrawals approve/mark-paid/mark-failed, webhooks, reconciliation, settings, sellers/{id}, adjustment, export, refunds) | `AdminSellerFinanceController` | `landing_page_orders`, `seller_balances`, `seller_balance_ledger`, `seller_withdrawals`, `landing_refunds`, `payment_webhook_logs` |
| **Pembelian** `/admin/products/purchases` | `pages/admin/products/purchases.tsx` | `admin/product-purchases*` | `Admin\ProductPurchaseController` | `product_purchases` |
| **Invoice** `/admin/invoices` | `pages/admin/Invoices.tsx` | `admin/invoices` | `InvoiceController::adminIndex` | `invoices` |
| **Ringkasan** `/admin` | `pages/admin/AdminDashboard.tsx` | `admin/dashboard-stats`, `admin/seller-finance/summary` | `SuperAdminController`, `AdminSellerFinanceController` | agregat |
| **Pengaturan › Gateway** `/admin/settings` | `pages/admin/AdminSettings.tsx` | `admin/billing/provider-config`, `runtime-config`, `manual-payment-config` | `PaymentGatewayConfigController` | `system_settings` (kredensial **terenkripsi** `Crypt`) |

**Yang belum ada (dibutuhkan Fase 4):** satu tabel transaksi gabungan lintas sumber, rincian per gateway, saldo di tiap gateway selain Xendit, grafik tren/perbandingan gateway/top seller.

## 2. Status integrasi gateway
| | iPaymu | Xendit | DOKU |
|---|---|---|---|
| Service API | `Services/Hellom/IpaymuService` | `XenditService` | `DokuService` |
| Pengaturan dashboard (terenkripsi, sandbox/production) | ✅ `IpaymuSettingsService` | ✅ | ✅ |
| Adapter `PaymentGateway` (`Services/Payments/Gateways/*`) | ✅ charge (direct QRIS/VA/retail + hosted), status, webhook | ✅ | ✅ |
| Webhook | `IpaymuWebhookController` — token + **cek ke API** (`IpaymuPaymentVerifier`) untuk semua tujuan | `XenditWebhookController` — header `X-CALLBACK-TOKEN` (mekanisme resmi Xendit); jalur langganan/produk **tidak** cek ulang ke API | `DokuWebhookController` — HMAC + cek status API |
| Disbursement (penarikan otomatis) | ❌ di kode kita → mode **manual** (super admin transfer + bukti). Perlu cek dok. resmi apakah API transfer/withdraw tersedia untuk akun Hellom. | ✅ `XenditGateway::disburse` (Payout API) | ❌ (produk DOKU Transfer/Sub-Account terpisah, perlu kontrak) |
| Saldo akun gateway | ❌ | ✅ `XenditBalanceSnapshot` | ❌ |

**Masalah arsitektur:** abstraksi `PaymentGateway` + `GatewayRegistry` **hanya dipakai Hellom Page**. Tiga alur lain memanggil service gateway **langsung dengan cabang `if provider === …`**:
- Langganan: `Billing/BillingController` + `Concerns/InteractsWithPaymentGateways`
- Produk digital owner: `DigitalProducts/ProductCheckoutService` (punya parser iPaymu sendiri)
- Top-up dompet: `BillingController`, `WalletController` (Xendit balance/payout)

Akibatnya logika iPaymu ada **dua kali** (kasus nyata kemarin: checkout penjual gagal karena parsernya berbeda dari checkout owner).

## 3. Pencatatan transaksi per sumber
| Sumber | Tabel utama | Status | Gateway tercatat | Fee gateway | Pendapatan Hellom |
|---|---|---|---|---|---|
| Penjualan seller (Hellom Page) | `landing_page_orders` (per `organization_id`) | `pending → paid → fulfilled / failed / expired / refunded` (`TRANSITIONS`) | `provider`, `gateway_trx_id` | ✅ `gateway_fee_amount` (dari gateway atau estimasi) | ✅ `commission_amount` + `platform_finance_ledgers` (`landing_platform_fee`, expense `landing_gateway_fee`) |
| Produk digital owner | `product_purchases` (per **`user_id`**, bukan organisasi) | `pending / paid / failed / refunded` | `payment_gateway` | ❌ tidak dicatat | ❌ **tidak masuk `platform_finance_ledgers`** |
| Langganan aplikasi | `checkout_intents` + `subscriptions` + `invoices` | intent `manual_review / gateway_pending / confirmed / rejected / failed / expired` | di `metadata` (ipaymu/xendit/doku) | ❌ | ✅ `platform_finance_ledgers` (`*_subscription_payment`) |
| Top-up dompet (lama) | `organization_wallet_transactions`, `user_wallet_ledgers` | — | metadata | ❌ | — (saldo top-up tidak bisa ditarik) |
| POS | `orders` (tunai/manual di outlet) | — | **tidak lewat gateway** | — | — |

Tidak tercampur secara tabel, tetapi **tidak ada satu "jurnal" bersama**: laporan lintas sumber harus menggabungkan 4 tabel dengan bentuk berbeda.

## 4. Saldo seller, ledger, withdrawal, fee — yang sudah ada
- **Saldo seller**: `seller_balances` (cache: pending / available / processing / withdrawn) + **`seller_balance_ledger` append-only** (sumber kebenaran; `balance:reconcile` mencocokkan). Tipe: sale, platform_fee, gateway_fee, release, withdrawal(_reversal), refund(_reversal), adjustment, opening. Mutasi dalam `DB::transaction(…, 3)` + `lockForUpdate`, **`idempotency_key` unik**.
- **Status saldo**: pending → (release setelah `hold_days`) available → withdrawal (processing) → withdrawn. ✅ sesuai brief.
- **Fee**: `FeeCalculator` — `platform_fee = max(harga×% + flat, gateway_fee + margin_min)`, `seller_net`, `hellom_net`. ✅ "Plan A".
- **Withdrawal**: `SellerFinance\WithdrawalService` (min, biaya, SLA, nama rekening = KYC, tahan 24 jam saat ganti rekening, mode manual/otomatis). ✅
- **Bukan double-entry**: ledger seller hanya mencatat sisi seller; sisi Hellom ada di `platform_finance_ledgers` (tabel lain, model lain), sisi gateway tidak ada.

## 5. Risiko yang ditemukan
| ID | Prioritas | Risiko | Lokasi | Usulan |
|---|---|---|---|---|
| R1 | **Tinggi** | `platform_finance_ledgers` menghitung `balance_before/after` dari baris terakhir **tanpa lock** dan **tanpa kunci unik** → saldo berjalan bisa salah saat dua pembayaran bersamaan; satu kejadian bisa tercatat dua kali bila dipanggil ulang. | `Models/PlatformFinanceLedger::recordRevenue/recordExpense` | Jurnal baru dengan idempotency key unik; lock baris saldo akun. |
| R2 | **Tinggi** | Pendapatan produk digital owner **tidak tercatat** di ledger platform → "pendapatan platform" di dashboard kurang. | `ProductCheckoutService`, webhook iPaymu/DOKU/Xendit jalur produk | Catat ke jurnal saat lunas/refund (+ backfill dari `product_purchases`). |
| R3 | Sedang | Fee gateway untuk langganan & produk owner tidak dicatat → laba bersih Hellom tidak akurat. | sama | Simpan fee dari status gateway (iPaymu `Fee`) atau estimasi `FeeCalculator`. |
| R4 | Sedang | Logika gateway terduplikasi (adapter vs pemanggilan langsung) — sumber bug kemarin. | §2 | Semua alur lewat `PaymentGateway` (Fase 2). |
| R5 | Sedang | Webhook Xendit jalur langganan/produk/top-up percaya isi notifikasi (hanya token header). | `XenditWebhookController` | Cek ulang ke API (pola `IpaymuPaymentVerifier`) — Xendit bukan gateway aktif, jadi bukan darurat. |
| R6 | Rendah | `product_purchases` terikat `user_id`, bukan organisasi → tidak bisa difilter per tenant di laporan. | skema | Kolom `organization_id` nullable (migration baru) bila dibutuhkan. |
| R7 | Rendah | Saldo di akun gateway hanya Xendit (`xendit_balance_snapshots`). | — | Tambah `getBalance()` bila API tersedia (cek dok. iPaymu/DOKU). |
| — | Aman | Race saldo seller, idempotency webhook Hellom Page, verifikasi iPaymu/DOKU, kebocoran lintas tenant di endpoint penjual — sudah ditangani (tes `tests/Landing/*`, `tests/Admin/*`). | | |

## 6. Rencana perubahan (usulan, menunggu persetujuan)
**Fase 2 — gateway agnostik (iPaymu dulu):**
1. Lengkapi `PaymentGateway`: tambah `getBalance()` (opsional per gateway). Webhook tetap per controller tetapi memakai `getStatus()`.
2. Pindahkan **produk digital owner** dan **langganan** ke `GatewayRegistry` (hapus cabang `if provider`); parser iPaymu tunggal = `IpaymuGateway`. Xendit & DOKU tetap jalan lewat adapter masing-masing.
3. Verifikasi dokumentasi resmi iPaymu & DOKU (endpoint saldo & transfer/disbursement) sebelum menulis kode; bila iPaymu tidak menyediakan transfer ke rekening pihak ketiga, penarikan seller tetap **manual** (alur sekarang) atau lewat Xendit Payout bila diaktifkan.

**Fase 3 — jurnal keuangan (double-entry, additive):**
4. Tabel baru `finance_journal_entries` (+ `finance_accounts`): setiap kejadian = beberapa baris yang berjumlah nol, mis. pembayaran seller 100.000 → `gateway_clearing:ipaymu +100.000`, `seller:{org}:pending −90.000`, `hellom:fee_revenue −10.000`, lalu `gateway_fee` dsb. `idempotency_key` unik per kejadian, `DB::transaction` + lock akun.
5. **Tidak mengganti** `seller_balance_ledger` (sudah berjalan di produksi & teruji); jurnal ditulis di titik yang sama + backfill dari data lama, dengan perintah rekonsiliasi jurnal ↔ ledger seller ↔ tabel sumber.
6. Catat produk digital owner & langganan (+ fee gateway) ke jurnal; `platform_finance_ledgers` dipertahankan untuk kompatibilitas lalu dipensiunkan.

**Fase 4 — dashboard real-time:**
7. Endpoint ringkasan dari jurnal: dana masuk per gateway, saldo tertahan per gateway (dari API bila ada, selain itu = clearing di jurnal), total saldo seller, pendapatan platform, fee gateway.
8. Tabel transaksi gabungan (view/query atas jurnal + tabel sumber) dengan filter gateway / sumber / seller / status / tanggal + detail.
9. Real-time: **polling ringan (15–30 dtk) + notifikasi Socket.IO yang sudah ada** (room `admins`, khusus super admin, di `realtime/server.js`) untuk memicu refresh saat pembayaran masuk — tanpa server baru. SSE tidak dipilih karena PHP-FPM menahan worker per koneksi.
10. Grafik Recharts: tren pendapatan, perbandingan gateway, top seller.

**File terdampak (perkiraan):** `Services/Payments/*`, `Services/Hellom/{Ipaymu,Doku,Xendit}Service.php`, `Services/DigitalProducts/ProductCheckoutService.php`, `Http/Controllers/Api/V1/Hellom/Billing/*`, webhook controllers, `Services/SellerFinance/{LandingPaymentService,SellerLedger,WithdrawalService}.php`, `Models/PlatformFinanceLedger.php`, migration baru (jurnal, `organization_id` di `product_purchases`), `routes/api/admin.php`, `frontend/src/pages/admin/{FinanceManagement,SellerFinance}.tsx` + halaman/komponen baru, `services/api/*`, tes baru `tests/Finance`.

## 7. Keputusan yang dibutuhkan
1. **Jurnal double-entry additive** (berdampingan dengan ledger seller yang sudah ada) — setuju? Mengganti ledger seller yang sedang dipakai produksi tidak disarankan.
2. **Cakupan Fase 2**: memindahkan checkout **langganan** dan **produk digital owner** ke adapter gateway (memengaruhi alur yang sekarang berhasil di produksi; akan diuji penuh) — setuju, atau hanya Hellom Page + produk owner dulu?
3. **Penarikan seller**: tetap manual (super admin transfer) bila iPaymu tidak punya API transfer, atau aktifkan Xendit hanya untuk payout?
4. **Backfill**: isi jurnal dari data historis (`landing_page_orders`, `product_purchases`, `checkout_intents` lunas) agar grafik punya riwayat — setuju (dengan mode laporan dulu, `--force` setelah dicek)?
