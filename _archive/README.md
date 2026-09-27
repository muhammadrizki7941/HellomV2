# _archive

File yang dikeluarkan dari kode aktif saat refactor (branch `refactor/cleanup`).
Tidak di-autoload, tidak di-route, tidak ikut build. Disimpan sementara sebagai
referensi; boleh dihapus permanen setelah satu siklus rilis stabil.

| Folder | Isi | Alasan |
|---|---|---|
| `backend-debug-scripts/` | Skrip CLI ad-hoc dari root `backend/` (`check_*.php`, `test_*.php`, `update_tenant.php`, `_debug_request_constants.php`, `temp_dashboard.html`) dan `backend/scripts/*.php` | Debug/one-off manual (bootstrap Laravel sendiri). Tidak direferensikan oleh route, composer script, atau kode lain. Beberapa menulis ke DB (`update_tenant.php`, `test_customer_tenant.php`, `test_middleware.php`, `test_request.php`, `test_route.php`, `scripts/add_placeholder_images.php`) — **jangan dijalankan di production**. Audit: D-2 |
| `docs/running-this-app.txt` | Catatan cara menjalankan app versi lama | Usang: path `Self-OrderMenu`, Midtrans, menu Blade `/admin/*`. Diganti README/DEPLOY baru. Audit: D-11 |
| `docs/ai-studio-metadata.json` | `metadata.json` dari template Google AI Studio | Tidak dibaca oleh build/Vite. Audit: D-11 |
| `backend-unused/` | Kelas & file backend tanpa referensi (struktur path dipertahankan): `Api/AnalyticsController`, `Admin/ManageMenuController`, `Customer/{Pending,TablePending}OrderController`, `PosContextService`, `GoogleMaps/GoogleMapsService`, `Gateway/*`, `Tenancy/TenantResolver`, controller Breeze yang tidak di-route (email verification, password confirm/reset/update, `ProfileController`) + view & tesnya, command `test:end-to-end-flows`, `test:tenant-isolation` (memakai tenant dummy), duplikat `TenantBackfillSettingsCommand` (identik dengan closure di `routes/console.php`), `resources/js/hellom/api/*` + `tsconfig.hellom-wallet.json` | `git grep` nama kelas = 0 referensi di app/routes/config/database/bootstrap/views/tests; URL Breeze tidak ada di `route:list`. Audit: D-4, D-5, D-10 |
| `blade-ui/` | UI Blade lama (±50 controller admin/kasir/customer/marketing/auth, ±120 view, route `admin/cashier/customer/auth/auth_global/marketing.php`, middleware dummy auth/tenancy, `DummyAuthService`, `PaymentGateway`+Midtrans, `PointsService`, aset Vite/Tailwind backend, tes Blade, skrip kiosk kasir) | Production Nginx hanya meneruskan `/api`, `/storage`, `/media`, `/socket.io` ke Laravel (dikonfirmasi pemilik 2026-09-28) → tidak terjangkau; kiosk kasir sudah pindah ke SPA. Diverifikasi: 315 route API identik, email tetap memakai `resources/views/emails`. Audit: D-3 |
