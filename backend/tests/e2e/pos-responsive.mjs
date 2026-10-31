// Read-only POS layout audit: 12 routes and all order views at 320, 390, 768, 1366 px.
// Requires Laravel :8010 using hellom_pos_test, a frontend build, and pos-cashier-seed.php.
// Uses isolated headless Chrome; does not modify orders, permissions, or security settings.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:8010';
const SSR = 'http://127.0.0.1:8010';
const API = `${SSR}/api/v1/hellom`;
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const PORT = 9353;
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-e2e-pos-responsive`,
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
    this.ws = new WebSocket(ws); this.ws.addEventListener('error', e => console.log('WS ERROR', e.message)); this.ws.addEventListener('close', e => console.log('WS CLOSED', e.code, e.reason));
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
}

const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_pos_cashier.json', import.meta.url), 'utf8'));
let tab;
let failed = 0;
try {
 await sleep(2000); tab = await Tab.open('about:blank'); await tab.send('Network.enable'); await tab.send('Network.setBypassServiceWorker', {bypass:true}); await tab.send('Network.setCacheDisabled', {cacheDisabled:true});
 const response = await fetch(`${API}/auth/me`, {headers:{Authorization:`Bearer ${seed.owner_token}`,Accept:'application/json'}});
 const me = await response.json();
 await tab.send('Page.addScriptToEvaluateOnNewDocument', {source:`
 localStorage.setItem('hellom_token', ${JSON.stringify(seed.owner_token)});
 localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(me.data?.user ?? me.data))});
 const originalFetch = window.fetch;
 window.fetch = (input, init) => originalFetch(typeof input === 'string' ? input.replace('127.0.0.1:8000/api/', '127.0.0.1:8010/api/') : input, init);
 `});
 for (const width of (process.env.POS_WIDTHS || '320,390,768,1366').split(',').map(Number)) {
  await tab.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
  for(const route of ['orders','menu','tables','members','loyalty','reports','settings','outlets','staff','admin-dashboard','customer-hub','audit']) {
   await tab.go(`${APP}/pos/${route}`);
   await tab.waitFor("document.querySelector('main')?.innerText.trim().length > 0", "page ready", 60000); await sleep(1500);
   if (route==='orders') for(const mode of ['Daftar','Detail','Kartu']) {
    await tab.mustClick(mode,'[aria-label="Tampilan pesanan"] button'); await sleep(150);
    await check(`${route}-${mode}`,width);
   } else await check(route,width);
  }
 }
 async function check(name,width) {
  const state = await tab.eval(`({path:location.pathname,width:innerWidth,scroll:document.documentElement.scrollWidth,text:document.querySelector('main')?.innerText.slice(0,100),overflow:[...document.querySelectorAll('main *')].filter(e=>{const r=e.getBoundingClientRect();return r.width && r.right>document.documentElement.clientWidth+1 && getComputedStyle(e).position!=='fixed' && !e.closest('[class*="overflow-x-auto"]')}).slice(0,5).map(e=>({tag:e.tagName,cls:e.className}))})`);
  const ok = state.scroll<=width+1 && state.path.startsWith('/pos/') && !!state.text;
  console.log(ok?'PASS':'FAIL',width,name,JSON.stringify(state));
  if(!ok) failed++;
  if(name==='orders-Daftar' || !ok) { await tab.eval("window.scrollTo(0,0)"); await tab.shot(`responsive-${width}-${name}`); }
 }
 const exceptions = tab.logs.filter(l=>l.startsWith('EXCEPTION'));
 console.log('EXCEPTIONS',JSON.stringify(exceptions));
 failed += exceptions.length;
 process.exitCode=failed?1:0;
} finally {
 if (tab) {
  await Promise.race([tab.send('Browser.close'), sleep(2000)]).catch(() => {});
  tab.ws.close();
 }
 chrome.kill();
}
