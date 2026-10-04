// Sewa / booking jadwal end to end: the seller creates a rental product (per day, 1 unit) in the
// product form; a buyer on a 360 px phone picks dates on the calendar, sees the price × days, pays
// (iPaymu mock) → the booking is locked; a second buyer sees those dates crossed out; the seller
// sees it in Jadwal and Pesanan. Needs mocks.mjs, Laravel :8010 with IPAYMU_SANDBOX_URL, Vite
// :3010 (README). Runs seed.php + builder-seed.php itself. Expect "7/7 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
const php = (...args) => execFileSync('php', args, { env }).toString();
php('tests/e2e/seed.php');
php('tests/e2e/builder-seed.php');
const seller = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const auth = { Authorization: `Bearer ${seller.token}`, Accept: 'application/json', 'Content-Type': 'application/json' };
await fetch(`${API}/apps/landing-builder/editor-preference`, { method: 'PUT', headers: auth, body: JSON.stringify({ preference: 'lynk', tour_done: true }) });

const browser = await openChrome(9367, 'cdp-builder-rental');
const { send, ev, waitFor, shot, errors } = browser;
const fill = (selector, value) => ev(`(() => {
  const el = document.querySelector(${JSON.stringify(selector)});
  if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : el.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)});
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
  return true;
})()`);
const clickText = (text) => ev(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim().includes(${JSON.stringify(text)})); b?.click(); return !!b; })()`);
const login = async () => {
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(seller.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(seller.user))}); true`);
};

let exitCode = 1;
try {
  // ── Seller: product form ──
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await login();
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=produk' });
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.includes('Tambah produk'))`, 20000);
  await clickText('Tambah produk');
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.includes('Sewa / booking jadwal'))`);
  await clickText('Sewa / booking jadwal');
  await waitFor(`!!document.querySelector('[data-rental-settings]')`);
  await fill('input[placeholder^="Contoh: E-book"]', 'Sewa Kamera Sony A7 III');
  await fill('input[placeholder="49.000"]', '150000');
  await shot('rental-form');
  await clickText('Simpan produk');
  const products = await (async () => { for (let i = 0; i < 40; i++) { const r = await (await fetch(`${API}/apps/landing-builder/products`, { headers: auth })).json(); const items = r.data?.items ?? r.data ?? []; const p = items.find?.((x) => x.type === 'rental'); if (p) return p; await sleep(300); } return null; })();
  check('seller: rental product created in the form (per day, 1 unit)', products?.booking_settings?.mode === 'daily' && products.booking_settings.units === 1 && products.stock === null, JSON.stringify(products?.booking_settings ?? products));
  const productId = products.id;

  // ── Buyer on a phone ──
  await ev(`localStorage.clear(); true`);
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: `${APP}/beli/${productId}` });
  await waitFor(`!!document.querySelector('[data-booking-picker] button[data-date]:not([disabled])')`, 20000);
  // Third free day from today (stays within this or next month's view).
  const start = await ev(`(() => { const b = [...document.querySelectorAll('[data-booking-picker] button[data-date]:not([disabled])')][2]; b.click(); return b.getAttribute('data-date'); })()`);
  await waitFor(`!!document.querySelector('[data-booking-picker] select')`);
  await fill('[data-booking-picker] select', '2');
  const priced = await waitFor(`!!document.querySelector('[data-booking-summary]') && document.body.textContent.includes('× 2 hari') && document.body.textContent.includes('Rp 300.000')`, 10000);
  await fill('input[autocomplete="name"]', 'Sari Penyewa');
  await fill('input[autocomplete="email"]', 'sari.sewa@example.test');
  await fill('input[type="tel"]', '081234567890');
  const noScroll = await ev(`document.documentElement.scrollWidth <= innerWidth + 1`);
  await ev(`document.querySelector('[data-booking-picker]').scrollIntoView({ block: 'start' }); true`);
  await sleep(300);
  await shot('rental-buyer-calendar');
  check('buyer 360 px: calendar → 2 days → price 2 × Rp 150.000 = Rp 300.000 with the dates', priced && noScroll && !!start, JSON.stringify({ start, priced, noScroll }));

  await clickText('Bayar sekarang');
  const pending = await waitFor(`document.body.textContent.includes('No. pesanan')`, 20000);
  const reference = await ev(`(document.body.textContent.match(/lps_[A-Z0-9]+/) || [null])[0]`);
  await fetch(`http://127.0.0.1:8020/pay-ref/${reference}`, { method: 'POST' });
  const paid = await waitFor(`document.body.textContent.includes('berhasil')`, 30000);
  const booking = JSON.parse(php('-r', `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $o = App\\Models\\LandingPageOrder::query()->where('reference_id', '${reference}')->first(); echo json_encode(['status' => $o?->status, 'amount' => $o?->amount, 'booking' => App\\Models\\LandingBooking::query()->where('order_id', $o?->id)->value('status')]);`));
  check('pay (QRIS mock) → order paid Rp 300.000 and booking locked', pending && paid && booking.status === 'paid' && booking.amount === 300000 && booking.booking === 'confirmed', JSON.stringify(booking));

  // ── Second buyer: those dates are crossed out ──
  await send('Page.navigate', { url: `${APP}/beli/${productId}` });
  await waitFor(`!!document.querySelector('[data-booking-picker] button[data-date]')`, 20000);
  await sleep(1200);
  const second = new Date(`${start}T00:00:00`); second.setDate(second.getDate() + 1);
  const secondDay = `${second.getFullYear()}-${String(second.getMonth() + 1).padStart(2, '0')}-${String(second.getDate()).padStart(2, '0')}`;
  const taken = await ev(`(() => { const a = document.querySelector('[data-date="${start}"]'); const b = document.querySelector('[data-date="${secondDay}"]'); return (!a || a.disabled) && (!b || b.disabled) && !!(a || b); })()`);
  await shot('rental-buyer-taken');
  check('second buyer: the booked dates are crossed out (no double booking)', taken, JSON.stringify({ start, secondDay }));

  // ── Per-session product (studio): date → start time chip → 2 sessions ──
  const hours = Object.fromEntries(['1', '2', '3', '4', '5', '6', '7'].map((d) => [d, ['10:00', '14:00']]));
  const studio = (await (await fetch(`${API}/apps/landing-builder/products`, { method: 'POST', headers: auth, body: JSON.stringify({
    type: 'rental', name: 'Sewa Studio Foto', price: 100000, booking: { mode: 'slot', units: 1, slot_minutes: 60, max_slots: 3, lead_hours: 0, hours } }) })).json()).data;
  await send('Page.navigate', { url: `${APP}/beli/${studio.id}` });
  await waitFor(`!!document.querySelector('[data-booking-picker] button[data-date]:not([disabled])')`, 20000);
  await ev(`[...document.querySelectorAll('[data-booking-picker] button[data-date]:not([disabled])')][2].click(); true`);
  await waitFor(`!!document.querySelector('[data-slot="11:00"]:not([disabled])')`, 15000);
  await ev(`document.querySelector('[data-slot="11:00"]').click(); true`);
  await waitFor(`!!document.querySelector('[data-booking-picker] select')`);
  await fill('[data-booking-picker] select', '2');
  const sessions = await waitFor(`!!document.querySelector('[data-booking-summary]') && document.querySelector('[data-booking-summary]').textContent.includes('11.00–13.00 WIB (2 sesi)') && document.body.textContent.includes('Rp 200.000')`, 10000);
  await ev(`document.querySelector('[data-booking-picker]').scrollIntoView({ block: 'start' }); true`);
  await sleep(300);
  await shot('rental-buyer-sessions');
  check('per session (studio): date → 11.00 → 2 sessions = Rp 200.000', sessions);

  // ── Seller: Jadwal + Pesanan ──
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await login();
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=jadwal' });
  const schedule = await waitFor(`!!document.querySelector('[data-booking]') && document.querySelector('[data-booking]').textContent.includes('(2 hari)') && document.querySelector('[data-booking]').textContent.includes('Sari Penyewa') && document.querySelector('[data-booking]').textContent.includes('Lunas')`, 20000);
  await shot('rental-seller-schedule');
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=pesanan' });
  const inOrders = await waitFor(`!!document.querySelector('[data-order-booking]') && document.querySelector('[data-order-booking]').textContent.includes('(2 hari)')`, 20000);
  check('seller: booking in Jadwal (Lunas, buyer + WhatsApp) and dates in Pesanan', schedule && inOrders, JSON.stringify({ schedule, inOrders }));
  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  php('tests/e2e/builder-seed.php', 'cleanup');
  php('tests/e2e/seed.php', 'cleanup');
  exitCode = finish();
}
process.exit(exitCode);
