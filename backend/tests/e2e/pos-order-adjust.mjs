// POS anti-fraud phase 2/3 in the browser (cashier): "Atur pesanan" — reduce an item (reason),
// remove an item after a printed receipt (owner PIN), manual discount (PIN), split bill, move an
// order to another table, move/merge a table bill, change a paid order's payment method (PIN),
// open the drawer without a sale; then the owner sees every action in Log Audit. No browser pop-ups.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test. Runs pos-cashier-seed.php. Expect "10/10 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const PIN = '583920';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/pos-cashier-seed.php'], { env });
const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_pos_cashier.json', import.meta.url), 'utf8'));
const call = async (token, method, path, body) => {
  const res = await fetch(`${API}${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Outlet-Id': String(seed.outlet_id) }, body: body ? JSON.stringify(body) : undefined });
  return { status: res.status, json: await res.json().catch(() => null) };
};
const ownerMe = (await call(seed.owner_token, 'GET', '/auth/me')).json.data;
const staffLogin = await (await fetch(`${API}/auth/staff-login`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: seed.cashier_email, password: seed.cashier_password }) })).json();
const cashierToken = staffLogin.data.token;

// Setup through the API: owner PIN, two tables, a second menu, an order on table A1.
await call(seed.owner_token, 'POST', '/pos/owner-pin', { pin: PIN, pin_confirmation: PIN });
const t1 = (await call(seed.owner_token, 'POST', '/pos/tables', { code: 'A1', name: 'Meja A1' })).json.data.table;
const t2 = (await call(seed.owner_token, 'POST', '/pos/tables', { code: 'A2', name: 'Meja A2' })).json.data.table;
const nasi = (await call(seed.owner_token, 'GET', '/pos/products')).json.data.products[0];
const teh = (await call(seed.owner_token, 'POST', '/pos/products', { category_id: nasi.category_id, name: 'Es Teh', price: 5000, is_available: true })).json.data.product;
const order = (await call(cashierToken, 'POST', '/pos/orders', { table_id: t1.id, items: [{ product_id: nasi.id, quantity: 3 }, { product_id: teh.id, quantity: 1 }] })).json.data.order;
const getOrder = async (id) => (await call(seed.owner_token, 'GET', '/pos/orders')).json.data.orders.find((o) => o.id === id);

const browser = await openChrome(9370, 'cdp-pos-adjust');
const { send, ev, waitFor, shot, errors } = browser;
const fill = (selector, value) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : el.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); return true; })()`);
const click = (selector) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`);
const clickText = (selector, text) => ev(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})].find((b) => b.textContent.includes(${JSON.stringify(text)})); if (!el) return false; el.click(); return true; })()`);
const toast = (text) => waitFor(`document.querySelector('[data-toast]')?.textContent.includes(${JSON.stringify(text)})`, 10000);
const signIn = async (token, user, path) => {
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(user))}); localStorage.setItem('hellom_active_outlet_id', ${JSON.stringify(String(seed.outlet_id))}); true`);
  await send('Page.navigate', { url: APP + path });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`window.__popups = []; window.alert = (m) => window.__popups.push(String(m)); window.confirm = (m) => { window.__popups.push(String(m)); return true; }; window.prompt = (m) => { window.__popups.push(String(m)); return null; }; true`);
};
const openManage = async (id, tab) => {
  await waitFor(`!!document.querySelector('[data-manage-order="${id}"]')`, 15000);
  await click(`[data-manage-order="${id}"]`);
  await waitFor(`!!document.querySelector('[data-order-manage]')`, 5000);
  if (tab) await clickText('[data-order-manage] [role="tab"]', tab);
};

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await signIn(cashierToken, staffLogin.data.user, '/pos/orders');

  // 1. Reduce Nasi 3 → 2 before any print: reason, no PIN.
  await openManage(order.id);
  await click(`[aria-label="Kurangi ${nasi.name}"]`);
  const reasonShown = await waitFor(`!!document.querySelector('[data-items-form] input[name="reason"]') && !document.querySelector('[data-items-form] input[name="approval_pin"]')`, 3000);
  await fill('[data-items-form] input[name="reason"]', 'Salah input');
  await click('[data-items-form] button[type="submit"]');
  const reduced = await toast('Item pesanan diperbarui');
  check('reduce an item: reason asked, no PIN before printing, server reprices', reasonShown && reduced && (await getOrder(order.id))?.items.find((i) => i.product_name === nasi.name)?.quantity === 2);

  // 2. After a printed receipt, removing Es Teh needs the owner PIN.
  await call(cashierToken, 'POST', `/pos/orders/${order.id}/receipt/printed`);
  await ev(`window.dispatchEvent(new CustomEvent('pos-orders-updated')); true`);
  await sleep(1500);
  await openManage(order.id);
  await click('[aria-label="Kurangi Es Teh"]');
  const pinShown = await waitFor(`!!document.querySelector('[data-items-form] input[name="approval_pin"]')`, 3000);
  await fill('[data-items-form] input[name="reason"]', 'Pelanggan tidak jadi');
  await fill('[data-items-form] input[name="approval_pin"]', '000999');
  await click('[data-items-form] button[type="submit"]');
  const wrong = await waitFor(`document.querySelector('[data-items-form]')?.textContent.includes('PIN salah')`, 8000);
  await fill('[data-items-form] input[name="approval_pin"]', PIN);
  await click('[data-items-form] button[type="submit"]');
  const removed = await toast('Item pesanan diperbarui');
  await shot('pos-adjust-items');
  check('remove an item after a printed receipt needs the owner PIN (wrong PIN explained)', pinShown && wrong && removed && (await getOrder(order.id))?.items.length === 1);

  // 3. Manual discount 10% with PIN.
  await openManage(order.id, 'Diskon');
  await fill('[data-discount-form] input[name="value"]', '10');
  await fill('[data-discount-form] input[name="reason"]', 'Pelanggan langganan');
  await fill('[data-discount-form] input[name="approval_pin"]', PIN);
  await click('[data-discount-form] button[type="submit"]');
  const discounted = await toast('diterapkan');
  const afterDiscount = await getOrder(order.id);
  await shot('pos-adjust-discount');
  check('manual discount with the owner PIN, shown on the order card', discounted && afterDiscount?.manual_discount_amount === 5000
    && await waitFor(`document.body.textContent.includes('Diskon kasir')`, 5000), JSON.stringify({ manual: afterDiscount?.manual_discount_amount, final: afterDiscount?.final_amount }));

  // 4. Split one Nasi to a new bill.
  await openManage(order.id, 'Pisah bill');
  await click(`[aria-label="Pisahkan ${nasi.name}"]`);
  await click('[data-split-form] button[type="submit"]');
  const split = await toast('Bill dipisah');
  const all = (await call(seed.owner_token, 'GET', '/pos/orders')).json.data.orders;
  const child = all.find((o) => o.split_from_order_id === order.id);
  check('split bill: one item moves to a new order on the same table bill', split && !!child && child.table_bill_id === order.table_bill_id);

  // 5. Move the original order to A2.
  await openManage(order.id, 'Pindah meja');
  await waitFor(`document.querySelectorAll('[data-transfer-form] select option').length > 1`, 5000);
  await fill('[data-transfer-form] select[name="table_id"]', String(t2.id));
  await click('[data-transfer-form] button[type="submit"]');
  const moved = await toast('dipindah ke');
  check('move one order to another table', moved && (await getOrder(order.id))?.table_label === 'Meja A2');

  // 6. Table bills: A1 (the split-off order) merges into A2's open bill.
  await clickText('button', 'Tagihan Meja');
  await waitFor(`!!document.querySelector('[data-move-bill]')`, 10000);
  const a1Bill = (await call(cashierToken, 'GET', '/pos/table-bills')).json.data.bills.find((b) => b.dining_table_id === t1.id);
  await click(`[data-move-bill="${a1Bill?.id}"]`);
  await waitFor(`document.querySelectorAll('[data-move-bill-form] select option').length > 1`, 5000);
  await fill('[data-move-bill-form] select[name="table_id"]', String(t2.id));
  await click('[data-move-bill-form] button[type="submit"]');
  const merged = await toast('digabung');
  await shot('pos-adjust-merge');
  const a2Bill = (await call(cashierToken, 'GET', '/pos/table-bills')).json.data.bills.find((b) => b.dining_table_id === t2.id);
  check('move/merge a table bill into the other table', merged && a2Bill?.orders_count === 2, JSON.stringify({ merged, count: a2Bill?.orders_count }));

  // 7. Paid order: payment method cash → QRIS with the PIN.
  await call(cashierToken, 'POST', `/pos/orders/${child.id}/payment`, { payment_method: 'cash', payment_amount: 100000 });
  await ev(`window.dispatchEvent(new CustomEvent('pos-orders-updated')); true`);
  await waitFor(`!!document.querySelector('[data-change-method="${child.id}"]')`, 10000);
  await click(`[data-change-method="${child.id}"]`);
  await waitFor(`!!document.querySelector('[data-payment-method-form]')`, 5000);
  await fill('[data-payment-method-form] select[name="payment_method"]', 'qris');
  await fill('[data-payment-method-form] input[name="reason"]', 'Ternyata bayar QRIS');
  await fill('[data-payment-method-form] input[name="approval_pin"]', PIN);
  await click('[data-payment-method-form] button[type="submit"]');
  const methodChanged = await toast('diganti ke QRIS');
  check('change a paid order\'s payment method with the owner PIN', methodChanged && (await getOrder(child.id))?.payment_method === 'qris');

  // 8. Drawer opened without a sale.
  await call(cashierToken, 'POST', `/pos/staff/${seed.staff_id}/cash/open`, { opening_cash: 100000 });
  await ev(`window.dispatchEvent(new CustomEvent('pos-orders-updated')); true`);
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.trim() === 'Kas terbuka')`, 10000);
  await clickText('button', 'Kas terbuka');
  await waitFor(`!!document.querySelector('[data-no-sale]')`, 5000);
  await clickText('[data-no-sale] button', 'Buka laci tanpa transaksi');
  await clickText('[data-no-sale] button', 'Tukar uang kecil');
  const noSale = await waitFor(`document.querySelector('[data-no-sale-note]')?.textContent.includes('1× hari ini')`, 8000);
  await shot('pos-adjust-nosale');
  check('drawer opened without a sale is recorded with a reason', noSale);
  check('no browser pop-ups at the till', (await ev(`(window.__popups || []).length`)) === 0);

  // 9. Owner sees all of it in Log Audit.
  await signIn(seed.owner_token, ownerMe.user ?? ownerMe, '/pos/audit');
  await waitFor(`!!document.querySelector('[data-audit-unlock]')`, 15000);
  await fill('[data-audit-unlock] input[name="pin"]', PIN);
  await click('[data-audit-unlock] button[type="submit"]');
  await waitFor(`!!document.querySelector('[data-audit-row="DRAWER_NO_SALE"]')`, 15000);
  const kinds = await ev(`[...document.querySelectorAll('[data-audit-row]')].map((r) => r.getAttribute('data-audit-row'))`);
  await shot('pos-adjust-log');
  check('owner sees every change in Log Audit', ['ITEM_QTY_CHANGED', 'ITEM_REMOVED', 'DISCOUNT_APPLIED', 'ORDER_SPLIT', 'TABLE_TRANSFERRED', 'BILL_MERGED', 'PAYMENT_METHOD_CHANGED', 'DRAWER_NO_SALE'].every((k) => kinds?.includes(k)), JSON.stringify(kinds));

  if (errors.length) console.log('script errors:', JSON.stringify(errors));
} finally {
  browser.close();
  exitCode = finish();
}
process.exit(exitCode);
