// Physical product with real courier rates (RajaOngkir, mocked by mocks.mjs): the seller sets the
// ship-from place in Pengaturan, a buyer on a phone searches their sub-district, picks a courier,
// sees the total, pays (iPaymu mock) — the order carries the courier and the fee skips shipping.
// Needs mocks.mjs, Laravel :8010 with IPAYMU_SANDBOX_URL + RAJAONGKIR_SANDBOX_URL=http://127.0.0.1:8020/rajaongkir/api/v1,
// Vite :3010 (README). Runs seed.php, builder-seed.php and shipping-seed.php itself. Expect "6/6 checks OK".
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
php('tests/e2e/shipping-seed.php');
const seller = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const { product } = JSON.parse(readFileSync(new URL('../../storage/app/shipping_seed.json', import.meta.url), 'utf8'));
await fetch(`${API}/apps/landing-builder/editor-preference`, { method: 'PUT', headers: { Authorization: `Bearer ${seller.token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ preference: 'lynk', tour_done: true }) });

const browser = await openChrome(9356, 'cdp-builder-shipping');
const { send, ev, waitFor, shot, errors } = browser;
// React-controlled fields: set through the native setter, then fire "input".
const fill = (selector, value) => ev(`(() => {
  const el = document.querySelector(${JSON.stringify(selector)});
  if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)});
  el.dispatchEvent(new Event('input', { bubbles: true }));
  return true;
})()`);
const clickText = (text) => ev(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);

let exitCode = 1;
try {
  // ── Seller: ship-from place in Pengaturan › Pengiriman ──
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(seller.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(seller.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=pengaturan' });
  await waitFor(`document.body.textContent.includes('Dikirim dari (kecamatan)')`, 20000);
  await fill('section input[role="combobox"]', 'gambir');
  await waitFor(`!!document.querySelector('[role="listbox"] [role="option"] button')`);
  await ev(`document.querySelector('[role="listbox"] [role="option"] button').click(); true`);
  await clickText('Simpan pengiriman');
  const saved = await waitFor(`document.body.textContent.includes('Pengiriman disimpan')`);
  await shot('shipping-seller-settings');
  const setup = await (await fetch(`${API}/apps/landing-builder/shipping`, { headers: { Authorization: `Bearer ${seller.token}`, Accept: 'application/json' } })).json();
  check('seller: ship-from place + couriers saved', saved && setup.data?.origin?.id === '17473' && setup.data.couriers.length === 3, JSON.stringify(setup.data));

  // ── Buyer on a phone ──
  await ev(`localStorage.clear(); true`);
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: `${APP}/beli/${product}` });
  await waitFor(`document.body.textContent.includes('Alamat pengiriman')`, 20000);
  const noCityField = await ev(`!document.querySelector('input[autocomplete="shipping address-level2"]')`);
  await fill('input[autocomplete="name"]', 'Sari Pembeli');
  await fill('input[autocomplete="email"]', 'sari.kurir@example.test');
  await fill('input[type="tel"]', '081234567890');
  await fill('input[autocomplete="shipping name"]', 'Sari');
  await ev(`(() => { const t = [...document.querySelectorAll('input[type="tel"]')]; const el = t[t.length - 1]; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, '081234567890'); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
  await fill('textarea[autocomplete="shipping street-address"]', 'Jl. Kaliurang Km 9 No. 5, RT 2 RW 3');
  await fill('input[role="combobox"]', 'ngaglik');
  await waitFor(`!!document.querySelector('[role="listbox"] [role="option"] button')`);
  await ev(`document.querySelector('[role="listbox"] [role="option"] button').click(); true`);
  const rates = await waitFor(`document.querySelectorAll('input[name="courier"]').length === 3`);
  const cheapest = await ev(`(() => { const r = [...document.querySelectorAll('input[name="courier"]')].find((i) => i.checked); return r?.closest('label')?.textContent ?? null; })()`);
  await ev(`document.querySelector('input[name="courier"]')?.closest('fieldset')?.scrollIntoView({ block: 'center' }); true`);
  await sleep(300);
  await shot('shipping-buyer-rates');
  check('buyer: no city/postcode fields, place search → 3 real rates, cheapest preselected', noCityField && rates && (cheapest ?? '').includes('J&T EZ'), JSON.stringify({ noCityField, rates, cheapest }));

  // Choose JNE REG: the summary follows the server quote.
  await ev(`[...document.querySelectorAll('input[name="courier"]')].find((i) => i.closest('label').textContent.includes('JNE REG')).click(); true`);
  const total = await waitFor(`document.body.textContent.includes('JNE REG · Rp 18.000') && document.body.textContent.includes('Rp 218.000')`);
  await ev(`[...document.querySelectorAll('dt')].find((x) => x.textContent === 'Ongkir')?.scrollIntoView({ block: 'center' }); true`);
  await sleep(300);
  await shot('shipping-buyer-summary');
  check('buyer: summary shows JNE REG · Rp 18.000 and total Rp 218.000', total);

  await clickText('Bayar sekarang');
  const pending = await waitFor(`document.body.textContent.includes('No. pesanan')`, 20000);
  const reference = await ev(`(document.body.textContent.match(/lps_[A-Z0-9]+/) || [null])[0]`);
  check('buyer: order created and waiting for payment', pending && !!reference, JSON.stringify({ pending, reference }));

  // Pay through the iPaymu mock (sends the webhook like iPaymu).
  await fetch(`http://127.0.0.1:8020/pay-ref/${reference}`, { method: 'POST' });
  const paid = await waitFor(`document.body.textContent.includes('Pembayaran berhasil') || document.body.textContent.includes('berhasil')`, 30000);
  await shot('shipping-buyer-paid');
  const order = JSON.parse(php('-r', `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(App\\Models\\LandingPageOrder::query()->where('reference_id', '${reference}')->first(['status', 'amount', 'shipping_amount', 'shipping_courier', 'commission_amount', 'net_amount', 'shipping_address']));`));
  check('order paid: JNE REG, ongkir 18.000, fee 5% of the product only', paid && order.status === 'paid' && order.shipping_courier === 'JNE REG' && order.shipping_amount === 18000 && order.commission_amount === 10000 && order.net_amount === 208000, JSON.stringify(order));

  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  php('tests/e2e/shipping-seed.php', 'cleanup');
  php('tests/e2e/builder-seed.php', 'cleanup');
  php('tests/e2e/seed.php', 'cleanup');
  exitCode = finish();
}
await sleep(0);
process.exit(exitCode);
