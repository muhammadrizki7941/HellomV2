// Turnstile on repeated checkouts, in a real browser, with Cloudflare's always-pass TEST keys:
// run the :8010 server with TURNSTILE_SITE_KEY=1x00000000000000000000AA
// TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA and CACHE_STORE=database. After journey.mjs.
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:3010';
const SSR = 'http://127.0.0.1:8010';
const API = `${SSR}/api/v1/hellom`;
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const PORT = 9340;
const MODE = process.argv[2] === 'light' ? 'light' : 'dark';
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-e2e-captcha`,
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


let tab;
try {
  await sleep(1500);
  const home = await (await fetch(`${SSR}/rina-kreasi`)).text();
  const pid = (home.match(/\/beli\/([a-z0-9]+)/) || [])[1];
  if (!pid) throw new Error('no product on the shop page (run journey.mjs first)');
  // Three orders from this IP reach the threshold.
  for (let i = 0; i < 3; i++) {
    const r = await fetch(`${API}/public/landing-products/${pid}/checkout`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ buyer_name: 'Tes Captcha', buyer_email: `captcha${i}@example.test`, payment_method: 'other' }) });
    console.log('api checkout', i + 1, r.status);
  }
  tab = await Tab.open('about:blank');
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  await tab.go(`${APP}/beli/${pid}`);
  await tab.waitFor(`!!document.querySelector('input[autocomplete=email]')`, 'checkout form', 15000);
  await tab.fill('input[autocomplete=name]', 'Budi Captcha');
  await tab.fill('input[autocomplete=email]', 'budi-captcha@example.test');
  await tab.waitFor(`[...document.querySelectorAll('[role=radio]')].some(b => b.innerText.includes('Virtual Account'))`, 'payment options');
  await tab.click('Virtual Account & lainnya', '[role=radio]');
  await tab.waitFor(`[...document.querySelectorAll('[role=radio]')].some(b => b.innerText.includes('Virtual Account') && b.getAttribute('aria-checked') === 'true')`, 'VA selected');
  await tab.click('Bayar sekarang');
  await tab.waitFor(tab.bodyHas('Satu langkah lagi'), 'captcha requested', 15000);
  await tab.waitFor(tab.bodyHas('pastikan kamu bukan robot'), 'turnstile widget', 20000);
  console.log('widget shown; pay button disabled:', await tab.eval(`[...document.querySelectorAll('button[type=submit]')].some(b => b.disabled)`));
  await tab.shot('captcha-shown');
  await tab.waitFor(`[...document.querySelectorAll('button[type=submit]')].some(b => !b.disabled && b.innerText.includes('Bayar'))`, 'token received', 30000);
  await tab.click('Bayar sekarang');
  await tab.waitFor(`location.host === '127.0.0.1:8020'`, 'went on to payment', 20000);
  console.log('RESULT: captcha solved → order created → payment page');
} catch (e) {
  console.log('FAIL', e.message);
  if (tab) await tab.shot('captcha-fail');
} finally {
  chrome.kill();
}
