// Kalkulator HPP in the browser (dashboard owner). Grows per stage; each check names its stage.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test. Runs pos-cashier-seed.php (org with a POS
// menu item "Nasi Goreng" Rp25.000). Expect "N/N checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/pos-cashier-seed.php'], { env });
const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_pos_cashier.json', import.meta.url), 'utf8'));
const call = async (method, path, body) => {
  const res = await fetch(`${API}${path}`, { method, headers: { Authorization: `Bearer ${seed.owner_token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Outlet-Id': String(seed.outlet_id) }, body: body ? JSON.stringify(body) : undefined });
  return { status: res.status, json: await res.json().catch(() => null) };
};
const me = (await call('GET', '/auth/me')).json.data;

const browser = await openChrome(9371, 'cdp-hpp');
const { send, ev, waitFor, shot, errors } = browser;
const fill = (selector, value) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : el.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); return true; })()`);
const click = (selector) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`);
const clickText = (selector, text) => ev(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})].find((b) => b.textContent.includes(${JSON.stringify(text)})); if (!el) return false; el.click(); return true; })()`);
const toast = (text) => waitFor(`document.querySelector('[data-toast]')?.textContent.includes(${JSON.stringify(text)})`, 10000);
const text = (selector) => ev(`document.querySelector(${JSON.stringify(selector)})?.textContent ?? ''`);
const optionValue = (selector, label) => ev(`[...document.querySelectorAll(${JSON.stringify(selector + ' option')})].find((o) => o.textContent.includes(${JSON.stringify(label)}))?.value ?? ''`);

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.clear(); localStorage.setItem('hellom_token', ${JSON.stringify(seed.owner_token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(me.user ?? me))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/hpp' });
  await ev(`window.__popups = []; window.alert = (m) => window.__popups.push(String(m)); window.confirm = (m) => { window.__popups.push(String(m)); return true; }; true`);

  // Tahap 1 — sidebar: Kalkulator HPP right below the landing page builder.
  await waitFor(`!!document.querySelector('[data-hpp-onboarding]')`, 20000);
  const order = await ev(`[...document.querySelectorAll('nav a')].map((a) => a.textContent.trim()).filter((t) => /Landing|HPP|POS/.test(t))`);
  check('Tahap 1: sidebar shows Kalkulator HPP right below the landing page builder (free, unlocked)', Array.isArray(order) && order.indexOf('Kalkulator HPP') === order.findIndex((t) => /Landing/.test(t)) + 1, JSON.stringify(order));

  // Tahap 1 — onboarding: F&B, Mode Cepat, starter ingredients.
  await click('[data-business="fnb"]');
  await click('[data-start-mode="quick"]');
  await shot('hpp-onboarding');
  await click('[data-hpp-start]');
  await waitFor(`!!document.querySelector('[data-hpp-app]')`, 10000);
  const ingredients = (await call('GET', '/hpp/ingredients')).json.data.ingredients;
  check('Tahap 1: onboarding saves business type + mode and loads starter ingredients', ingredients.length > 10 && (await call('GET', '/hpp/overview')).json.data.settings.mode === 'quick');

  // Tahap 1 — Mode Cepat: Rp200.000 → 50 porsi = Rp4.000.
  await clickText('[role="tab"]', 'Produk');
  await waitFor(`!!document.querySelector('[data-add-product]')`, 8000);
  await click('[data-add-product]');
  await waitFor(`!!document.querySelector('[data-product-form]')`, 5000);
  await fill('[data-product-form] input[name="name"]', 'Nasi Kuning');
  await fill('[data-product-form] input[name="quick_batch_cost"]', '200000');
  await fill('[data-product-form] input[name="yield_qty"]', '50');
  await fill('[data-product-form] input[name="selling_price"]', '8000');
  const quickHpp = await waitFor(`document.querySelector('[data-hpp-value]')?.textContent.includes('4.000')`, 3000);
  await click('[data-product-form] button[type="submit"]');
  const quickSaved = await toast('HPP Rp4.000');
  check('Tahap 1: Mode Cepat — Rp200.000 jadi 50 porsi = HPP Rp4.000 (live + saved)', quickHpp && quickSaved);

  // Tahap 1 — recipe with ingredients + packaging, suggested price.
  await click('[data-app-mode="recipe"]');
  const modeSwitched = await waitFor(`document.querySelector('[data-app-mode="recipe"]')?.getAttribute('aria-checked') === 'true'`, 5000);
  check('Tahap 1: switching mode Cepat → Resep keeps the data and updates the screen', modeSwitched && (await call('GET', '/hpp/products')).json.data.products.some((p) => p.name === 'Nasi Kuning'));
  await clickText('[role="tab"]', 'Produk');
  await waitFor(`!!document.querySelector('[data-add-product]')`, 8000);
  await click('[data-add-product]');
  await waitFor(`!!document.querySelector('[data-product-form]')`, 5000);
  await click('[data-product-form] [data-mode="recipe"]');
  await fill('[data-product-form] input[name="name"]', 'Es Kopi Susu');
  const pick = async (index, label, qty) => {
    await click('[data-add-line]');
    await waitFor(`document.querySelectorAll('[name="items.${index}.component"] option').length > 3`, 8000);
    await fill(`[name="items.${index}.component"]`, await optionValue(`[name="items.${index}.component"]`, label));
    await fill(`[name="items.${index}.qty"]`, qty);
  };
  await pick(0, 'Biji kopi', '18');
  await pick(1, 'Susu UHT', '150');
  await pick(2, 'Cup + tutup', '1');
  await fill('[data-product-form] input[name="selling_price"]', '18000');
  const live = await waitFor(`document.querySelector('[data-hpp-value]')?.textContent.includes('7.240')`, 3000); // 3.240 + 3.000 + 1.000
  await shot('hpp-recipe');
  await click('[data-use-suggested]');
  const suggested = await text('[data-suggested-price]');
  await click('[data-product-form] button[type="submit"]');
  const recipeSaved = await toast('Es Kopi Susu');
  const saved = (await call('GET', '/hpp/products')).json.data.products.find((p) => p.name === 'Es Kopi Susu');
  check('Tahap 1: recipe — bahan + kemasan HPP Rp7.240, suggested price applied (Rp13.000 at 40%)',
    live && recipeSaved && saved?.cost.full === 7240 && saved?.selling_price === 13000 && suggested.includes('13.000'), JSON.stringify({ live, full: saved?.cost.full, price: saved?.selling_price, suggested }));

  // Tahap 1 — import the POS menu and write a price back to POS.
  await click('[data-import-pos]');
  const imported = await toast('menu POS diimpor');
  const nasi = (await call('GET', '/hpp/products')).json.data.products.find((p) => p.name === 'Nasi Goreng');
  check('Tahap 1: POS menu imported and linked (price read from POS)', imported && nasi?.pos_product_id && nasi.selling_price === 25000);

  // Tahap 1 — ingredient edit shows the unit conversion.
  await clickText('[role="tab"]', 'Bahan');
  await waitFor(`!!document.querySelector('[data-add-ingredient]')`, 8000);
  await click('[data-add-ingredient]');
  await waitFor(`!!document.querySelector('[data-ingredient-form]')`, 5000);
  await fill('[data-ingredient-form] input[name="name"]', 'Ayam fillet');
  await fill('[data-ingredient-form] input[name="buy_price"]', '40000');
  await fill('[data-ingredient-form] input[name="yield_pct"]', '80');
  const conversion = await waitFor(`document.querySelector('[data-unit-cost]')?.textContent.includes('Rp40') && document.querySelector('[data-unit-cost]')?.textContent.includes('Rp50')`, 3000);
  await shot('hpp-ingredient');
  await click('[data-ingredient-form] button[type="submit"]');
  check('Tahap 1: ingredient form converts Rp40.000/kg → Rp40/g → Rp50/g after 80% yield', conversion && await toast('Ayam fillet'));

  // Tahap 2 — fixed costs from the template, spread per estimated unit sold.
  await clickText('[role="tab"]', 'Biaya');
  await waitFor(`!!document.querySelector('[data-overhead-template]')`, 8000);
  await click('[data-overhead-template]');
  const overheadLoaded = await waitFor(`document.querySelector('[data-overhead-total]')?.textContent.includes('5.800.000')`, 8000);
  await fill('[data-allocation-form] input[name="monthly_units"]', '1500');
  const perUnitHint = await waitFor(`document.querySelector('[data-allocation-form]')?.textContent.includes('3.867')`, 3000);
  await click('[data-allocation-form] button[type="submit"]');
  await toast('pembagian biaya disimpan');
  await shot('hpp-costs');
  check('Tahap 2: overhead Rp5,8 jt ÷ 1.500 terjual = Rp3.867 per produk', overheadLoaded && perUnitHint);

  // Tahap 2 — product shows overhead, per-channel price & margin, BEP.
  await clickText('[role="tab"]', 'Produk');
  await waitFor(`!!document.querySelector('[data-product-row="Es Kopi Susu"]')`, 8000);
  await click('[data-product-row="Es Kopi Susu"]');
  await waitFor(`!!document.querySelector('[data-channel="GoFood"]')`, 8000);
  await fill('[data-channel="GoFood"] input', '25000');
  const channelView = await waitFor(`document.querySelector('[data-cost-summary]')?.textContent.includes('Overhead') && !!document.querySelector('[data-bep]')`, 3000);
  await shot('hpp-channels');
  await click('[data-product-form] button[type="submit"]');
  await toast('disimpan');
  const kopi = (await call('GET', '/hpp/products')).json.data.products.find((p) => p.name === 'Es Kopi Susu');
  const go = kopi?.channels.find((c) => c.name === 'GoFood');
  check('Tahap 2: overhead in HPP, own GoFood price with its margin, BEP shown', channelView && Math.round(kopi?.cost.overhead) === 3867 && go?.price === 25000 && go?.margin_pct !== null && kopi?.bep?.units_per_month > 0,
    JSON.stringify({ overhead: kopi?.cost.overhead, go, bep: kopi?.bep }));

  // Tahap 2 — a purchase updates the ingredient price (+15%) and HPP.
  await clickText('[role="tab"]', 'Pembelian');
  await waitFor(`!!document.querySelector('[data-add-purchase]')`, 8000);
  await click('[data-add-purchase]');
  await waitFor(`document.querySelectorAll('[data-purchase-form] select[name="items.0.ingredient_id"] option').length > 3`, 8000);
  await fill('[data-purchase-form] select[name="items.0.ingredient_id"]', await optionValue('[data-purchase-form] select[name="items.0.ingredient_id"]', 'Susu UHT'));
  await fill('[data-purchase-form] input[name="items.0.qty"]', '2');
  await fill('[data-purchase-form] input[name="items.0.total_price"]', '46000');
  const purchasePreview = await waitFor(`document.querySelector('[data-purchase-preview]')?.textContent.includes('naik 15')`, 3000);
  await shot('hpp-purchase');
  await click('[data-purchase-form] button[type="submit"]');
  const purchased = await toast('Susu UHT naik 15');
  const kopiAfter = (await call('GET', '/hpp/products')).json.data.products.find((p) => p.name === 'Es Kopi Susu');
  check('Tahap 2: purchase 2 L susu Rp46.000 → Rp23/ml (+15%), HPP updated automatically', purchasePreview && purchased && Math.round(kopiAfter?.cost.direct) === 7690, JSON.stringify({ direct: kopiAfter?.cost.direct }));

  // Tahap 2 — reports.
  await clickText('[role="tab"]', 'Laporan');
  const reported = await waitFor(`document.querySelector('[data-report-table]')?.textContent.includes('Es Kopi Susu') && document.querySelector('[data-hpp-reports]')?.textContent.includes('/bln')`, 8000);
  await shot('hpp-reports');
  check('Tahap 2: report with HPP, overhead, margin and BEP per product', reported);

  // Tahap 3 — margin alert names the price rise and applies the suggested price.
  await clickText('[role="tab"]', 'Ringkasan');
  const alertShown = await waitFor(`document.querySelector('[data-alert="Es Kopi Susu"]')?.textContent.includes('Susu UHT naik 15')`, 10000);
  await shot('hpp-alerts');
  await click('[data-alert="Es Kopi Susu"] [data-apply-alert]');
  const applied = await toast('Harga Es Kopi Susu jadi');
  const kopiPriced = (await call('GET', '/hpp/products')).json.data.products.find((p) => p.name === 'Es Kopi Susu');
  check('Tahap 3: margin alert "Harga Susu UHT naik 15%…" with one-click suggested price', alertShown && applied && kopiPriced?.selling_price === kopiPriced?.suggested_price, JSON.stringify({ alertShown, price: kopiPriced?.selling_price }));

  // Tahap 3 — POS option → variant, recipe for the linked POS menu, then a paid POS order backflushes stock.
  const nasiPos = (await call('GET', '/hpp/pos-products')).json.data.products.find((p) => p.name === 'Nasi Goreng');
  const posProduct = (await call('GET', '/pos/products')).json.data.products.find((p) => p.id === nasiPos.id);
  await call('PATCH', `/pos/products/${nasiPos.id}`, { category_id: posProduct.category_id, name: 'Nasi Goreng', price: 25000, is_available: 'true',
    options: [{ name: 'Tambahan', type: 'multi', is_required: 'false', values: [{ name: 'Telur ceplok', price_delta: 4000 }] }] });
  await clickText('[role="tab"]', 'Produk');
  await waitFor(`!!document.querySelector('[data-product-row="Nasi Goreng"]')`, 8000);
  await click('[data-product-row="Nasi Goreng"]');
  await waitFor(`!!document.querySelector('[data-product-form]')`, 5000);
  await pick(0, 'Beras', '150');
  await pick(1, 'Minyak goreng', '10');
  await pick(2, 'Telur ayam', '1');
  await waitFor(`!!document.querySelector('[data-import-variants]')`, 8000);
  await click('[data-import-variants]');
  await waitFor(`!!document.querySelector('[data-variant="Telur ceplok"]')`, 3000);
  await clickText('[data-variant="Telur ceplok"] button', '+ bahan tambahan');
  await fill('[data-variant="Telur ceplok"] select[aria-label="Bahan tambahan"]', await optionValue('[data-variant="Telur ceplok"] select[aria-label="Bahan tambahan"]', 'Telur ayam'));
  await fill('[data-variant="Telur ceplok"] input[aria-label="Takaran tambahan"]', '1');
  const variantCost = await waitFor(`document.querySelector('[data-variant="Telur ceplok"] [data-variant-extra]')?.textContent.includes('2.000')`, 3000);
  await shot('hpp-variants');
  await click('[data-product-form] button[type="submit"]');
  await toast('Nasi Goreng');
  const nasiHpp = (await call('GET', '/hpp/products')).json.data.products.find((p) => p.name === 'Nasi Goreng');
  check('Tahap 3: variant imported from the POS option with its own extra cost', variantCost && nasiHpp?.variants?.[0]?.name === 'Telur ceplok' && nasiHpp.variants[0].extra_cost === 2000 && nasiHpp.variants[0].pos_option_value_id,
    JSON.stringify(nasiHpp?.variants));

  const eggsBefore = (await call('GET', '/hpp/ingredients')).json.data.ingredients.find((i) => i.name === 'Telur ayam').stock ?? 0;
  const optionId = (await call('GET', `/hpp/pos-products/${nasiPos.id}/options`)).json.data.options[0];
  const posOrder = (await call('POST', '/pos/orders', { items: [{ product_id: nasiPos.id, quantity: 2, options: [{ option_id: optionId.id, value_id: optionId.values[0].id }] }] })).json?.data?.order;
  await call('POST', `/pos/orders/${posOrder?.id}/payment`, { payment_method: 'cash', payment_amount: 100000 });
  await sleep(1500);
  const eggsAfter = (await call('GET', '/hpp/ingredients')).json.data.ingredients.find((i) => i.name === 'Telur ayam').stock ?? 0;
  check('Tahap 3: paid POS order (2× Nasi Goreng + telur ceplok) takes 4 eggs from stock', !!posOrder && Math.round((eggsBefore - eggsAfter) * 1000) / 1000 === 4, JSON.stringify({ eggsBefore, eggsAfter }));

  check('no browser pop-ups, no script errors', (await ev(`(window.__popups || []).length`)) === 0 && errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  exitCode = finish();
}
process.exit(exitCode);
