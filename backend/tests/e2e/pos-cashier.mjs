// POS cashier permissions in the browser: default menu, direct-URL redirect, owner grants/revokes
// features and the cashier menu follows without re-login, orders always work, owner sees the switches.
// Needs Laravel :8010 + Vite :3010 (see README) and: DB_DATABASE=hellom_pos_test php tests/e2e/pos-cashier-seed.php
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:3010';
const SSR = 'http://127.0.0.1:8010';
const API = `${SSR}/api/v1/hellom`;
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const PORT = 9341;
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-e2e-pos-cashier`,
  '--no-first-run', '--window-size=360,740', 'about:blank',
], { stdio: 'ignore' });
async function json(url, method = 'GET') {
  for (let i = 0; i < 40; i++) {
    try { return await (await fetch(url, { method })).json(); } catch { await sleep(250); }
  }
  throw new Error('chrome not reachable');
}
class Tab {
  static async open(url) {
    const info = await json(`http://127.0.0.1:${PORT}/json/new?${encodeURIComponent(url)}`, 'PUT');
    const tab = new Tab(info.webSocketDebuggerUrl);
    await tab.ready;
    await tab.send('Runtime.enable');
    await tab.send('Page.enable');
    await tab.send('DOM.enable');
    tab.logs = [];
    tab.ws.addEventListener('message', (e) => {
      const msg = JSON.parse(e.data);
      if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') tab.logs.push(msg.params.args.map((a) => a.value ?? a.description).join(' '));
      if (msg.method === 'Runtime.exceptionThrown') tab.logs.push('EXCEPTION ' + (msg.params.exceptionDetails.exception?.description ?? msg.params.exceptionDetails.text));
    });
    return tab;
  }
  constructor(ws) {
    this.id = 0; this.pending = new Map();
    this.ws = new WebSocket(ws);
    this.ready = new Promise((r) => this.ws.addEventListener('open', r));
    this.ws.addEventListener('message', (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && this.pending.has(msg.id)) { this.pending.get(msg.id)(msg); this.pending.delete(msg.id); }
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((r) => this.pending.set(id, r));
  }
  async eval(expr) {
    const res = await this.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (res.result?.exceptionDetails) throw new Error(res.result.exceptionDetails.exception?.description || 'eval failed');
    return res.result?.result?.value;
  }
  async waitFor(expr, label, timeout = 15000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
      if (await this.eval(expr).catch(() => false)) return true;
      await sleep(250);
    }
    throw new Error(`timeout: ${label}`);
  }
  async go(url) { await this.send('Page.navigate', { url }); await sleep(1200); }
  async shot(name) {
    const res = await this.send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(new URL(`${name}.png`, SHOTS), Buffer.from(res.result.data, 'base64'));
  }
  bodyHas(text) { return `document.body.innerText.includes(${JSON.stringify(text)})`; }
  click(text, selector = 'button, a, [role=radio]') {
    return this.eval(`(() => { const el = [...document.querySelectorAll(${JSON.stringify(selector)})].find(b => b.innerText.trim().includes(${JSON.stringify(text)}) && !b.disabled && b.offsetParent !== null); if (!el) return false; el.scrollIntoView({ block: 'center' }); el.click(); return true; })()`);
  }
  async mustClick(text, selector) {
    if (!(await this.click(text, selector))) throw new Error(`no clickable "${text}"`);
  }
  /** Set a React-controlled input/textarea (found by CSS selector) and fire input events. */
  async fill(selector, value) {
    const ok = await this.eval(`(() => { const el = ${selector.startsWith('label:') ? `(() => { const l = [...document.querySelectorAll('label')].find(l => l.innerText.trim().startsWith(${JSON.stringify(selector.slice(6))}) && l.offsetParent !== null); return l && (l.querySelector('input, textarea') || document.getElementById(l.htmlFor)); })()` : `document.querySelector(${JSON.stringify(selector)})`};
      if (!el) return false; el.focus();
      const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)});
      el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
    if (!ok) throw new Error(`no input ${selector}`);
  }
  async upload(selector, path) {
    const doc = await this.send('DOM.getDocument', { depth: -1, pierce: true });
    const found = await this.send('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector });
    if (!found.result?.nodeId) throw new Error(`no file input ${selector}`);
    await this.send('DOM.setFileInputFiles', { nodeId: found.result.nodeId, files: [path] });
  }
}


const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_pos_cashier.json', import.meta.url), 'utf8'));
const results = [];
const step = async (name, fn) => {
  try { await fn(); results.push(['OK', name]); console.log('OK  ', name); }
  catch (e) { results.push(['FAIL', name, e.message]); console.log('FAIL', name, '-', e.message); if (tab) await tab.shot('fail-' + results.length).catch(() => {}); }
};
const api = (token, method, path, body) => fetch(`${API}${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Outlet-Id': String(seed.outlet_id) },
  body: body ? JSON.stringify(body) : undefined }).then(async (r) => ({ status: r.status, json: await r.json().catch(() => null) }));
const setPermissions = (permissions) => api(seed.owner_token, 'PUT', `/pos/staff/${seed.staff_id}`, { name: 'Kasir Sinta', role: 'cashier', employment_status: 'active', permissions });
const navTexts = () => tab.eval(`[...document.querySelectorAll('aside a[href^="/pos/"], nav a[href^="/pos/"]')].filter(a => a.offsetParent !== null).map(a => a.innerText.trim()).filter(Boolean)`);
const login = async (token) => {
  const me = await api(token, 'GET', '/auth/me');
  await tab.go(`${APP}/cek-pesanan`);
  await tab.eval(`localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(me.json.data?.user ?? me.json.data))}); true`);
};

let tab;
const state = {};
try {
  await sleep(1500);
  tab = await Tab.open('about:blank');
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 860, deviceScaleFactor: 1, mobile: false });
  await setPermissions({}); // defaults

  await step('kasir default: menu Orders + Tables + Members', async () => {
    await login(seed.cashier_token);
    await tab.go(`${APP}/pos/orders`);
    await tab.waitFor(`[...document.querySelectorAll('a[href="/pos/orders"]')].some(a => a.offsetParent !== null)`, 'orders nav', 20000);
    const items = await navTexts();
    console.log('     menu:', items.join(' | '));
    for (const hidden of ['Reports', 'Product Management', 'Staff', 'Outlet', 'Dashboard', 'Settings', 'Loyalty']) {
      if (items.some((t) => t === hidden)) throw new Error(`${hidden} visible`);
    }
    if (!items.includes('Orders') || !items.includes('Members') || !items.includes('Tables')) throw new Error('Orders/Tables/Members missing');
    await tab.shot('pos-cashier-default');
  });

  await step('buka /pos/reports langsung → dialihkan ke Orders', async () => {
    await tab.go(`${APP}/pos/reports`);
    await tab.waitFor(`location.pathname === '/pos/orders'`, 'redirect', 10000);
  });

  await step('owner beri akses Laporan & Produk → menu kasir berubah tanpa login ulang', async () => {
    const r = await setPermissions({ reports: true, products: true });
    if (r.status !== 200) throw new Error('update ' + r.status + ' ' + JSON.stringify(r.json).slice(0, 200));
    await tab.eval(`window.dispatchEvent(new Event('focus')); true`);
    await tab.waitFor(`[...document.querySelectorAll('a[href="/pos/reports"]')].some(a => a.offsetParent !== null)`, 'reports nav', 15000);
    const items = await navTexts();
    console.log('     menu:', items.join(' | '));
    if (!items.includes('Product Management')) throw new Error('products missing');
    await tab.go(`${APP}/pos/reports`);
    await sleep(1500);
    if (await tab.eval(`location.pathname`) !== '/pos/reports') throw new Error('reports redirected');
    await tab.shot('pos-cashier-granted');
  });

  await step('owner cabut akses Member → kasir di halaman Member dialihkan', async () => {
    await tab.go(`${APP}/pos/members`);
    await tab.waitFor(`location.pathname === '/pos/members'`, 'members page', 10000);
    await setPermissions({ reports: true, products: true, members: false });
    await tab.eval(`window.dispatchEvent(new Event('focus')); true`);
    await tab.waitFor(`location.pathname === '/pos/orders'`, 'redirect after revoke', 15000);
  });

  await step('kasir buka kas dari layar Orders', async () => {
    await tab.go(`${APP}/pos/orders`);
    await tab.waitFor(`[...document.querySelectorAll('button')].some(b => b.innerText.includes('Buka kas'))`, 'cash button', 15000);
    await tab.click('Buka kas');
    await tab.waitFor(tab.bodyHas('Hitung uang di laci'), 'open dialog');
    await tab.fill('[role=dialog] input[inputmode=numeric]', '200000');
    await tab.shot('pos-cash-open');
    await tab.eval(`[...document.querySelectorAll('[role=dialog] button')].find(b => b.innerText.trim() === 'Buka kas').click(); true`);
    await tab.waitFor(`[...document.querySelectorAll('button')].some(b => b.innerText.includes('Kas terbuka'))`, 'drawer open', 15000);
  });

  await step('Kasir & pesanan tetap bisa: buat pesanan', async () => {
    const products = await api(seed.cashier_token, 'GET', '/pos/products');
    const list = products.json?.data?.products ?? products.json?.data?.items ?? products.json?.data ?? [];
    const pid = (Array.isArray(list) ? list : list.data ?? [])[0]?.id;
    const r = await api(seed.cashier_token, 'POST', '/pos/orders', { items: [{ product_id: pid, quantity: 1 }] });
    if (r.status !== 201) throw new Error('order ' + r.status + ' ' + JSON.stringify(r.json).slice(0, 200));
    state.orderTotal = r.json.data.order.final_amount;
    const paid = await api(seed.cashier_token, 'POST', `/pos/orders/${r.json.data.order.id}/payment`, { payment_method: 'cash', payment_amount: 100000 });
    if (paid.status !== 200) throw new Error('pay ' + paid.status);
  });

  await step('kasir tutup kas: selisih dihitung dari kas awal + penjualan tunai', async () => {
    await tab.go(`${APP}/pos/orders`);
    await tab.waitFor(`[...document.querySelectorAll('button')].some(b => b.innerText.includes('Kas terbuka'))`, 'cash button', 15000);
    await tab.click('Kas terbuka');
    const expected = 200000 + state.orderTotal;
    await tab.waitFor(tab.bodyHas('Seharusnya di laci') + ' && ' + tab.bodyHas('Rp ' + expected.toLocaleString('id-ID')), 'expected cash', 10000);
    await tab.fill('[role=dialog] input[inputmode=numeric]', String(expected - 2000));
    await tab.waitFor(tab.bodyHas('Kurang Rp 2.000'), 'live difference');
    await tab.shot('pos-cash-close');
    await tab.eval(`window.confirm = () => true; [...document.querySelectorAll('[role=dialog] button')].find(b => b.innerText.trim() === 'Tutup kas').click(); true`);
    await tab.waitFor(tab.bodyHas('Kas ditutup') + ' && ' + tab.bodyHas('Kurang Rp 2.000'), 'closed summary', 15000);
    await tab.shot('pos-cash-closed');
    await tab.click('Selesai');
    await tab.waitFor(`[...document.querySelectorAll('button')].some(b => b.innerText.includes('Buka kas'))`, 'back to closed', 10000);
  });

  await step('owner: form Staff menampilkan hak akses (Kasir & pesanan terkunci)', async () => {
    await login(seed.owner_token);
    await tab.go(`${APP}/pos/staff`);
    await tab.waitFor(tab.bodyHas('Kasir Sinta'), 'staff list', 20000);
    const opened = await tab.eval(`(() => { const card = [...document.querySelectorAll('article')].find(a => a.innerText.includes('Kasir Sinta')); const b = card && [...card.querySelectorAll('button')].find(x => /edit|ubah/i.test(x.innerText + (x.getAttribute('aria-label') || '') + (x.title || ''))); if (!b) return false; b.click(); return true; })()`);
    if (!opened) throw new Error('edit button not found');
    await tab.waitFor(tab.bodyHas('Hak akses POS'), 'form', 10000);
    await tab.waitFor(tab.bodyHas('(selalu aktif)'), 'locked orders', 5000);
    const state = await tab.eval(`Object.fromEntries([...document.querySelectorAll('[role=switch]')].map(b => [b.innerText.split('\\n')[0].replace('(selalu aktif)', '').trim(), b.getAttribute('aria-checked')]))`);
    console.log('     switches:', JSON.stringify(state));
    if (state['Laporan'] !== 'true' || state['Member'] !== 'false' || state['Kasir & pesanan'] !== 'true') throw new Error('unexpected switches');
    await tab.eval(`document.querySelector('[role=dialog], form')?.scrollIntoView(); true`);
    await tab.shot('pos-staff-permissions');
  });
} finally {
  console.log('\n' + results.filter((r) => r[0] === 'OK').length + '/' + results.length + ' steps OK');
  chrome.kill();
}
