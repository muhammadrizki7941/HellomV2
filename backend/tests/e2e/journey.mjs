// Fase 5 end-to-end journey in a real (headless) Chrome at 390px:
// register seller → onboarding (username, template, Drive product) → file product → published
// page → buyer checkout without login → pay on the iPaymu sandbox (local mock) → webhook →
// access email (SMTP sink) → access page / download → balance → email verification → KYC →
// admin approves → withdraw Rp50.000 → admin marks it paid → seller sees it done.
// Stack: Laravel :8010 (hellom_pos_test), Vite :3010, mocks.mjs (:8020 iPaymu, :1025 SMTP).
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const seed = JSON.parse(readFileSync(new URL('../../storage/app/e2e_full_seed.json', import.meta.url), 'utf8'));
const APP = 'http://127.0.0.1:3010';
const SSR = 'http://127.0.0.1:8010';
const API = `${SSR}/api/v1/hellom`;
const MOCK = 'http://127.0.0.1:8020';
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
const FILES = new URL('../../storage/app/e2e_files/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
mkdirSync(FILES, { recursive: true });
const pdfPath = fileURLToPath(new URL('panduan-e2e.pdf', FILES));
const proofPath = fileURLToPath(new URL('bukti-transfer.png', FILES));
writeFileSync(pdfPath, '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
writeFileSync(proofPath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const backend = fileURLToPath(new URL('../../', import.meta.url));
const artisan = (...args) => execFileSync('php', ['artisan', ...args], { cwd: backend, env: { ...process.env, DB_DATABASE: 'hellom_pos_test' }, encoding: 'utf8' });

const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
  '--headless=new', '--remote-debugging-port=9336', `--user-data-dir=${process.env.TEMP}\\cdp-e2e-full`,
  '--no-first-run', '--window-size=390,844', 'about:blank',
], { stdio: 'ignore' });

async function json(url, method = 'GET') {
  for (let i = 0; i < 40; i++) {
    try { return await (await fetch(url, { method })).json(); } catch { await sleep(250); }
  }
  throw new Error('chrome not reachable');
}

class Tab {
  static async open(url) {
    const info = await json(`http://127.0.0.1:9336/json/new?${encodeURIComponent(url)}`, 'PUT');
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
    writeFileSync(new URL(`full-${name}.png`, SHOTS), Buffer.from(res.result.data, 'base64'));
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

const results = [];
const step = async (name, fn) => {
  try { await fn(); results.push(['OK', name]); console.log('OK  ', name); }
  catch (e) { results.push(['FAIL', name, e.message]); console.log('FAIL', name, '-', e.message); if (tab) await tab.shot('fail-' + results.length).catch(() => {}); }
};
const noOverflow = 'document.documentElement.scrollWidth <= window.innerWidth + 1';
let mailBase = 0; // the mock keeps mail across runs; only look at this run's
const mails = async () => (await fetch(`${MOCK}/mails`)).json().then((all) => all.slice(mailBase));
const mailTo = async (email, subjectPart, timeout = 15000) => {
  const start = Date.now();
  while (Date.now() - start < timeout) {
    const found = (await mails()).filter((m) => m.to.includes(email) && m.subject.toLowerCase().includes(subjectPart.toLowerCase()));
    if (found.length) return found[found.length - 1];
    await sleep(500);
  }
  throw new Error(`no email to ${email} about "${subjectPart}"`);
};
/** First link in a (quoted-printable) email that contains `part`. */
const linkIn = (mail, part) => {
  const text = mail.raw.replace(/=\r\n/g, '').replace(/=3D/g, '=').replace(/&amp;/g, '&');
  const link = (text.match(/https?:\/\/[^\s"'<>]+/g) || []).find((l) => l.includes(part));
  if (!link) throw new Error(`no ${part} link in "${mail.subject}"`);
  return link;
};

let tab;
const state = {};
/** Put a session into the SPA's storage (on the :3010 origin). */
const loginAs = async (tkn, user) => {
  await tab.go(`${APP}/cek-pesanan`);
  await tab.eval(`localStorage.setItem('hellom_token', ${JSON.stringify(tkn)}); localStorage.setItem('hellom_user', ${JSON.stringify(typeof user === 'string' ? user : JSON.stringify(user))}); true`);
};
const seller = { name: 'Rina Kreasi', email: seed.seller_email, password: 'RinaE2E-2026!', org: 'Rina Kreasi Studio', username: 'rina-kreasi' };
const buyer = { name: 'Budi Pembeli', email: 'budi-e2e-full@example.test' };

try {
  await sleep(1500);
  mailBase = (await (await fetch(`${MOCK}/mails`)).json()).length;
  tab = await Tab.open('about:blank');
  await tab.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });

  await step('1. daftar penjual baru', async () => {
    await tab.go(`${APP}/register`);
    await tab.waitFor(`!!document.querySelector('input[placeholder="Contoh: Toko Kopi Senja"]')`, 'register form');
    await tab.fill('input[placeholder="Contoh: Toko Kopi Senja"]', seller.org);
    await tab.fill('input[placeholder="Contoh: Budi Santoso"]', seller.name);
    await tab.fill('input[placeholder="nama@email.com"]', seller.email);
    await tab.fill('input[placeholder="Minimal 8 karakter"]', seller.password);
    await tab.shot('01-register');
    await tab.eval(`document.querySelector('button[type=submit]').click(); true`);
    await tab.waitFor(`location.pathname.startsWith('/dashboard')`, 'dashboard after register', 20000);
  });

  await step('2. onboarding muncul otomatis: username', async () => {
    await tab.go(`${APP}/dashboard/apps/landing-builder`);
    await tab.waitFor(tab.bodyHas('Pilih alamat toko kamu'), 'wizard step 1', 20000);
    if (!(await tab.eval(noOverflow))) throw new Error('horizontal overflow');
    await tab.fill('#onb-username', seller.username);
    await tab.shot('02-wizard-username');
    await tab.mustClick('Simpan & lanjut');
    await tab.waitFor(tab.bodyHas('Pilih tampilan halaman'), 'wizard step 2');
  });

  await step('3. pilih template', async () => {
    await tab.mustClick('Jualan e-book', '[role=radio]');
    await tab.shot('03-wizard-template');
    await tab.mustClick('Pakai template ini');
    await tab.waitFor(tab.bodyHas('Tambah produk pertama'), 'wizard step 3');
  });

  await step('4. produk pertama (Drive) → halaman terbit & bisa dibagikan', async () => {
    await tab.fill('label:Nama produk', 'E-book Resep Kue Kering');
    await tab.fill('input[placeholder="49.000"]', '75000');
    await tab.fill('label:Link Google Drive', 'https://drive.google.com/file/d/1RinaE2EDriveFileAbcdEfgh/view');
    await tab.shot('04-wizard-product');
    await tab.mustClick('Terbitkan halaman');
    await tab.waitFor(tab.bodyHas('Halaman kamu sudah online!'), 'published', 20000);
    state.publicText = await tab.eval(`document.querySelector('p.break-all').innerText`);
    if (!state.publicText.endsWith('/' + seller.username)) throw new Error('unexpected url ' + state.publicText);
    await tab.shot('05-wizard-done');
    await tab.mustClick('Selesai');
    await tab.waitFor(tab.bodyHas('Siapkan toko kamu'), 'checklist');
    await tab.shot('06-overview-checklist');
  });

  await step('5. tambah produk file', async () => {
    await tab.go(`${APP}/dashboard/apps/landing-builder?tab=produk`);
    await tab.waitFor(tab.bodyHas('E-book Resep Kue Kering'), 'product list', 15000);
    await tab.mustClick('Tambah produk');
    await tab.waitFor(tab.bodyHas('Upload file'), 'type picker');
    await tab.mustClick('Upload file');
    await tab.waitFor(`!!document.querySelector('input[type=file]')`, 'file form');
    await tab.fill('label:Nama produk', 'Template Planner Jualan');
    await tab.fill('label:Harga (Rp)', '60000');
    await tab.upload('input[type=file]:not([accept^="image"])', pdfPath);
    await sleep(300);
    await tab.mustClick('Simpan produk');
    await tab.waitFor(tab.bodyHas('Template Planner Jualan') + ` && !document.querySelector('[role=dialog]')`, 'saved', 15000);
    await tab.shot('07-products');
  });

  const token = await tab.eval(`localStorage.getItem('hellom_token')`);
  const sellerUser = await tab.eval(`localStorage.getItem('hellom_user')`);
  const authed = (path) => fetch(`${API}${path}`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }).then((r) => r.json());

  await step('6. halaman publik (SSR) tampil tanpa link Drive, CSP aktif', async () => {
    const res = await fetch(`${SSR}/${seller.username}`);
    const html = await res.text();
    if (res.status !== 200) throw new Error('status ' + res.status);
    if (!res.headers.get('content-security-policy')?.includes("'strict-dynamic'")) throw new Error('no CSP');
    if (html.includes('1RinaE2EDriveFileAbcdEfgh')) throw new Error('drive id leaked');
    await tab.eval(`localStorage.removeItem('hellom_token'); localStorage.removeItem('hellom_user'); true`);
    await tab.go(`${SSR}/${seller.username}`);
    await tab.waitFor(`${tab.bodyHas('E-book Resep Kue Kering')} && ${tab.bodyHas('Beli sekarang')}`, 'ssr content');
    if (!(await tab.eval(noOverflow))) throw new Error('horizontal overflow');
    if (tab.logs.some((l) => /Content Security Policy|Refused to/i.test(l))) throw new Error('CSP violation: ' + tab.logs.join(' | '));
    await tab.shot('08-public-page');
  });

  const buy = async (label, startUrl, buyText) => {
    await tab.go(startUrl);
    await tab.waitFor(tab.bodyHas(buyText), 'buy button');
    // Shop pages link to /beli/… on the same origin (one domain in production); here the
    // SPA runs on :3010, so follow the link's path there.
    const href = await tab.eval(`[...document.querySelectorAll('a')].find(a => a.innerText.includes(${JSON.stringify(buyText)})).getAttribute('href')`);
    await tab.go(APP + new URL(href, SSR).pathname);
    await tab.waitFor(`location.pathname.startsWith('/beli/') && !!document.querySelector('input[autocomplete=email]')`, 'checkout form', 15000);
    if (await tab.eval(`!!localStorage.getItem('hellom_token')`)) throw new Error('buyer is logged in');
    await tab.fill('input[autocomplete=name]', buyer.name);
    await tab.fill('input[autocomplete=email]', buyer.email);
    await tab.mustClick('Virtual Account & lainnya', '[role=radio]');
    if (!(await tab.eval(noOverflow))) throw new Error('checkout overflow');
    await tab.shot(`09-checkout-${label}`);
    await tab.mustClick('Bayar sekarang');
    await tab.waitFor(`location.host === '127.0.0.1:8020'`, 'sandbox payment page', 20000);
    await tab.shot(`10-sandbox-${label}`);
    await tab.eval(`document.querySelector('#pay').click(); true`);
    await tab.waitFor(`location.pathname.startsWith('/pesanan/')`, 'back to order page', 20000);
    const ref = await tab.eval(`location.pathname.split('/').pop()`);
    await tab.waitFor(`/berhasil|lunas|sudah dibayar/i.test(document.body.innerText)`, 'paid status', 20000);
    await tab.shot(`11-paid-${label}`);
    return ref;
  };

  await step('7. pembeli checkout tanpa login → bayar (sandbox) → webhook → lunas (Drive)', async () => {
    state.refDrive = await buy('drive', `${SSR}/${seller.username}`, 'Beli sekarang');
  });

  await step('8. email akses terkirim → halaman akses membuka Drive', async () => {
    const mail = await mailTo(buyer.email, 'E-book Resep Kue Kering');
    state.accessDrive = linkIn(mail, '/akses/');
    if (mail.raw.includes('1RinaE2EDriveFileAbcdEfgh')) throw new Error('raw drive link inside email');
    await tab.go(state.accessDrive.replace(/^https?:\/\/[^/]+/, APP));
    await tab.waitFor(`/Buka|Akses/.test(document.body.innerText) && ${tab.bodyHas('E-book Resep Kue Kering')}`, 'access page', 15000);
    await tab.shot('12-access-drive');
  });

  await step('9. produk file: checkout → bayar → email → unduh file', async () => {
    const products = await fetch(`${SSR}/${seller.username}`).then((r) => r.text());
    const slug = (products.match(new RegExp(`/${seller.username}/(template-planner[\\w-]*)`)) || [])[1] || 'template-planner-jualan';
    state.refFile = await buy('file', `${SSR}/${seller.username}/${slug}`, 'Beli sekarang');
    const mail = await mailTo(buyer.email, 'Template Planner Jualan');
    await tab.go(linkIn(mail, '/akses/').replace(/^https?:\/\/[^/]+/, APP));
    await tab.waitFor(tab.bodyHas('Unduh'), 'download button', 15000);
    await tab.shot('13-access-file');
    const accessToken = linkIn(mail, '/akses/').split('/akses/')[1].split(/[?#]/)[0];
    const opened = await fetch(`${API}/public/landing-access/${accessToken}/open`, { method: 'POST', headers: { Accept: 'application/json' } }).then((r) => r.json());
    const url = opened.data?.url;
    if (!url) throw new Error('no download url: ' + JSON.stringify(opened).slice(0, 200));
    const file = await fetch(url.replace(/^https?:\/\/[^/]+/, SSR));
    if (file.status !== 200 || !(file.headers.get('content-disposition') || '').includes('attachment')) throw new Error('download ' + file.status);
    if (!(await file.text()).startsWith('%PDF')) throw new Error('not the uploaded file');
  });

  await loginAs(token, sellerUser);

  await step('10. saldo penjualan masuk', async () => {
    artisan('landing:orders', 'release');
    await tab.go(`${APP}/dashboard/apps/landing-builder?tab=saldo`);
    await tab.waitFor(tab.bodyHas('Tarik dana'), 'balance', 15000);
    const summary = await authed('/seller/finance/summary');
    state.available = summary.data?.balance?.available ?? summary.data?.available;
    if (!(state.available >= 50000)) throw new Error('available ' + JSON.stringify(summary.data?.balance ?? summary).slice(0, 200));
    await tab.shot('14-balance');
  });

  await step('11. verifikasi email lewat link di email', async () => {
    await tab.go(`${APP}/dashboard/apps/landing-builder`);
    await tab.waitFor(tab.bodyHas('Verifikasi email'), 'checklist');
    await tab.eval(`(() => { const li = [...document.querySelectorAll('li')].find(l => l.innerText.includes('Verifikasi email')); li.querySelector('button').click(); return true; })()`);
    const mail = await mailTo(seller.email, 'Verifikasi');
    const link = linkIn(mail, 'verif');
    await tab.go(link.replace(/^https?:\/\/127\.0\.0\.1:\d+/, (m) => (link.includes('/api/') ? SSR : APP)));
    await sleep(1500);
    const onb = await authed('/apps/landing-builder/onboarding');
    if (!onb.data?.checklist?.email_verified) throw new Error('not verified');
  });

  await step('12. isi data KTP & rekening', async () => {
    await tab.go(`${APP}/dashboard/payments?tab=rekening`);
    await tab.waitFor(tab.bodyHas('Kirim Verifikasi'), 'kyc form', 15000).catch(async (e) => { throw new Error(e.message + ' :: ' + (await tab.eval('document.body.innerText.slice(0, 1500)')).replace(/\s+/g, ' ')); });
    await tab.fill('label:Nama sesuai KTP', 'Rina Kreasi');
    await tab.fill('label:NIK (16 digit)', '3201010101010002');
    await tab.fill('label:Kode Bank', 'BCA');
    await tab.fill('label:No. Rekening', '1234567890');
    await tab.fill('label:Nama Pemilik Akun', 'RINA KREASI');
    await tab.shot('15-kyc');
    await tab.mustClick('Kirim Verifikasi');
    await tab.waitFor(`/ditinjau|menunggu|pending/i.test(document.body.innerText)`, 'kyc submitted', 15000);
  });

  await step('13. super admin menyetujui KYC', async () => {
    await loginAs(seed.admin_token, seed.admin_user);
    await tab.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await tab.go(`${APP}/admin/finance`);
    await tab.waitFor(tab.bodyHas('Rina Kreasi'), 'kyc queue', 20000);
    await tab.eval(`window.confirm = () => true; window.prompt = () => 'ok'; true`);
    await tab.mustClick('Setujui');
    let profile;
    for (let i = 0; i < 30; i++) {
      profile = await authed('/payout-profile');
      if (profile.data?.profile?.status === 'verified') break;
      await sleep(500);
    }
    if (profile.data?.profile?.status !== 'verified') throw new Error('not verified: ' + JSON.stringify(profile).slice(0, 200));
    await tab.shot('15b-admin-kyc-approved');
  });

  await step('14. penjual tarik dana Rp50.000', async () => {
    await loginAs(token, sellerUser);
    await tab.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await tab.go(`${APP}/dashboard/apps/landing-builder?tab=saldo`);
    await tab.waitFor(tab.bodyHas('Tarik dana'), 'balance');
    await tab.mustClick('Tarik dana');
    await tab.waitFor(tab.bodyHas('Ajukan penarikan'), 'withdraw sheet');
    await tab.fill('input[inputmode=numeric]', '50000');
    await tab.shot('16-withdraw');
    await tab.mustClick('Ajukan penarikan');
    await tab.waitFor(`/diproses|menunggu|diajukan/i.test(document.body.innerText)`, 'withdrawal requested', 15000);
    await tab.shot('17-withdraw-requested');
  });

  await step('15. super admin transfer & tandai berhasil (bukti)', async () => {
    await loginAs(seed.admin_token, seed.admin_user);
    await tab.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await tab.go(`${APP}/admin/keuangan-penjual`);
    await tab.waitFor(tab.bodyHas('Penarikan'), 'admin finance', 20000);
    await tab.eval(`[...document.querySelectorAll('button')].find(b => b.innerText.trim() === 'Penarikan').click(); true`);
    await tab.waitFor(tab.bodyHas('Rina Kreasi'), 'withdrawal row', 15000);
    await tab.eval(`window.confirm = () => true; true`);
    await tab.mustClick('Proses');
    await tab.waitFor(`[...document.querySelectorAll('button')].some(b => b.innerText.includes('Tandai berhasil') && !b.disabled)`, 'mark-paid button', 15000);
    await tab.mustClick('Tandai berhasil');
    await tab.waitFor(tab.bodyHas('Tandai transfer berhasil'), 'paid dialog');
    await tab.upload('form input[type=file]', proofPath);
    await tab.eval(`document.querySelector('form button[type=submit]').click(); true`);
    await sleep(2000);
    await tab.shot('18-admin-paid');
  });

  await step('16. penjual melihat penarikan selesai + email', async () => {
    const list = await authed('/seller/finance/withdrawals');
    const items = list.data?.items ?? list.data?.data ?? list.data ?? [];
    if (!JSON.stringify(items).includes('"paid"')) throw new Error('withdrawal not paid: ' + JSON.stringify(list).slice(0, 300));
    await mailTo(seller.email, 'penarikan');
    await loginAs(token, sellerUser);
    await tab.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await tab.go(`${APP}/dashboard/apps/landing-builder?tab=saldo`);
    await tab.waitFor(`${tab.bodyHas('Sudah ditarik')} && ${tab.bodyHas('Rp 50.000')}`, 'withdrawn total', 15000);
    await tab.eval(`[...document.querySelectorAll('button')].find(b => b.innerText.trim() === 'Penarikan').click(); true`);
    await tab.waitFor(tab.bodyHas('Berhasil'), 'paid shown', 15000);
    await tab.shot('19-seller-done');
  });

  const all = await mails();
  console.log('\nEmails captured:', all.length);
  for (const m of all) console.log(`  → ${m.to.join(',')}: ${m.subject}`);
  const errors = tab.logs.filter((l) => !/favicon|DevTools|ERR_BLOCKED_BY_CLIENT/.test(l));
  if (errors.length) console.log('\nConsole errors:\n  ' + errors.slice(0, 10).join('\n  '));
} finally {
  console.log('\n' + results.filter((r) => r[0] === 'OK').length + '/' + results.length + ' steps OK');
  writeFileSync(new URL('../../storage/app/e2e_full_result.json', import.meta.url), JSON.stringify({ results, state }, null, 2));
  chrome.kill();
}
