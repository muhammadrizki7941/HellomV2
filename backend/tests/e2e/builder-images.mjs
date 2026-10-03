// Hellom Page builder images (regression for "gambar tidak ter-load"): a phone-sized photo
// uploads, the editor preview and the property panel show it, and the published page serves it.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test (see README) and tests/e2e/builder-seed.php.
// Expect "6/6 checks OK".
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const API_ORIGIN = 'http://127.0.0.1:8010';
const API = `${API_ORIGIN}/api/v1/hellom`;
const APP = 'http://127.0.0.1:3010';
const seed = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const SHOTS = new URL('../../storage/app/e2e_shots/', import.meta.url);
mkdirSync(SHOTS, { recursive: true });
const auth = { Authorization: `Bearer ${seed.token}`, Accept: 'application/json' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok }); console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : ' ' + detail}`); };

// A real PNG of ~5 MB (random pixels do not compress), like a photo from a phone.
function photoPng(width = 1500, height = 1100) {
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => {
    const out = Buffer.alloc(12 + data.length);
    out.writeUInt32BE(data.length, 0); out.write(type, 4, 'ascii'); data.copy(out, 8);
    out.writeUInt32BE(crc(out.subarray(4, 8 + data.length)), 8 + data.length);
    return out;
  };
  const header = Buffer.alloc(13); header.writeUInt32BE(width, 0); header.writeUInt32BE(height, 4); header[8] = 8; header[9] = 2;
  const raw = Buffer.alloc((width * 3 + 1) * height);
  for (let i = 0; i < raw.length; i++) raw[i] = (i % (width * 3 + 1)) === 0 ? 0 : (Math.random() * 256) | 0;
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', header), chunk('IDAT', deflateSync(raw, { level: 0 })), chunk('IEND', Buffer.alloc(0))]);
}

const png = photoPng();
const form = new FormData();
form.append('file', new Blob([png], { type: 'image/png' }), 'foto-hp.png');
const up = await fetch(`${API}/apps/landing-builder/assets/upload`, { method: 'POST', headers: auth, body: form });
const upBody = await up.json();
const url = upBody.data?.url;
check(`upload ${(png.length / 1048576).toFixed(1)} MB photo → WebP`, up.status === 201 && /^\/media\/.+\.webp$/.test(url ?? ''), JSON.stringify(upBody).slice(0, 200));

// Page with a profile photo + image block, then publish.
const site = (await (await fetch(`${API}/apps/landing-builder/site`, { headers: auth })).json()).data;
let page = site.pages?.[0];
if (!page) page = (await (await fetch(`${API}/apps/landing-builder/site/pages`, { method: 'POST', headers: { ...auth, 'Content-Type': 'application/json' }, body: JSON.stringify({ title: 'Beranda' }) })).json()).data;
const draft = (await (await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { headers: auth })).json()).data;
const document = { ...draft.document, blocks: [
  { id: 'p1', type: 'profile', hidden: false, content: { name: 'Toko E2E Builder', bio: 'Tes gambar', avatarUrl: url }, styles: {} },
  { id: 'i1', type: 'image', hidden: false, content: { imageUrl: url, caption: 'Foto produk', alt: 'Foto produk' }, styles: {} },
] };
const saved = await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { method: 'PUT', headers: { ...auth, 'Content-Type': 'application/json' }, body: JSON.stringify({ document, revision: draft.revision }) });
const pub = await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/publish`, { method: 'POST', headers: auth });
check('draft keeps the URL and the page publishes', saved.ok && pub.ok, `${saved.status} ${pub.status}`);

// Server-rendered page: the <img> URL answers with an image.
const html = await (await fetch(`${API_ORIGIN}/${seed.username}`)).text();
const ssrSrc = html.match(/<img[^>]+src="(\/media\/[^"]+)"/)?.[1];
const ssrImg = ssrSrc ? await fetch(API_ORIGIN + ssrSrc) : null;
check('published page image loads', ssrImg?.status === 200 && ssrImg.headers.get('content-type') === 'image/webp', `${ssrSrc} ${ssrImg?.status}`);

// Same path through the dev server (Vite proxies /media to Laravel).
const viaVite = await fetch(APP + url);
check('dev server serves /media as an image', viaVite.headers.get('content-type') === 'image/webp', `${viaVite.status} ${viaVite.headers.get('content-type')}`);

const PORT = 9352;
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${process.env.TEMP}\\cdp-builder-images`, '--no-first-run', 'about:blank'], { stdio: 'ignore' });
try {
  let info; for (let i = 0; i < 40 && !info; i++) { try { info = await (await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' })).json(); } catch { await sleep(250); } }
  const ws = new WebSocket(info.webSocketDebuggerUrl); await new Promise((r) => ws.addEventListener('open', r));
  let id = 0; const pend = new Map();
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); } });
  const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (x) => (await send('Runtime.evaluate', { expression: x, returnByValue: true, awaitPromise: true })).result?.result?.value;
  const waitFor = async (x, ms = 20000) => { const t = Date.now(); while (Date.now() - t < ms) { if (await ev(x)) return true; await sleep(250); } return false; };
  await send('Page.enable');
  for (const [width, mobile] of [[1366, false], [360, true]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 1, mobile });
    await send('Page.navigate', { url: APP + '/' }); await sleep(1500);
    await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(seed.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(seed.user))}); true`);
    await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder' });
    await waitFor(`[...document.querySelectorAll('button')].some(b => /^\\s*Editor\\s*$/i.test(b.innerText))`);
    await ev(`[...document.querySelectorAll('button')].find(b => /^\\s*Editor\\s*$/i.test(b.innerText))?.click(); true`);
    const shown = await waitFor(`(() => { const imgs = [...document.images].filter(i => (i.getAttribute('src') || '').includes('/media/')); return imgs.length > 0 && imgs.every(i => i.complete && i.naturalWidth > 0); })()`);
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(new URL(`builder-images-${width}.png`, SHOTS), Buffer.from(shot.result.data, 'base64'));
    check(`editor preview shows the photo at ${width}px`, shown, await ev(`JSON.stringify([...document.images].map(i => [i.getAttribute('src'), i.naturalWidth]))`));
  }
} finally {
  chrome.kill();
  const passed = results.filter((r) => r.ok).length;
  console.log(`\n${passed}/${results.length} checks OK`);
  process.exit(passed === results.length ? 0 : 1);
}
