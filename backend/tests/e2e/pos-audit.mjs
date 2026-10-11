// POS anti-fraud trail in the browser: the owner must create a PIN on the first POS visit; a cashier
// voids an order only with the owner's PIN (styled dialog, no browser prompt); blind cash closing
// (no expected cash shown); the owner unlocks POS › Log Audit with the PIN and sees the void with
// approver + before/after, fraud alerts, and an intact hash chain; the emailed reset link sets a
// new PIN. Needs Laravel :8010 + Vite :3010 on hellom_pos_test (MAIL array, no SMTP in that DB).
// Runs pos-cashier-seed.php itself. Expect "8/8 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/pos-cashier-seed.php'], { env });
const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_pos_cashier.json', import.meta.url), 'utf8'));
const call = async (token, method, path, body) => {
  const res = await fetch(`${API}${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Outlet-Id': String(seed.outlet_id) }, body: body ? JSON.stringify(body) : undefined });
  return { status: res.status, json: await res.json().catch(() => null) };
};
const me = async (token) => (await call(token, 'GET', '/auth/me')).json.data;
const ownerMe = await me(seed.owner_token);
// The cashier's own account points at her side business; the staff login moves her into the shop.
const staffLogin = await (await fetch(`${API}/auth/staff-login`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: seed.cashier_email, password: seed.cashier_password }) })).json();
const cashierToken = staffLogin.data.token;
const cashierMe = { user: staffLogin.data.user };

const browser = await openChrome(9369, 'cdp-pos-audit');
const { send, ev, waitFor, shot, errors } = browser;
const fill = (selector, value) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
const click = (selector) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`);
const clickText = (selector, text) => ev(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})].find((b) => b.textContent.includes(${JSON.stringify(text)})); if (!el) return false; el.click(); return true; })()`);
const signIn = async (token, user, path) => {
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(user.user ?? user))}); localStorage.setItem('hellom_active_outlet_id', ${JSON.stringify(String(seed.outlet_id))}); true`);
  await send('Page.navigate', { url: APP + path });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`window.__popups = []; window.alert = (m) => window.__popups.push(String(m)); window.confirm = (m) => { window.__popups.push(String(m)); return true; }; window.prompt = (m) => { window.__popups.push(String(m)); return null; }; true`);
};
const popups = () => ev(`(window.__popups || []).length`);

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });

  // 1. Owner's first POS visit: must create a PIN (weak one refused), then "Log Audit" is in the menu.
  await signIn(seed.owner_token, ownerMe, '/pos/orders');
  const asked = await waitFor(`!!document.querySelector('[data-pin-setup]')`, 20000);
  await fill('[data-pin-setup] input[name="pin"]', '123456');
  await fill('[data-pin-setup] input[name="pin_confirmation"]', '123456');
  await click('[data-pin-setup] button[type="submit"]');
  const weak = await waitFor(`document.querySelector('[data-pin-setup]')?.textContent.includes('terlalu mudah')`, 8000);
  await shot('pos-audit-setup');
  await fill('[data-pin-setup] input[name="pin"]', '583920');
  await fill('[data-pin-setup] input[name="pin_confirmation"]', '583920');
  await click('[data-pin-setup] button[type="submit"]');
  const saved = await waitFor(`!document.querySelector('[data-pin-setup]') && !!document.querySelector('a[href="/pos/audit"]')`, 10000);
  check('owner must create a PIN on first visit (weak PIN refused), then sees Log Audit', asked && weak && saved);

  // 2. Cashier voids an unpaid order: reason + owner PIN in a dialog; wrong PIN explained.
  const products = (await call(seed.owner_token, 'GET', '/pos/products')).json.data.products;
  const order = (await call(cashierToken, 'POST', '/pos/orders', { items: [{ product_id: products[0].id, quantity: 1 }] })).json?.data?.order;
  await call(cashierToken, 'POST', `/pos/staff/${seed.staff_id}/cash/open`, { opening_cash: 100000 });
  await signIn(cashierToken, cashierMe, '/pos/orders');
  await waitFor(`!!document.querySelector('[data-cancel-order="${order?.id}"]')`, 20000);
  await click(`[data-cancel-order="${order?.id}"]`);
  const dialog = await waitFor(`!!document.querySelector('[data-approval-dialog]')`, 5000);
  await fill('[data-approval-dialog] textarea[name="reason"]', 'Pelanggan batal pesan');
  await fill('[data-approval-dialog] input[name="approval_pin"]', '000999');
  await click('[data-approval-dialog] button[type="submit"]');
  const wrong = await waitFor(`document.querySelector('[data-approval-dialog]')?.textContent.includes('PIN salah')`, 8000);
  await shot('pos-audit-void-wrong');
  await fill('[data-approval-dialog] input[name="approval_pin"]', '583920');
  await click('[data-approval-dialog] button[type="submit"]');
  const closedDialog = await waitFor(`!document.querySelector('[data-approval-dialog]')`, 10000);
  const orders = (await call(seed.owner_token, 'GET', '/pos/orders')).json?.data;
  const list = orders?.orders ?? orders?.items ?? orders ?? [];
  const voided = (Array.isArray(list) ? list : []).find((o) => o.id === order?.id);
  check('cashier void needs the owner PIN (dialog, wrong PIN explained, no browser prompt)', !!order && dialog && wrong && closedDialog && voided?.status === 'cancelled' && (await popups()) === 0,
    JSON.stringify({ order: order?.id, dialog, wrong, closedDialog, status: voided?.status, popups: await popups() }));

  // 3. Blind cash closing: no expected amount on the button or in the dialog.
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.trim() === 'Kas terbuka')`, 10000);
  await clickText('button', 'Kas terbuka');
  await waitFor(`!!document.querySelector('[role="dialog"] input[inputmode="numeric"]')`, 5000);
  const hidden = await ev(`!document.querySelector('[role="dialog"]').textContent.includes('Seharusnya')`);
  await fill('[role="dialog"] input[inputmode="numeric"]', '90000');
  await clickText('[role="dialog"] button', 'Tutup kas');
  const confirmStep = await waitFor(`!!document.querySelector('[data-cash-confirm]')`, 5000);
  await clickText('[role="dialog"] button', 'Ya, tutup kas');
  const done = await waitFor(`!!document.querySelector('[data-cash-closed]') && !document.querySelector('[role="dialog"]').textContent.includes('Selisih')`, 10000);
  await shot('pos-audit-cash');
  check('blind cash close: cashier never sees expected cash or difference, confirm step in the dialog', hidden && confirmStep && done && (await popups()) === 0);

  // 4. Cashier cannot open the audit page.
  await send('Page.navigate', { url: APP + '/pos/audit' });
  await sleep(1500);
  check('cashier is kept out of Log Audit', !(await ev(`location.pathname === '/pos/audit' && !!document.querySelector('[data-audit-unlock], [data-audit-unlocked]')`)));

  // 5. Owner unlocks the log with the PIN and sees the void with approver and before/after.
  await signIn(seed.owner_token, ownerMe, '/pos/audit');
  await waitFor(`!!document.querySelector('[data-audit-unlock]')`, 15000);
  await fill('[data-audit-unlock] input[name="pin"]', '111333');
  await click('[data-audit-unlock] button[type="submit"]');
  const lockedOut = await waitFor(`document.querySelector('[data-audit-unlock]')?.textContent.includes('PIN salah')`, 8000);
  await fill('[data-audit-unlock] input[name="pin"]', '583920');
  await click('[data-audit-unlock] button[type="submit"]');
  await waitFor(`!!document.querySelector('[data-audit-row="ORDER_VOID"]')`, 15000);
  await click('[data-audit-row="ORDER_VOID"] button');
  const detail = await waitFor(`(() => { const r = document.querySelector('[data-audit-row="ORDER_VOID"]'); return r && r.textContent.includes('Pemilik Resto') && r.textContent.includes('Pelanggan batal pesan') && r.textContent.includes('Dibatalkan') && r.textContent.includes('Kasir Sinta'); })()`, 5000);
  const kinds = await ev(`[...document.querySelectorAll('[data-audit-row]')].map((r) => r.getAttribute('data-audit-row'))`);
  await shot('pos-audit-log');
  check('owner opens Log Audit with the PIN: void row shows cashier, approver, reason, before/after', lockedOut && detail
    && ['ORDER_VOID', 'CASH_CLOSED', 'CASH_OPENED', 'PIN_SET', 'PIN_FAILED'].every((k) => kinds?.includes(k)), JSON.stringify(kinds));

  // 6. Fraud alerts: the cashier's cash shortage is flagged.
  await clickText('[role="tab"]', 'Peringatan');
  const alerts = await waitFor(`!!document.querySelector('[data-alert-cashier="Kasir Sinta"]') && document.querySelector('[data-alert-cashier="Kasir Sinta"]').textContent.includes('Kas kurang')`, 10000);
  await shot('pos-audit-alerts');
  check('fraud alerts flag the cashier with a cash shortage', alerts);

  // 7. Integrity check.
  await clickText('[role="tab"]', 'PIN & keamanan');
  await clickText('button', 'Periksa sekarang');
  check('hash chain is intact', await waitFor(`document.querySelector('[data-integrity]')?.getAttribute('data-integrity') === 'ok'`, 10000));

  // 8. Reset link from the email sets a new PIN (token created here; the email itself is not sent in tests).
  const token = 'e2e' + Math.random().toString(36).slice(2).padEnd(40, 'x');
  execFileSync('php', ['artisan', 'tinker', `--execute=App\\Models\\PosOwnerPinReset::query()->create(['organization_id' => App\\Models\\Organization::query()->where('slug', 'resto-e2e-kasir')->value('id'), 'owner_user_id' => ${ownerMe.user?.id ?? ownerMe.id}, 'token_hash' => hash('sha256', '${token}'), 'expires_at' => now()->addMinutes(30)]);`], { env, input: '' });
  await send('Page.navigate', { url: `${APP}/reset-pin-pos?token=${token}` });
  await waitFor(`!!document.querySelector('[data-pin-reset]')`, 15000);
  await fill('[data-pin-reset] input[name="pin"]', '260417');
  await fill('[data-pin-reset] input[name="pin_confirmation"]', '260417');
  await click('[data-pin-reset] button[type="submit"]');
  const resetDone = await waitFor(`!!document.querySelector('[data-pin-reset-done]')`, 10000);
  const unlocked = (await call(seed.owner_token, 'POST', '/pos/owner-pin/unlock', { pin: '260417' })).status === 200;
  await shot('pos-audit-reset');
  check('emailed reset link sets a new PIN that works', resetDone && unlocked);

  if (errors.length) console.log('script errors:', JSON.stringify(errors));
} finally {
  browser.close();
  exitCode = finish();
}
process.exit(exitCode);
