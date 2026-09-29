// Fase 5 UI audit at 360px: horizontal scroll, tap targets < 44px, form fonts < 16px (iOS zoom),
// on public pages and the seller dashboard. Uses the shop left by journey.mjs (run before seed.php cleanup).
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:3010';
const SSR = 'http://127.0.0.1:8010';
const API = `${SSR}/api/v1/hellom`;
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const PORT = 9337;
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\cdp-e2e-audit`,
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
    writeFileSync(new URL(`audit-${name}.png`, SHOTS), Buffer.from(res.result.data, 'base64'));
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


// In-page checks. Inline text links inside sentences are exempt from the 44px rule (WCAG 2.5.8).
const AUDIT = `(() => {
  const vw = window.innerWidth;
  const visible = (el) => { const cs = getComputedStyle(el); const r = el.getBoundingClientRect();
    return cs.display !== 'none' && cs.visibility !== 'hidden' && Number(cs.opacity) > 0.05 && r.width > 2 && r.height > 2 && r.right > 0 && r.left < vw; };
  const desc = (el) => { const r = el.getBoundingClientRect();
    const label = (el.getAttribute('aria-label') || el.innerText || el.value || el.placeholder || el.name || '').trim().replace(/\\s+/g, ' ').slice(0, 40);
    return el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : '') + ' "' + label + '" ' + Math.round(r.width) + 'x' + Math.round(r.height); };
  const inlineText = (el) => { if (el.tagName !== 'A') return false; const cs = getComputedStyle(el);
    if (!cs.display.startsWith('inline') || cs.display === 'inline-flex' || cs.display === 'inline-block') return false;
    const p = el.parentElement; return !!p && p.innerText.trim().length > el.innerText.trim().length + 3; };
  const overflow = document.documentElement.scrollWidth - vw;
  const wide = [];
  if (overflow > 1) for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect(); if (!visible(el) || r.right <= vw + 1) continue;
    let p = el.parentElement, clipped = false; while (p && p !== document.body) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden') { clipped = true; break; } p = p.parentElement; }
    if (!clipped && getComputedStyle(el).position !== 'fixed') wide.push(desc(el));
  }
  const small = [];
  for (const el of document.querySelectorAll('a[href], button, [role=button], [role=radio], [role=tab], [role=switch], input:not([type=hidden]), select, textarea, summary')) {
    if (!visible(el) || inlineText(el) || el.closest('[aria-hidden=true]')) continue;
    let r = el.getBoundingClientRect();
    if ((el.type === 'checkbox' || el.type === 'radio' || el.type === 'file' || el.getAttribute('role') === 'switch' || el.classList.contains('sr-only')) && el.closest('label')) r = el.closest('label').getBoundingClientRect();
    if (r.height < 43.5 || r.width < 43.5) small.push(desc(el));
  }
  const fonts = [];
  for (const el of document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([type=color]), select, textarea')) {
    if (!visible(el)) continue; const size = parseFloat(getComputedStyle(el).fontSize);
    if (size < 16) fonts.push(desc(el) + ' ' + size + 'px');
  }
  return { overflow, wide: [...new Set(wide)].slice(0, 8), small: [...new Set(small)], fonts: [...new Set(fonts)] };
})()`;

const report = [];
let tab;
try {
  await sleep(1500);
  tab = await Tab.open('about:blank');
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 360, height: 740, deviceScaleFactor: 2, mobile: true });

  const login = await (await fetch(`${API}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: 'rina-e2e-full@example.test', password: 'RinaE2E-2026!' }) })).json();
  const token = login.data?.token || login.data?.api_token;
  const user = login.data?.user;
  const authed = (path) => fetch(`${API}${path}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }).then((r) => r.json());
  const products = (await authed('/apps/landing-builder/products')).data;
  const productList = products?.items ?? products ?? [];
  const pid = productList[0]?.id;
  const slug = productList.find((p) => p.type === 'file')?.slug ?? productList[0]?.slug;
  const orders = (await authed('/seller/orders')).data;
  const ref = (orders?.data ?? orders?.items ?? [])[0]?.reference_id ?? (orders?.data ?? orders?.items ?? [])[0]?.reference;
  const mails = await (await fetch('http://127.0.0.1:8020/mails')).json();
  const accessMail = [...mails].reverse().find((m) => m.to.includes('budi-e2e-full@example.test') && m.raw.includes('/akses/'));
  const access = accessMail ? (accessMail.raw.replace(/=\r\n/g, '').match(/\/akses\/([A-Za-z0-9]+)/) || [])[1] : null;

  const audit = async (name, url, prep) => {
    try {
      if (url) await tab.go(url);
      await sleep(1200);
      if (prep) await prep();
      await sleep(600);
      const r = await tab.eval(AUDIT);
      await tab.shot(name);
      report.push({ name, url, ...r });
      console.log(`${r.overflow > 1 ? 'OVERFLOW ' + r.overflow + 'px' : 'ok      '}  small:${String(r.small.length).padStart(2)}  font:${String(r.fonts.length).padStart(2)}  ${name}`);
    } catch (e) {
      report.push({ name, url, error: e.message });
      console.log('ERROR    ', name, e.message);
    }
  };

  // Public (buyer, logged out).
  await audit('ssr-home', `${SSR}/rina-kreasi`);
  await audit('ssr-product', `${SSR}/rina-kreasi/${slug}`);
  await audit('ssr-share', null, () => tab.eval(`document.querySelector('[data-share]')?.click(); true`));
  await audit('spa-public-page', `${APP}/rina-kreasi`);
  await audit('spa-public-404', `${APP}/toko-yang-tidak-ada-e2e`);
  await audit('checkout', `${APP}/beli/${pid}`);
  if (ref) await audit('order-status', `${APP}/pesanan/${ref}`);
  if (access) await audit('access', `${APP}/akses/${access}`);
  await audit('order-lookup', `${APP}/cek-pesanan`);
  await audit('policy', `${APP}/kebijakan/syarat`);
  await audit('login', `${APP}/login`);
  await audit('register', `${APP}/register`);

  // Seller dashboard.
  await tab.go(`${APP}/cek-pesanan`);
  await tab.eval(`localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(user))}); localStorage.setItem('hl_onboarding_seen:${user?.current_organization_id ?? 0}', '1'); true`);
  for (const t of ['overview', 'produk', 'pesanan', 'kupon', 'customers', 'saldo', 'statistik', 'pengaturan']) {
    await audit(`lb-${t}`, `${APP}/dashboard/apps/landing-builder${t === 'overview' ? '' : '?tab=' + t}`);
  }
  await audit('lb-product-form', `${APP}/dashboard/apps/landing-builder?tab=produk`, async () => { await sleep(1000); await tab.click('Tambah produk'); await sleep(800); await tab.click('Digital via Google Drive'); });
  await audit('lb-order-detail', `${APP}/dashboard/apps/landing-builder?tab=pesanan`, async () => { await sleep(1200); await tab.eval(`document.querySelector('main button[data-order], main li button, main table tbody tr')?.click(); true`); });
  await audit('lb-withdraw-sheet', `${APP}/dashboard/apps/landing-builder?tab=saldo`, async () => { await sleep(1200); await tab.click('Tarik dana'); });
  await audit('lb-editor', `${APP}/dashboard/apps/landing-builder?tab=editor`, () => sleep(2500));
  await audit('lb-onboarding', `${APP}/dashboard/apps/landing-builder`, async () => { await sleep(1500); await tab.click('Atur'); });
  await audit('payments-rekening', `${APP}/dashboard/payments?tab=rekening`);
  await audit('payments-overview', `${APP}/dashboard/payments`, () => sleep(1500));
  await audit('payments-topup', null, async () => { await tab.click('Isi Saldo'); await sleep(800); });
  await audit('dashboard-home', `${APP}/dashboard`);
} finally {
  writeFileSync(new URL('../../storage/app/e2e_ui_audit.json', import.meta.url), JSON.stringify(report, null, 2));
  chrome.kill();
}
