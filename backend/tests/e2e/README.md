# Hellom Page end-to-end (browser)

Full seller + buyer journey in headless Chrome, **on the test database only** (`hellom_pos_test`),
with local stand-ins for iPaymu and email. Nothing reaches a real gateway or inbox.

| File | What it does |
|---|---|
| `seed.php` | iPaymu in sandbox mode + a super admin; `cleanup` removes everything the journey created. Refuses any DB other than `hellom_pos_test`. |
| `mocks.mjs` | iPaymu sandbox API on `:8020` (create payment, check transaction, payment page that sends the notify webhook) + SMTP sink on `:1025` (`GET :8020/mails`). |
| `journey.mjs` | 16 steps at 390 px: register → onboarding (username, template, Drive product) → file product → SSR page + CSP → buyer checkout without login → sandbox payment → webhook → access email → access page / download → balance → email verification → KYC → admin approves → withdraw Rp50.000 → admin marks paid → seller sees it. |
| `ui-audit.mjs` | 360 px audit of 32 public + dashboard pages: horizontal scroll, tap targets < 44 px, form fonts < 16 px. Run after `journey.mjs`, before cleanup. |
| `dark-audit.mjs [light]` | Dashboard dark (or light) mode: text contrast < 4.5:1 on 20 views, screenshots, public pages must stay light. |
| `pos-cashier-seed.php` + `pos-cashier.mjs` | POS cashier permissions: default menu (Orders + Members), direct URL redirect, owner grants/revokes and the menu follows without re-login, orders always work, owner sees the switches. Only Laravel :8010 + Vite :3010 needed. |
| `admin-smoke-seed.php` + `admin-smoke.mjs` | Super admin: opens all 19 admin pages + main read actions at 1366 px (and the overview at 820 px), fails on console errors, failed API calls (the expected 503 of `/api/health` without a scheduler is ignored) or pages stuck loading. Needs Laravel :8010 + Vite :3010 (+ realtime :3011 with the same `REALTIME_SERVER_SECRET`); `admin-smoke-seed.php cleanup` afterwards. Expect `23/23 checks OK`. |
| `builder-seed.php` + `builder-images.mjs` | Hellom Page editor images: a ~4.7 MB phone photo uploads (WebP), draft → publish keeps the URL, the server-rendered page and the dev server (Vite proxies `/media`) answer with an image, and the editor preview shows it at 1366 px and 360 px. Needs Laravel :8010 + Vite :3010; `builder-seed.php cleanup` afterwards. Expect `6/6 checks OK`. |
| `builder-onboarding.mjs` | Hellom Page editor onboarding (uses `builder-seed.php`, reseeds per viewport): "Sebelumnya pakai apa?" first, the shop wizard after it, Linktree wording in the editor, the tour finishes and is remembered, the block gallery starts with links — at 1366 px and 360 px. Expect `8/8 checks OK`; `builder-seed.php cleanup` afterwards. |
| `builder-editor.mjs` | Link-in-bio editor: illustrated "+ Tambah" gallery, live server-rendered phone preview (sandboxed iframe), tap-to-select, hide / duplicate / delete, keyboard reorder, undo / redo, autosave, publish (1366 px) and the phone layout with bottom sheets (360 px). Reseeds `builder-seed.php` and cleans up. Expect `16/16 checks OK`. Shared helpers: `cdp.mjs` (Chrome over CDP; reaches the out-of-process preview iframe). |
| `builder-blocks.mjs` | Fase 3 blocks: gallery cards, WhatsApp link, Embed (Spotify recognised, iframe in preview, short link explained), TikTok in Video, Spasi, "Produk fisik" picker lists only physical products. Laravel :8010 + Vite :3010. Expect `6/6 checks OK`. |
| `shipping-seed.php` + `builder-shipping.mjs` | Courier rates: seller sets the ship-from place, buyer at 360 px searches a sub-district, picks JNE REG, pays (iPaymu mock) → paid order with courier, ongkir and the fee on the product only. Needs `mocks.mjs` and Laravel with `IPAYMU_SANDBOX_URL=http://127.0.0.1:8020 RAJAONGKIR_SANDBOX_URL=http://127.0.0.1:8020/rajaongkir/api/v1`. Seeds and cleans up itself. Expect `6/6 checks OK`. |
| `builder-social.mjs` | Fase 4 Sosial media panel: import from the old block, live check (✓ / explanation), top position + brand colours in the preview, tap the icon row → panel, published page, phone bottom sheet. Laravel :8010 + Vite :3010. Expect `7/7 checks OK`. |
| `builder-appearance.mjs` | Fase 5 Tampilan: preset Neo-brutal (pola, tombol kotak + bayangan keras, font Archivo benar-benar termuat di pratinjau sandbox), background gradien & animasi, tombol global (pill/garis/besar), satu tombol dengan isi/ikon/unggulan sendiri, halaman terbit + preload font, sheet Tampilan di HP 360 px. Laravel :8010 + Vite :3010. Expect `8/8 checks OK`. |
| `builder-stats.mjs` | Fase 6: klik asli di halaman publik tercatat untuk link itu, Statistik › Klik per link (jumlah, %, sumber) di 1366 & 360 px, kartu og:image 1200×630 di Halaman › Atur. Laravel :8010 + Vite :3010. Expect `6/6 checks OK`. |
| `social-parity.mjs` | Editor (`socialPlatforms.ts`) vs server (`SocialLinks::url`) give the same link for 828 inputs; only needs PHP + Node 22.18+. Expect `828/828 cases agree`. |
| `captcha.mjs` | Turnstile on repeated checkouts in the browser. Start the :8010 server with `CACHE_STORE=database` and Cloudflare's test keys `TURNSTILE_SITE_KEY=1x00000000000000000000AA TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA` (always pass; calls challenges.cloudflare.com). |

Screenshots and results go to `backend/storage/app/e2e_shots/`, `e2e_full_result.json`, `e2e_ui_audit.json` (git-ignored).

## Run (from `backend/`, Git Bash)

```bash
rm -f bootstrap/cache/config.php
DB_DATABASE=hellom_pos_test php artisan migrate
DB_DATABASE=hellom_pos_test php tests/e2e/seed.php

# three terminals
node tests/e2e/mocks.mjs
DB_DATABASE=hellom_pos_test APP_URL=http://127.0.0.1:8010 FRONTEND_URL=http://127.0.0.1:3010 \
  CORS_ALLOWED_ORIGINS=http://127.0.0.1:3010 IPAYMU_SANDBOX_URL=http://127.0.0.1:8020 \
  MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 MAIL_SCHEME=smtp MAIL_USERNAME= MAIL_PASSWORD= \
  QUEUE_CONNECTION=sync CACHE_STORE=array PHP_CLI_SERVER_WORKERS=4 \
  php artisan serve --host=127.0.0.1 --port=8010
(cd ../frontend && VITE_HELLOM_API_BASE=http://127.0.0.1:8010/api/v1/hellom npx vite --host 127.0.0.1 --port 3010 --strictPort)

node tests/e2e/journey.mjs        # expect "16/16 steps OK"
node tests/e2e/ui-audit.mjs       # expect no OVERFLOW, small:0, font:0
DB_DATABASE=hellom_pos_test php artisan balance:reconcile
node tests/e2e/dark-audit.mjs && node tests/e2e/dark-audit.mjs light   # expect 0 low-contrast
DB_DATABASE=hellom_pos_test php tests/e2e/seed.php cleanup
DB_DATABASE=hellom_pos_test CACHE_STORE=database php artisan cache:clear  # after captcha.mjs
```

`CACHE_STORE=array` keeps the test server's page cache out of the dev cache. Ports 8000/3000
(the normal dev servers) are not used. Set `CHROME_PATH` if Chrome is elsewhere.

In production the shop page and the SPA share one domain; here the SSR page is on :8010 and the SPA
on :3010, so the journey follows "Beli" links by path on :3010.
