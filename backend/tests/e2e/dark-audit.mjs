// Dashboard dark mode check: screenshots + text contrast (< 4.5:1, or < 3:1 for large text) on
// seller dashboard pages; public pages must stay light. Run after journey.mjs, before cleanup.
//   node tests/e2e/dark-audit.mjs [light]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:3010';
const SSR = 'http://127.0.0.1:8010';
const API = `${SSR}/api/v1/hellom`;
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const PORT = 9339;
const MODE = process.argv[2] === 'light' ? 'light' : 'dark';
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-e2e-dark`,
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
    writeFileSync(new URL(`${MODE}-${name}.png`, SHOTS), Buffer.from(res.result.data, 'base64'));
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


// Resolve any CSS color (oklch, color-mix…) to RGBA through a canvas, walk up for the background.
const CONTRAST = `(() => {
  const cv = document.createElement('canvas'); cv.width = cv.height = 1; const cx = cv.getContext('2d', { willReadFrequently: true });
  const rgba = (c) => { cx.clearRect(0, 0, 1, 1); cx.fillStyle = '#000'; cx.fillStyle = c; cx.fillRect(0, 0, 1, 1); const d = cx.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2], d[3] / 255]; };
  const lum = ([r, g, b]) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const blend = (top, bottom) => { const a = top[3]; return [0, 1, 2].map((i) => top[i] * a + bottom[i] * (1 - a)).concat(1); };
  const bgOf = (el) => { const stack = []; for (let e = el; e; e = e.parentElement) { const cs = getComputedStyle(e);
      if (cs.backgroundImage && cs.backgroundImage !== 'none' && !cs.backgroundImage.startsWith('url')) return null;
      const c = rgba(cs.backgroundColor); if (c[3] > 0) { stack.push(c); if (c[3] >= 0.99) break; } }
    let out = [255, 255, 255, 1]; for (let i = stack.length - 1; i >= 0; i--) out = blend(stack[i], out); return out; };
  const bad = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const seen = new Set();
  while (walker.nextNode()) {
    const el = walker.currentNode.parentElement; const text = walker.currentNode.textContent.trim();
    if (!el || !text || seen.has(el) || el.closest('.hl-light, [aria-hidden=true], svg, .sr-only')) continue; seen.add(el);
    const cs = getComputedStyle(el); const r = el.getBoundingClientRect();
    if (cs.visibility === 'hidden' || Number(cs.opacity) < 0.1 || r.width < 2 || r.height < 2 || r.bottom < 0 || r.right < 0 || r.left > innerWidth) continue;
    if (el.closest('button:disabled, [disabled]')) continue;
    const bg = bgOf(el); if (!bg) continue;
    const fg = blend(rgba(cs.color), bg);
    const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const size = parseFloat(cs.fontSize); const bold = Number(cs.fontWeight) >= 700;
    const need = size >= 24 || (size >= 18.66 && bold) ? 3 : 4.5;
    if (ratio < need) bad.push(ratio.toFixed(2) + ' ' + el.tagName.toLowerCase() + ' "' + text.slice(0, 40) + '" ' + cs.color + ' on rgb(' + bg.slice(0, 3).map(Math.round).join(',') + ')');
  }
  return { dark: document.documentElement.classList.contains('hl-dark'), bad };
})()`;

const report = [];
let tab;
try {
  await sleep(1500);
  tab = await Tab.open('about:blank');
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  const login = await (await fetch(`${API}/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: 'rina-e2e-full@example.test', password: 'RinaE2E-2026!' }) })).json();
  const token = login.data?.token || login.data?.api_token;
  const user = login.data?.user;
  await tab.go(`${APP}/cek-pesanan`);
  await tab.eval(`localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(user))}); localStorage.setItem('hl_onboarding_seen:${user?.current_organization_id ?? 0}', '1'); localStorage.setItem('hellom_theme', '${MODE}'); true`);

  const check = async (name, url, prep) => {
    try {
      if (url) await tab.go(url);
      await sleep(1400);
      await tab.waitFor(`!document.querySelector('.animate-pulse, [aria-busy=true]')`, 'loaded', 12000).catch(() => {});
      if (prep) await prep();
      await sleep(900);
      await tab.waitFor(`!document.querySelector('.animate-pulse, [aria-busy=true]')`, 'loaded', 8000).catch(() => {});
      const r = await tab.eval(CONTRAST);
      await tab.shot(name);
      report.push({ name, ...r });
      console.log(`${r.dark === (MODE === 'dark') ? '' : '!! mode not applied  '}${String(r.bad.length).padStart(3)} low-contrast  ${name}`);
    } catch (e) { report.push({ name, error: e.message }); console.log('ERROR', name, e.message); }
  };
  const lb = (t) => `${APP}/dashboard/apps/landing-builder${t ? '?tab=' + t : ''}`;
  await check('overview', lb());
  for (const t of ['produk', 'pesanan', 'kupon', 'customers', 'saldo', 'statistik', 'pengaturan']) await check(t, lb(t));
  await check('product-form', lb('produk'), async () => { await sleep(800); await tab.click('Tambah produk'); await sleep(600); await tab.click('Digital via Google Drive'); });
  await check('order-detail', lb('pesanan'), async () => { await sleep(1000); await tab.eval(`document.querySelector('main li button, main table tbody tr')?.click(); true`); });
  await check('payout-sheet', `${lb('saldo')}&rekening=1`, () => sleep(800));
  await check('withdraw-sheet', lb('saldo'), async () => { await sleep(800); await tab.click('Tarik dana'); });
  await check('onboarding', lb(), async () => { await sleep(1000); await tab.click('Atur'); });
  await check('editor', lb('editor'), () => sleep(2500));
  await check('payments', `${APP}/dashboard/payments`);
  await check('dashboard-home', `${APP}/dashboard`);
  await check('profile', `${APP}/dashboard/profile`);
  await check('menu-open', `${APP}/dashboard`, async () => { await tab.eval(`document.querySelector('[aria-label="Buka menu"]').click(); true`); });
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 860, deviceScaleFactor: 1, mobile: false });
  await check('desktop-overview', lb());
  await check('desktop-editor', lb('editor'), () => sleep(2500));
  // Public pages must stay light even with the dark preference.
  for (const [name, url] of [['public-lookup', `${APP}/cek-pesanan`], ['public-login', `${APP}/login`]]) {
    await tab.go(url); await sleep(1200);
    const d = await tab.eval(`document.documentElement.classList.contains('hl-dark')`);
    console.log(`${d ? '!! DARK on public page' : 'light ok'}  ${name}`);
    report.push({ name, publicDark: d });
  }
} finally {
  writeFileSync(new URL(`../../storage/app/e2e_${MODE}_audit.json`, import.meta.url), JSON.stringify(report, null, 2));
  chrome.kill();
}
