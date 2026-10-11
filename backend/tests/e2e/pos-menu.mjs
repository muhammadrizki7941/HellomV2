// POS › Menu (owner): edit a product — a missing name is explained in the form (no browser pop-up),
// a 5 MB phone photo is accepted and saved (multipart POST + _method, compressed WebP), a toast
// confirms; delete goes through a styled dialog. Needs Laravel :8010 + Vite :3010 on
// hellom_pos_test. Runs pos-cashier-seed.php itself. Expect "4/4 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/pos-cashier-seed.php'], { env });
const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_pos_cashier.json', import.meta.url), 'utf8'));
const headers = { Authorization: `Bearer ${seed.owner_token}`, Accept: 'application/json', 'X-Outlet-Id': String(seed.outlet_id) };
const me = (await (await fetch(`${API}/auth/me`, { headers })).json()).data;

const browser = await openChrome(9368, 'cdp-pos-menu');
const { send, ev, waitFor, shot, errors } = browser;
const dialogs = [];
const fill = (selector, value) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  // Any window.alert/confirm would be a failure: record instead of answering.
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(seed.owner_token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(me.user ?? me))}); localStorage.setItem('hellom_active_outlet_id', ${JSON.stringify(String(seed.outlet_id))}); true`);
  await send('Page.navigate', { url: APP + '/pos/menu' });
  await waitFor(`!!document.querySelector('[data-edit-product]')`, 20000);
  await ev(`window.alert = (m) => { window.__popups = [...(window.__popups || []), String(m)]; }; window.confirm = (m) => { window.__popups = [...(window.__popups || []), String(m)]; return true; }; true`);

  // Edit the first product, clear its name → message in the form.
  await ev(`document.querySelector('[data-edit-product]').click(); true`);
  await waitFor(`!!document.querySelector('[data-pos-product-form]')`);
  await fill('[data-pos-product-form] input[type="text"]', '');
  await ev(`document.querySelector('[data-pos-product-form] button[type="submit"]').click(); true`);
  const explained = await waitFor(`!!document.querySelector('[data-form-error]') && document.body.textContent.includes('Nama produk wajib diisi.') && document.activeElement?.getAttribute('aria-invalid') === 'true'`, 5000);
  await shot('pos-menu-error');
  check('missing name: explained in the form, field focused, no browser pop-up', explained && !(await ev(`(window.__popups || []).length`)), JSON.stringify(await ev(`window.__popups || []`)));

  // Fix the name, attach a 5 MB phone photo, save.
  await fill('[data-pos-product-form] input[type="text"]', 'Nasi Goreng Spesial E2E');
  const { result } = await send('Runtime.evaluate', { expression: `(async () => {
    const c = document.createElement('canvas'); c.width = 3000; c.height = 2000; const g = c.getContext('2d');
    for (let i = 0; i < 4000; i++) { g.fillStyle = 'hsl(' + (i % 360) + ',80%,50%)'; g.fillRect(Math.random() * 3000, Math.random() * 2000, 60, 60); }
    const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
    const pad = new Uint8Array(Math.max(0, 5 * 1024 * 1024 - blob.size)); // make it a ~5 MB phone-sized file
    const file = new File([blob, pad], 'foto-hp.png', { type: 'image/png' });
    const input = document.querySelector('#image-upload'); const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true })); return file.size;
  })()`, awaitPromise: true, returnByValue: true });
  await sleep(500);
  await ev(`document.querySelector('[data-pos-product-form] button[type="submit"]').click(); true`);
  const saved = await waitFor(`!!document.querySelector('[data-toast]') && document.querySelector('[data-toast]').textContent.includes('diperbarui') && !document.querySelector('[data-pos-product-form]')`, 20000);
  await shot('pos-menu-saved');
  const product = (await (await fetch(`${API}/pos/products`, { headers })).json()).data.products.find((p) => p.name === 'Nasi Goreng Spesial E2E');
  check('5 MB photo + new name saved (toast), stored as compressed WebP', saved && !!product && String(product.image_path).endsWith('.webp'), JSON.stringify({ size: result?.result?.value, saved, image: product?.image_path }));

  // Delete through the dialog.
  const del = `document.querySelector('[data-delete-product][aria-label="Hapus Nasi Goreng Spesial E2E"]')`;
  await waitFor(`!!${del}`, 15000); // the list reloads after saving
  await ev(`${del}.click(); true`);
  const dialog = await waitFor(`!!document.querySelector('[data-delete-dialog]') && document.querySelector('[data-delete-dialog]').textContent.includes('Nasi Goreng Spesial E2E')`, 5000);
  await shot('pos-menu-delete');
  await ev(`[...document.querySelectorAll('[data-delete-dialog] button')].find((b) => b.textContent.includes('Hapus')).click(); true`);
  const gone = await waitFor(`document.querySelector('[data-toast]')?.textContent.includes('dihapus')`, 15000);
  check('delete: styled confirmation dialog, then toast', dialog && gone && !(await ev(`(window.__popups || []).length`)));
  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  exitCode = finish();
}
process.exit(exitCode);
