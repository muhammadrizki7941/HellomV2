# _archive

File yang dikeluarkan dari kode aktif saat refactor (branch `refactor/cleanup`).
Tidak di-autoload, tidak di-route, tidak ikut build. Disimpan sementara sebagai
referensi; boleh dihapus permanen setelah satu siklus rilis stabil.

| Folder | Isi | Alasan |
|---|---|---|
| `backend-debug-scripts/` | Skrip CLI ad-hoc dari root `backend/` (`check_*.php`, `test_*.php`, `update_tenant.php`, `_debug_request_constants.php`, `temp_dashboard.html`) dan `backend/scripts/*.php` | Debug/one-off manual (bootstrap Laravel sendiri). Tidak direferensikan oleh route, composer script, atau kode lain. Beberapa menulis ke DB (`update_tenant.php`, `test_customer_tenant.php`, `test_middleware.php`, `test_request.php`, `test_route.php`, `scripts/add_placeholder_images.php`) — **jangan dijalankan di production**. Audit: D-2 |
