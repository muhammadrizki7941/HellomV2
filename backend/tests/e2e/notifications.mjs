// Live notifications in the browser: super admin (/admin) and a shop owner (/dashboard) are open;
// the server rings both bells (notifications-seed.php fire) through the real realtime server →
// toast + badge + tab title without a reload, the dropdown groups, "Lihat semua" page with
// search / status filter, the seller item opens its page, the sound switch is remembered.
// Needs Laravel :8010 + Vite :3010 (VITE_REALTIME_PUBLIC_URL=http://127.0.0.1:3011) + realtime :3011,
// all with the same REALTIME_SERVER_SECRET (env of this script too). Expect "N/N checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = {
  ...process.env,
  DB_DATABASE: 'hellom_pos_test',
  REALTIME_ENABLED: 'true',
  REALTIME_SERVER_URL: process.env.REALTIME_SERVER_URL || 'http://127.0.0.1:3011',
};
if (!env.REALTIME_SERVER_SECRET) throw new Error('Set REALTIME_SERVER_SECRET (same as Laravel :8010 and realtime :3011)');
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/notifications-seed.php'], { env });
const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_notifications.json', import.meta.url), 'utf8'));
const fire = (n) => execFileSync('php', ['tests/e2e/notifications-seed.php', 'fire', String(n)], { env });
const me = async (token) => {
  const res = await fetch(`${API}/auth/me`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  const data = (await res.json()).data;
  return data.user ?? data;
};

async function login(browser, token, path) {
  const { send, ev, waitFor } = browser;
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  const user = await me(token);
  await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(user))}); true`);
  await send('Page.navigate', { url: APP + path });
  await waitFor(`document.readyState === 'complete'`);
}

// The visible bell button (the dashboard renders a mobile and a desktop one).
const BELL = `[...document.querySelectorAll('button[aria-label^="Notifikasi"]')].find((b) => b.offsetParent !== null)`;
const badge = (ev) => ev(`Number((${BELL})?.querySelector('span')?.textContent?.replace('+', '') || 0)`);
const clickBell = (ev) => ev(`(() => { const b = ${BELL}; if (!b) return false; b.click(); return true; })()`);
const live = `!!document.querySelector('[title="Langsung (realtime)"]')`;
const toastWith = (text) => `[...document.querySelectorAll('[aria-live="polite"] p')].some((p) => p.textContent.includes(${JSON.stringify(text)}))`;

const admin = await openChrome(9381, 'cdp-notif-admin');
const seller = await openChrome(9382, 'cdp-notif-seller');
let exitCode = 1;
try {
  await login(admin, seed.admin_token, '/admin');
  await login(seller, seed.owner_token, '/dashboard');

  // Both bells connect to the realtime server (green dot in the dropdown).
  await admin.waitFor(`!!(${BELL})`);
  await seller.waitFor(`!!(${BELL})`);
  await clickBell(admin.ev);
  await clickBell(seller.ev);
  // Generous: php artisan serve on Windows answers one request at a time (~20 dashboard calls first).
  const adminLive = await admin.waitFor(live, 40000);
  const sellerLive = await seller.waitFor(live, 40000);
  check('both bells are connected live (realtime)', adminLive && sellerLive, `admin=${adminLive} seller=${sellerLive}`);
  await clickBell(admin.ev);
  await clickBell(seller.ev);
  await sleep(500);

  const adminBefore = await badge(admin.ev);
  const sellerBefore = await badge(seller.ev);
  await admin.ev(`localStorage.removeItem('hellom.notificationSound'); true`);

  // Server event → toast + badge on both sides without reloading.
  const t0 = Date.now();
  fire(1);
  const adminToast = await admin.waitFor(toastWith('Dana masuk Hellom Page'), 8000);
  const sellerToast = await seller.waitFor(toastWith('Penjualan baru'), 8000);
  const ms = Date.now() - t0;
  check('toast on both sides within seconds', adminToast && sellerToast, `admin=${adminToast} seller=${sellerToast}`);
  const adminBadge = await admin.waitFor(`Number((${BELL})?.querySelector('span')?.textContent?.replace('+', '') || 0) >= ${Math.min(99, adminBefore + 2)}`, 8000);
  const sellerBadge = await seller.waitFor(`Number((${BELL})?.querySelector('span')?.textContent?.replace('+', '') || 0) === ${sellerBefore + 1}`, 8000);
  check('badges go up without reload (admin +2, seller +1)', adminBadge && sellerBadge, `admin ${adminBefore}→${await badge(admin.ev)} seller ${sellerBefore}→${await badge(seller.ev)}`);
  const titles = [await admin.ev('document.title'), await seller.ev('document.title')];
  check('unread count in the tab title', titles.every((t) => /^\(\d+\+?\) /.test(t)), JSON.stringify(titles));
  const sidebar = await admin.ev(`[...document.querySelectorAll('aside a, nav a')].find((a) => a.getAttribute('href') === '/admin/notifications')?.textContent ?? ''`);
  check('sidebar "Notifikasi" link with badge', /Notifikasi\s*\d+/.test(sidebar), sidebar);
  await admin.shot('notif-admin-toast');
  await seller.shot('notif-seller-toast');
  console.log(`     (event → toast ${ms} ms)`);

  // Admin dropdown: group "Penarikan & refund" lists the pending withdrawal with its action badge.
  await clickBell(admin.ev);
  await admin.waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.trim() === 'Penarikan & refund')`);
  await admin.ev(`[...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Penarikan & refund').click(); true`);
  const group = await admin.waitFor(`[...document.querySelectorAll('h4')].some((h) => h.textContent.includes('Penarikan dana diajukan')) && [...document.querySelectorAll('span')].some((s) => s.textContent === 'Tinjau penarikan') && ![...document.querySelectorAll('h4')].some((h) => h.textContent.includes('Dana masuk Hellom Page'))`, 8000);
  check('dropdown group filter: withdrawal + "Tinjau penarikan"', group);
  await admin.shot('notif-admin-dropdown');

  // "Lihat semua" → full page; server search + "Perlu tindakan" filter.
  await admin.ev(`[...document.querySelectorAll('button')].find((b) => b.textContent.includes('Lihat semua notifikasi')).click(); true`);
  await admin.waitFor(`location.pathname === '/admin/notifications' && !!document.querySelector('input[placeholder^="Cari"]')`);
  await admin.ev(`(() => { const el = document.querySelector('input[placeholder^="Cari"]'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, 'Toko Notif E2E'); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
  const searched = await admin.waitFor(`document.body.innerText.includes('2 notifikasi')`, 8000);
  await admin.ev(`(() => { const el = document.querySelector('select[aria-label="Filter status"]'); Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(el, 'pending'); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
  const pendingOnly = await admin.waitFor(`document.body.innerText.includes('1 notifikasi') && document.body.innerText.includes('Penarikan dana diajukan')`, 8000);
  check('"Lihat semua" page: search + "Perlu tindakan" filter', searched && pendingOnly, `search=${searched} pending=${pendingOnly}`);

  // A second event while the page is open → list reloads by itself.
  fire(2);
  const reloaded = await admin.waitFor(`document.body.innerText.includes('2 notifikasi')`, 8000);
  check('full page reloads on a new event', reloaded);
  await admin.shot('notif-admin-page');

  // Seller: the sale item opens the orders tab.
  await clickBell(seller.ev);
  await seller.waitFor(`[...document.querySelectorAll('h4')].some((h) => h.textContent.includes('Penjualan baru'))`);
  await seller.shot('notif-seller-dropdown');
  await seller.ev(`[...document.querySelectorAll('h4')].find((h) => h.textContent.includes('Penjualan baru')).closest('button').click(); true`);
  const opened = await seller.waitFor(`location.pathname === '/dashboard/apps/landing-builder' && location.search.includes('tab=pesanan')`, 8000);
  check('seller notification opens Hellom Page › Pesanan', opened, await seller.ev('location.href'));

  // Sound switch is remembered.
  await clickBell(seller.ev);
  await seller.waitFor(`!!document.querySelector('button[aria-label="Matikan bunyi notifikasi"]')`);
  await seller.ev(`document.querySelector('button[aria-label="Matikan bunyi notifikasi"]').click(); true`);
  const muted = await seller.ev(`localStorage.getItem('hellom.notificationSound')`);
  check('sound switch saved', muted === 'off', String(muted));

  check('no page errors', admin.errors.length === 0 && seller.errors.length === 0, JSON.stringify([...admin.errors, ...seller.errors]));
  exitCode = finish();
} catch (error) {
  console.error(error);
  finish();
} finally {
  admin.close();
  seller.close();
  execFileSync('php', ['tests/e2e/notifications-seed.php', 'cleanup'], { env });
}
process.exit(exitCode);
