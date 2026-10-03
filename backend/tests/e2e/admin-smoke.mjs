// Super admin smoke test (1366px): opens every admin menu, records console errors, failed API
// calls (status >= 400 or network errors) and pages that stay empty, and tries the main read
// actions (organization detail, audit filter, KYC/manual queues). Needs Laravel :8010 + Vite :3010
// on hellom_pos_test and tests/e2e/admin-smoke-seed.php. Result: storage/app/admin_smoke_result.json.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const APP = 'http://127.0.0.1:3010';
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const seed = JSON.parse(readFileSync(new URL('../../storage/app/admin_smoke_seed.json', import.meta.url), 'utf8'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const PORT = 9341;
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-admin-smoke`,
  '--no-first-run', '--window-size=1366,900', 'about:blank',
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
    await tab.send('Network.enable');
    return tab;
  }
  constructor(ws) {
    this.id = 0; this.pending = new Map(); this.logs = []; this.failed = []; this.requests = new Map();
    this.ws = new WebSocket(ws);
    this.ready = new Promise((r) => this.ws.addEventListener('open', r));
    this.ws.addEventListener('message', (e) => {
      const msg = JSON.parse(e.data);
      if (msg.id && this.pending.has(msg.id)) { this.pending.get(msg.id)(msg); this.pending.delete(msg.id); return; }
      const p = msg.params;
      if (msg.method === 'Runtime.consoleAPICalled' && p.type === 'error') this.logs.push(p.args.map((a) => a.value ?? a.description).join(' ').slice(0, 300));
      if (msg.method === 'Runtime.exceptionThrown') this.logs.push('EXCEPTION ' + (p.exceptionDetails.exception?.description ?? p.exceptionDetails.text).slice(0, 300));
      if (msg.method === 'Network.requestWillBeSent') this.requests.set(p.requestId, `${p.request.method} ${p.request.url}`);
      if (msg.method === 'Network.responseReceived' && p.response.status >= 400 && p.response.url.includes('/api/')) this.failed.push(`${p.response.status} ${this.requests.get(p.requestId) ?? p.response.url}`);
      if (msg.method === 'Network.loadingFailed' && !p.canceled) this.failed.push(`NETERR ${this.requests.get(p.requestId)} ${p.errorText}`);
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
  async waitFor(expr, timeout = 15000) {
    const start = Date.now();
    while (Date.now() - start < timeout) {
      try { if (await this.eval(expr)) return true; } catch { /* page navigating */ }
      await sleep(200);
    }
    return false;
  }
  async go(path) {
    await this.send('Page.navigate', { url: APP + path });
    await sleep(400);
    await this.waitFor('document.readyState === "complete"');
  }
  async shot(name) {
    const { result } = await this.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    writeFileSync(new URL(`${name}.png`, SHOTS), Buffer.from(result.data, 'base64'));
  }
}

// Text that only shows while a page is still loading.
const LOADING = /Memuat|Memeriksa server|Loading/;
const PAGES = [
  ['/admin', 'Ringkasan'], ['/admin/users', 'Pengguna'], ['/admin/organizations', 'Organisasi'], ['/admin/apps', 'Aplikasi & Paket'],
  ['/admin/invoices', 'Invoice'], ['/admin/keuangan', 'Ringkasan Keuangan'], ['/admin/finance', 'Keuangan Platform'], ['/admin/keuangan-penjual', 'Keuangan Penjual'],
  ['/admin/moderasi-toko', 'Moderasi Toko'], ['/admin/showcase', 'Showcase'], ['/admin/landing-content', 'Konten Situs'],
  ['/admin/brand', 'Branding'], ['/admin/audit-log', 'Log Audit'], ['/admin/system', 'Kesehatan Sistem'], ['/admin/settings', 'Pengaturan'],
  ['/admin/settings/email', 'Pengaturan Email'], ['/admin/notifications', 'Notifikasi'], ['/admin/products', 'Produk'],
  ['/admin/products/new', 'Produk'], ['/admin/products/purchases', 'Pembelian'],
];

const results = [];
let tab;
try {
  tab = await Tab.open(APP + '/login');
  await tab.waitFor('document.readyState === "complete"');
  await tab.eval(`localStorage.setItem('hellom_token', ${JSON.stringify(seed.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(seed.user))}); true`);

  for (const [path, expected] of PAGES) {
    tab.logs = []; tab.failed = [];
    await tab.go(path);
    const found = await tab.waitFor(`document.body.innerText.includes(${JSON.stringify(expected)}) && !!document.querySelector('main')`, 12000);
    // php artisan serve on Windows answers one request at a time: allow a slow queue.
    await tab.waitFor(`!${LOADING}.test(document.querySelector('main')?.innerText || '')`, 25000);
    await sleep(600);
    const stuck = await tab.eval(`${LOADING}.test(document.querySelector('main')?.innerText || '')`);
    const onAdmin = await tab.eval('location.pathname');
    const name = 'admin-' + (path.replace(/\//g, '-').replace(/^-/, '') || 'root');
    await tab.shot(name);
    // GET /api/health answers 503 by design while a check fails (no scheduler on the test DB).
    tab.failed = tab.failed.filter((line) => !(line.startsWith('503 GET') && line.endsWith('/api/health')));
    const entry = { path, ok: found && !stuck && onAdmin === path && tab.logs.length === 0 && tab.failed.length === 0, found, stuck, url: onAdmin, console: tab.logs, failed: tab.failed };
    results.push(entry);
    console.log(`${entry.ok ? 'OK  ' : 'FAIL'} ${path}${entry.ok ? '' : ' ' + JSON.stringify({ found, stuck, url: onAdmin, console: tab.logs, failed: tab.failed })}`);
  }

  // Actions: organization detail opens; audit log filter; KTP preview button exists only with KYC.
  tab.logs = []; tab.failed = [];
  await tab.go('/admin/organizations');
  await tab.waitFor(`document.querySelector('main').innerText.includes('Toko Smoke Admin')`);
  await tab.eval(`[...document.querySelectorAll('main button')].find((b) => b.innerText.trim() === 'Detail')?.click(); true`);
  const detail = await tab.waitFor(`document.body.innerText.includes('Akses aplikasi') && document.body.innerText.includes('Batas outlet')`);
  await tab.shot('admin-organizations-detail');
  results.push({ path: 'action: organization detail', ok: detail && tab.logs.length === 0 && tab.failed.length === 0, console: tab.logs, failed: tab.failed });
  console.log(`${detail ? 'OK  ' : 'FAIL'} action: organization detail`);

  tab.logs = []; tab.failed = [];
  await tab.go('/admin/audit-log');
  await tab.waitFor(`!!document.querySelector('main select')`);
  await tab.eval(`(() => { const s = document.querySelector('main select'); s.value = 'organization.'; s.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
  await sleep(1200);
  const auditOk = tab.logs.length === 0 && tab.failed.length === 0;
  results.push({ path: 'action: audit filter', ok: auditOk, console: tab.logs, failed: tab.failed });
  console.log(`${auditOk ? 'OK  ' : 'FAIL'} action: audit filter`);

  tab.logs = []; tab.failed = [];
  await tab.go('/admin/finance');
  const manualQueue = await tab.waitFor(`document.querySelector('main').innerText.includes('Toko Smoke Admin') && document.querySelector('main').innerText.includes('Setujui')`);
  results.push({ path: 'action: manual transfer queue shows the seeded request', ok: manualQueue, console: tab.logs, failed: tab.failed });
  console.log(`${manualQueue ? 'OK  ' : 'FAIL'} action: manual transfer queue`);

  // Finance journal: a transaction row opens its double-entry detail; the gateway filter answers.
  tab.logs = []; tab.failed = [];
  await tab.go('/admin/keuangan');
  const hasRow = await tab.waitFor(`document.querySelectorAll('main tbody tr.cursor-pointer').length > 0`);
  await tab.eval(`document.querySelector('main tbody tr.cursor-pointer')?.click(); true`);
  const drawer = await tab.waitFor(`!!document.querySelector('aside[aria-label="Rincian transaksi"]') && document.body.innerText.includes('Debit')`);
  await tab.shot('admin-keuangan-detail');
  await tab.eval(`document.querySelector('aside[aria-label="Rincian transaksi"] button[aria-label="Tutup"]').click(); true`);
  await tab.eval(`(() => { const s = document.querySelector('main select[aria-label="Gateway"]'); s.value = 'ipaymu'; s.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
  await sleep(1200);
  const financeOk = hasRow && drawer && tab.logs.length === 0 && tab.failed.length === 0;
  results.push({ path: 'action: finance journal detail + filter', ok: financeOk, hasRow, drawer, console: tab.logs, failed: tab.failed });
  console.log(`${financeOk ? 'OK  ' : 'FAIL'} action: finance journal detail + filter`);

  // Narrow (tablet) layout: no horizontal scroll on the overview.
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 820, height: 1100, deviceScaleFactor: 1, mobile: false });
  await tab.go('/admin');
  await tab.waitFor(`document.querySelector('main').innerText.includes('Perlu tindakan')`);
  await sleep(800);
  const overflow = await tab.eval('document.documentElement.scrollWidth > window.innerWidth + 1');
  await tab.shot('admin-overview-tablet');
  results.push({ path: 'tablet 820px: overview without horizontal scroll', ok: !overflow });
  console.log(`${!overflow ? 'OK  ' : 'FAIL'} tablet 820px overview`);
} catch (error) {
  // Without this the exit in finally hides the error and reports only the checks done so far.
  console.error('ABORTED', error);
  results.push({ path: 'script aborted', ok: false, error: String(error) });
} finally {
  writeFileSync(new URL('../../storage/app/admin_smoke_result.json', import.meta.url), JSON.stringify(results, null, 2));
  const passed = results.filter((r) => r.ok).length;
  console.log(`\n${passed}/${results.length} checks OK`);
  chrome.kill();
  process.exit(passed === results.length ? 0 : 1);
}
