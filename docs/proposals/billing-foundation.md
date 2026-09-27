# Usulan: Fondasi Penjualan — Langganan & Lifetime (Langkah 6)

> Status: **DISETUJUI & DIIMPLEMENTASIKAN** (branch `refactor/cleanup`, commit `ee1dfed` … `5c75d66`).
> Default untuk pertanyaan §4 yang belum dijawab (semuanya mempertahankan perilaku lama, bisa diubah kemudian):
> masa tenggang **0 hari** (`BILLING_GRACE_DAYS`); paket tahunan **tidak** diperpanjang otomatis (berakhir + notifikasi yang sudah ada);
> lifetime tetap **per aplikasi** dengan `max_outlets` dari plan; aturan upgrade **tidak diubah**.
> Backfill data lama: command `hellom:billing:backfill-entitlement-ends` (laporan dulu, tulis hanya dengan `--force`), bukan migration.
> Keputusan #4: "sekali beli" = paket **lifetime** di SaaS yang sama (bayar sekali, akses selamanya). Tanpa license key.

## 1. Kondisi saat ini (hasil penelusuran kode)

**Yang sudah ada dan berjalan:**
- Plan `pos_lifetime` (tipe `lifetime`, Rp5.000.000) sudah ada di katalog. Aktivasinya membuat `subscriptions.ends_at = null` + `entitlements.ends_at = null` → akses selamanya. Secara fungsi "sekali beli" **sudah bisa dijual**.
- Katalog: `pos_starter`/`pos_*_monthly` (subscription, bulanan), `pos_*_yearly` (tipe `one_time`, `duration_days=365`, prabayar tahunan), `pos_lifetime`.
- Pembayaran: iPaymu (utama), Xendit, DOKU, manual (approve super admin), saldo wallet. Semua berujung ke `subscriptions` + `entitlements` + `invoices`.

**Masalah yang ditemukan:**

| # | Masalah | Dampak |
|---|---|---|
| B-1 | **Akses paket tahunan tidak pernah berakhir.** `entitlements.ends_at` selalu `null`; satu-satunya proses yang mengakhiri akses adalah `hellom:billing:auto-renew-wallet`, dan itu **hanya memproses `billing_cycle = monthly`**. | Pembeli paket tahunan tetap bisa memakai POS setelah 365 hari tanpa membayar. Lokal: 7 langganan tahunan, belum ada yang lewat masa; di production kemungkinan mulai terjadi ±April 2027. **Kebocoran pendapatan.** |
| B-2 | Masa berlaku bulanan **bergantung penuh pada cron** (`schedule:run`). Middleware `canUseApp` memeriksa `entitlements.ends_at`, tetapi nilainya `null`. | Jika cron mati, langganan bulanan juga tidak pernah berakhir. |
| B-3 | Logika "kapan berakhir" **disalin 3×** (`BillingController`, `SubscriptionCheckoutActivationService`, `DokuWebhookController`) dengan perbedaan (DOKU tidak memperlakukan plan `free`), plus beberapa jalur yang meng-hardcode `addMonth()` (konfirmasi wallet, perpanjangan otomatis). | Hasil berbeda tergantung gateway. |
| B-4 | Periode tahunan ditentukan dari "plan **mendukung** yearly", bukan siklus yang **dipilih** pembeli. | Laten (tiap plan sekarang hanya punya 1 siklus). Jika nanti satu plan menawarkan bulanan+tahunan, pembeli bulanan dapat 1 tahun. |
| B-5 | 16 titik aktivasi entitlement di 8 file (`updateOrCreate` langsung). | Setiap perbaikan harus dilakukan di 16 tempat. |
| B-6 | Frontend menebak siklus dari **nama slug** (`slug.includes('yearly')`). | Plan baru dengan slug lain → siklus salah. |

## 2. Desain yang diusulkan

Prinsip: **tidak menulis ulang** alur pembayaran yang sudah berjalan; hanya menyatukan "apa yang terjadi setelah pembayaran sukses" ke satu tempat.

### 2.1 Satu sumber kebenaran periode — di model `Plan`
```php
// App\Models\Plan
public function accessEndsAt(Carbon $start, ?string $billingCycle): ?Carbon
// lifetime / free            → null (selamanya)
// duration_days terisi       → start + duration_days
// billingCycle == 'yearly'   → start + 1 tahun
// selain itu                 → start + 1 bulan
```
Memperbaiki B-3 dan B-4 (siklus yang **dipilih**, bukan yang didukung).

### 2.2 `App\Services\Billing\EntitlementService` (satu pintu aktivasi)
```php
activate(Organization $org, AppCatalog $app, Plan $plan, ?string $cycle, string $source): Subscription
extend(Subscription $sub, ?string $cycle, string $source): Subscription   // perpanjangan
expire(Subscription $sub, string $reason): void
```
- Menulis `subscriptions.starts_at/ends_at` **dan** `entitlements.ends_at` dengan nilai yang sama (`null` hanya untuk lifetime/free).
- Menjalankan provisioning POS dan menulis audit/notifikasi seperti sekarang.
- 16 titik `updateOrCreate` diganti panggilan ke service ini, dipindah **satu per satu** dengan perilaku sama kecuali poin B-1/B-2.

### 2.3 Penegakan akses tidak bergantung cron
`EnsureAppEntitlement` **sudah** menolak jika `entitlements.ends_at` lewat. Setelah `ends_at` terisi (2.2), akses berakhir tepat waktu walaupun cron mati. Cron tetap berguna untuk perpanjangan otomatis, status `expired`, dan notifikasi.
Opsional: masa tenggang `BILLING_GRACE_DAYS` (mis. 3 hari) supaya kasir tidak terkunci di tengah jam operasional saat pembayaran perpanjangan telat.

### 2.4 Lifetime
- Tetap memakai tipe plan `lifetime` yang sudah ada: bayar sekali → `ends_at = null`, tanpa perpanjangan, satu invoice.
- Tidak ada license key / aktivasi perangkat (sesuai keputusan #4).
- `one_time` + `duration_days` (prabayar tahunan) tetap didukung; di UI admin diberi label "Prabayar (tanpa perpanjangan otomatis)" agar tidak tertukar dengan lifetime.

### 2.5 Frontend
API katalog/pricing mengembalikan `billing_cycle` dan `type` eksplisit; `SubscriptionModal`/`checkoutIntent` memakai field itu, bukan menebak dari slug (B-6).

### 2.6 Migrasi data (migration **baru**, non-destruktif)
Backfill `entitlements.ends_at` dari `subscriptions.ends_at` aktif terakhir untuk plan non-lifetime/non-free.
⚠ Sebelum deploy: jalankan query laporan (read-only) untuk melihat organisasi yang **langsung terkunci** setelah backfill (langganan yang seharusnya sudah berakhir tapi masih aktif karena cron/bug B-1). Anda memutuskan: kunci, beri masa tenggang, atau perpanjang manual.

## 3. Urutan pengerjaan (setelah disetujui)
1. `Plan::accessEndsAt()` + unit test (murni, tanpa DB).
2. `EntitlementService` + pindahkan jalur aktivasi satu per satu (commit per jalur, verifikasi dengan transaksi rollback seperti hotfix Langkah 0).
3. Perpanjangan otomatis & perintah kedaluwarsa memakai service (bulanan **dan** tahunan).
4. Query laporan dampak → keputusan Anda → migration backfill.
5. Frontend memakai `billing_cycle`/`type` dari API.

## 4. Pertanyaan untuk Anda
1. **Setuju dengan desain 2.1–2.6?**
2. **Masa tenggang** setelah langganan berakhir: 0 hari (langsung terkunci) atau N hari?
3. **Paket tahunan saat habis**: diperpanjang otomatis dari saldo wallet seperti bulanan, atau cukup berakhir + notifikasi (pembeli bayar manual)?
4. **Lifetime**: apakah mencakup aplikasi lain di masa depan, atau hanya POS? Apakah ada batas outlet (`max_outlets`) khusus lifetime?
5. **Upgrade** dari bulanan/tahunan ke lifetime: bayar penuh, atau dikurangi sisa masa langganan (prorata)?
