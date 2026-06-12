# Notification System Progress

## Status: Phase 2 Complete

## What Works (Owner)
- `createNewUserNotif()`, `createSubscriptionRenewalNotif()`, `createExpiryReminderNotif()`, `createManualConfirmationNotif()`, `createGatewayPaymentSuccessNotif()`
- Owner routes: list, unread count, mark read, mark all read, show detail, execute action, ignore action, delete
- Action fields added: `action_type`, `action_url`, `action_status`, `action_done_at`, `reference_id`, `reference_type`

## What Works (Consumer)
- `notifyConsumerPaymentSuccess()`, `notifyConsumerPaymentPending()`, `notifyConsumerPaymentFailed()`, `notifyConsumerRefundProcessed()`, `notifyConsumerRefundDone()`, `notifyConsumerAccessActivated()`, `notifyConsumerExpiryWarning()`, `notifyConsumerMaintenance()`, `notifyConsumerPromo()`
- Consumer routes: index, unread count, mark one read, mark all read
- Frontend files created: `plans/UI/src/hooks/useConsumerNotifications.ts`, `plans/UI/src/components/consumer/NotificationBell.tsx`

## Files Changed
backend:
  - backend/database/migrations/2026_05_14_101000_add_action_fields_to_owner_notifications_table.php [created]
  - backend/database/migrations/2026_05_14_101100_create_consumer_notifications_table.php [created]
  - backend/app/Models/OwnerNotification.php [modified]
  - backend/app/Models/ConsumerNotification.php [created]
  - backend/app/Services/NotificationService.php [modified]
  - backend/app/Http/Controllers/Api/V1/Consumer/NotificationController.php [created]
  - backend/app/Http/Controllers/Admin/OwnerNotificationController.php [modified]
  - backend/app/Http/Controllers/Api/V1/Hellom/XenditWebhookController.php [modified]
  - backend/app/Http/Controllers/Api/V1/Hellom/IpaymuWebhookController.php [modified]
  - backend/app/Http/Controllers/Api/V1/Hellom/DokuWebhookController.php [modified]
  - backend/app/Http/Controllers/Api/V1/Hellom/BillingController.php [modified]
  - backend/routes/api.php [modified]
frontend:
  - plans/UI/src/lib/hellomApi.ts [modified]
  - plans/UI/src/hooks/useNotifications.ts [modified]
  - plans/UI/src/hooks/useConsumerNotifications.ts [created]
  - plans/UI/src/components/admin/NotificationBell.tsx [modified]
  - plans/UI/src/components/admin/NotificationDropdown.tsx [modified]
  - plans/UI/src/components/consumer/NotificationBell.tsx [created]
  - plans/UI/src/layouts/DashboardLayout.tsx [modified]
  - plans/UI/src/App.tsx [modified]

## DB Tables
owner_notifications: id, type, title, message, data, is_read, action_type, action_url, action_status, action_done_at, reference_id, reference_type, notifiable_id, notifiable_type, created_at, updated_at
consumer_notifications: id, user_id, type, title, body, data, is_read, read_at, action_type, action_url, created_at, updated_at

## API Endpoints
OWNER: GET /api/v1/hellom/admin/notifications → OwnerNotificationController@index
OWNER: GET /api/v1/hellom/admin/notifications/unread-count → OwnerNotificationController@unreadCount
OWNER: GET /api/v1/hellom/admin/notifications/{id} → OwnerNotificationController@show
OWNER: PATCH /api/v1/hellom/admin/notifications/{id}/read → OwnerNotificationController@markAsRead
OWNER: PATCH /api/v1/hellom/admin/notifications/read-all → OwnerNotificationController@markAllAsRead
OWNER: POST /api/v1/hellom/admin/notifications/{id}/execute → OwnerNotificationController@executeAction
OWNER: POST /api/v1/hellom/admin/notifications/{id}/ignore → OwnerNotificationController@ignoreAction
OWNER: DELETE /api/v1/hellom/admin/notifications/{id} → OwnerNotificationController@destroy
CONSUMER: GET /api/v1/hellom/consumer/notifications → Consumer\NotificationController@index
CONSUMER: GET /api/v1/hellom/consumer/notifications/unread-count → Consumer\NotificationController@unreadCount
CONSUMER: POST /api/v1/hellom/consumer/notifications/read-all → Consumer\NotificationController@markAllRead
CONSUMER: POST /api/v1/hellom/consumer/notifications/{id}/read → Consumer\NotificationController@markRead

## Webhook Hooks
- XenditWebhookController: success → owner gateway success + consumer payment success + consumer access activated; pending → consumer payment pending; fail → consumer payment failed
- IpaymuWebhookController: success → owner gateway success + consumer payment success + consumer access activated; pending/non-success waiting → consumer payment pending; fail/non-success other → consumer payment failed
- DokuWebhookController: success → owner gateway success + consumer payment success + consumer access activated; pending → consumer payment pending; fail → consumer payment failed

## What Remains (Phase 3)
- Broadcast promo UI (owner kirim manual ke semua consumer)
- Consumer notification full page /dashboard/notifications
- Expiry cron: H-30, H-7, H-3, H-1 reminder scheduling
- WhatsApp / email channel for consumer notifs

Saya sudah pelajari alurnya. Intinya: sistem sekarang sudah punya pondasi checkout yang bagus, tapi ada beberapa jalur yang belum “satu rasa” antara POS/App Management dan produk digital owner.

Temuan Utama

App Management / POS
POS adalah app katalog platform. Datanya lewat apps, plans, subscriptions, checkout_intents, entitlements.
Checkout modernnya ada di BillingController.php (line 311), endpoint billing/checkout-start.
Ini sudah mendukung:

pilih app + plan
wallet/direct payment
manual payment method
gateway otomatis Xendit/iPaymu/DOKU
manual confirmation
provisioning POS setelah entitlement aktif
Kelola Produk / produk owner
Produk digital owner memakai jalur lain: DigitalProduct, ProductPurchase.
Checkout-nya ada di ProductController.php (line 75).
Ini juga sudah mendukung:

produk free langsung aktif
produk paid via gateway
produk paid via manual payment
status di my-purchases
tombol konfirmasi WhatsApp untuk manual payment
Perbedaan besar sekarang

POS/App subscription memakai CheckoutIntent, Subscription, Invoice, Entitlement.
Produk digital memakai ProductPurchase.
Jadi backend belum satu tabel transaksi, tapi konsepnya sudah mirip: pending, paid, manual, gateway, checkout_url, notifikasi.
Masalah App Management plan POS
plans tidak punya app_id. Plan POS ditentukan dari slug saja, misalnya harus pos_....
Ini terlihat di PricingController.php (line 139) dan BillingController.php (line 2244).
Artinya: kalau super admin tambah plan POS tapi slug-nya bukan pos_..., plan itu tidak akan muncul di modal POS. Ini rawan.

UI checkout POS
SubscriptionModal sudah memuat pricing matrix, gateway status, manual payment, dan memanggil checkout-start.
Tapi UX-nya belum benar-benar step-by-step. User memilih metode pembayaran di panel atas, lalu klik tombol di kartu plan. Secara teknis jalan, tapi secara flow terasa bisa “langsung bayar” tanpa urutan jelas.

Flow belum login
Login/register sudah punya intent POS ?subscribe=1, tapi belum menyimpan pilihan lengkap seperti plan_slug, payment_flow, manual_payment_method.
Jadi belum bisa: pilih paket + metode dulu, login/register, lalu otomatis lanjut checkout dengan pilihan tadi.

Rekomendasi Perbaikan

Buat satu komponen checkout wizard reusable:

Step 1: pilih paket atau produk
Step 2: pilih metode pembayaran
Step 3: konfirmasi checkout
Step 4: hasil: payment URL, manual WhatsApp, atau redirect my-purchases/payments
Untuk POS/App subscription:

tetap pakai checkout-start
jangan izinkan submit kalau belum memilih metode pembayaran
untuk manual tampilkan instruksi + tombol WhatsApp/konfirmasi
untuk gateway buka payment URL
setelah sukses redirect ke dashboard purchase/payment status
Untuk produk digital:

UI disamakan dengan wizard yang sama
backend tetap pakai ProductPurchase dulu agar tidak terlalu besar migrasinya
hasil akhir tetap masuk my-purchases
Untuk user belum login:

simpan checkout intent sementara di localStorage, misalnya:
type=app, app_slug=pos, plan_slug=pos_starter_monthly, payment_flow=direct, manual_payment_method=qris
setelah login/register, baca intent itu lalu otomatis panggil checkout
kalau gateway: buka payment URL
kalau manual: redirect ke status/purchase dan tampilkan instruksi WhatsApp
Untuk App Management:

minimal: tambahkan validasi/UX bahwa plan POS harus slug pos_...
ideal: tambah relasi plans.app_id, lalu App Management bisa benar-benar “Tambah plan untuk POS” tanpa bergantung ke slug
modal checkout mengambil description, features, is_recommended, sort_order dari plan yang diedit super admin
Menurut saya arah paling aman: fase pertama samakan flow UI dan intent login dulu tanpa migrasi database besar. Setelah itu baru fase kedua tambah relasi app_id ke plans supaya App Management lebih rapi dan tidak bergantung slug.





9:05 PM
terkait flow ccara chekcout sdbelmlogin atau register gimana? sudah kamu pahmi juga?
9:08 PM

Sudah, saya pahami.

Flow yang kamu mau untuk checkout sebelum login/register adalah begini:

User publik klik Beli Sekarang / Aktifkan POS.
Sistem tetap tampilkan modal checkout dulu:
kalau POS/subscription: pilih paket dulu
kalau produk sekali beli: tidak perlu pilih paket
pilih metode pembayaran: gateway otomatis atau manual transfer/QRIS/GoPay/DANA
Setelah pilihan lengkap, sistem belum memproses payment karena user belum login.
Sistem simpan “checkout intent sementara” di browser, misalnya:
tipe checkout: app_subscription atau product_purchase
app_slug / product_slug
plan_slug jika ada
payment_flow
manual_payment_method
User diarahkan ke login atau register.
Setelah login/register sukses, sistem membaca intent tadi dan otomatis melanjutkan checkout ke backend.
Jika payment gateway otomatis:
backend buat checkout/payment URL
user diarahkan/dibukakan payment gateway
setelah webhook sukses, akses otomatis aktif
Jika manual transfer/QRIS manual:
backend buat transaksi pending/manual
user diarahkan ke My Purchases atau halaman status pembayaran
tampil tombol/instruksi Konfirmasi via WhatsApp
owner/super admin konfirmasi manual, lalu akses aktif
Yang belum rapi di sistem saat ini: intent sebelum login baru sebatas URL seperti ?subscribe=1 atau hellom_intended_url, belum menyimpan pilihan lengkap paket + metode pembayaran. Jadi bagian itu nanti perlu kita tambahkan agar flow publik benar-benar nyambung otomatis setelah login/register.





9:08 PM
oke buat oerencaan mana dulu bagsunay yang mau dikerjakan. baru eksekusi edit danceklist jika ssay asudha konfirmasi sudah bagus  berjalan.
9:10 PM

Bagus. Saya sarankan kerjanya bertahap supaya tidak merusak billing yang sudah ada.

Rencana Pengerjaan

Rapikan kontrak checkout intent publik
Buat format intent sementara di frontend, misalnya:

kind: app_subscription | digital_product
app_slug, plan_slug, product_slug
payment_flow
manual_payment_method
return_to
Bangun helper frontend untuk simpan/ambil intent
Tambahkan util di hellomApi atau file kecil baru, agar login/register, landing, POS, dan produk digital memakai format yang sama.

Perbaiki flow belum login
Saat user publik pilih paket/metode lalu klik lanjut:

simpan intent
redirect ke /login?...
kalau belum punya akun, register tetap membawa intent yang sama
setelah login/register, intent otomatis diproses
Samakan modal checkout POS/App
SubscriptionModal dibuat lebih step-by-step:

pilih paket
pilih metode pembayaran
konfirmasi
Tidak boleh langsung submit sebelum metode valid.
Samakan checkout produk digital
Produk sekali beli tidak pakai pilihan paket, tapi tetap pakai pola:

lihat produk
pilih metode pembayaran
konfirmasi checkout
gateway/manual diarahkan sesuai hasil backend
Redirect hasil pembayaran

gateway otomatis: buka payment URL, lalu arahkan user ke halaman status terkait
manual: arahkan ke My Purchases atau detail checkout dengan tombol WhatsApp
Rapikan App Management plan POS
Untuk fase aman dulu:

tampilkan peringatan/validasi bahwa plan POS harus slug pos_...
pastikan description, features, recommended, sort_order tampil di modal checkout
Verifikasi
Checklist manual:

belum login beli POS subscription
belum login beli produk digital
login dari modal checkout
register dari modal checkout
gateway mode
manual transfer/QRIS
plan POS dari App Management muncul di modal
produk digital muncul di My Purchases
Urutan terbaik menurut saya
Mulai dari 1-3 dulu: format intent + simpan intent + proses otomatis setelah login/register. Ini fondasi paling penting untuk flow sebelum login.

Setelah itu baru 4-6 untuk merapikan pengalaman modal dan hasil pembayaran. Terakhir 7-8 untuk App Management POS dan verifikasi penuh.

Kalau kamu setuju, saya akan mulai eksekusi fase pertama: bikin checkout intent frontend, integrasi login/register, dan pastikan flow setelah auth bisa lanjut otomatis.





9:10 PM
ok kerjakan  1-3 dulu
9:11 PM


Worked for 3m 33s
Sudah saya kerjakan fase 1-3.

Yang berubah:

Menambah helper intent checkout di checkoutIntent.ts (line 58).
Login dan register sekarang mencoba lanjutkan pending checkout setelah auth sukses:
LoginPage.tsx (line 69), RegisterPage.tsx (line 51).
Tombol publik POS/produk sekarang menyimpan intent checkout baru, bukan cuma URL lama.
/dashboard/apps/pos?subscribe=1 sekarang otomatis membuka modal aktivasi POS di DashboardLayout.tsx (line 150).
Checklist fase 1-3

Format intent frontend dibuat: app_subscription dan digital_product.
Intent bisa disimpan, dibaca, dibersihkan, dan diproses setelah login/register.
Jika intent lengkap, sistem lanjut ke backend checkout.
Jika intent belum lengkap, sistem redirect ke halaman/modal checkout yang benar.
Entry publik POS menyimpan intent aktivasi POS.
Entry publik produk menyimpan intent produk yang diklik.
Build frontend berhasil.
Verifikasi: npm run build sukses. Ada warning lama soal font/chunk size dari Vite, tapi tidak menggagalkan build.