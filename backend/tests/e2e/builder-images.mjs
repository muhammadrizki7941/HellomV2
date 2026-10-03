// Hellom Page builder images (regression for "gambar tidak ter-load"): a phone-sized photo
// uploads, the editor preview and the property panel show it, and the published page serves it.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test (see README) and tests/e2e/builder-seed.php.
// Expect "6/6 checks OK".
import { readFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { checker, openChrome } from './cdp.mjs';

const API_ORIGIN = 'http://127.0.0.1:8010';
const API = `${API_ORIGIN}/api/v1/hellom`;
const APP = 'http://127.0.0.1:3010';
const seed = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const auth = { Authorization: `Bearer ${seed.token}`, Accept: 'application/json' };
const { check, finish } = checker();

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


const browser = await openChrome(9352, 'cdp-builder-images');
const { send, ev, waitFor, inPreview } = browser;
let exitCode = 1;
try {
  await fetch(`${API}/apps/landing-builder/editor-preference`, { method: 'PUT', headers: { ...auth, 'Content-Type': 'application/json' }, body: JSON.stringify({ preference: 'lynk', tour_done: true }) });
  for (const [width, mobile] of [[1366, false], [360, true]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 1, mobile });
    await send('Page.navigate', { url: APP + '/' });
    await waitFor(`document.readyState === 'complete'`);
    await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(seed.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(seed.user))}); true`);
    await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
    await waitFor(`!!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);
    // The live phone preview (sandboxed iframe): every uploaded image has loaded.
    const loaded = await browser.waitPreview(`(() => { const imgs = [...document.images].filter((i) => (i.getAttribute('src') || '').includes('/media/')); return imgs.length >= 2 && imgs.every((i) => i.complete && i.naturalWidth > 0); })()`, 20000);
    const detail = await inPreview(`JSON.stringify([...document.images].map((i) => [i.getAttribute('src'), i.naturalWidth]))`);
    // The settings panel shows the photo too (image block selected).
    if (mobile) await ev(`document.querySelector('nav [data-tour="list"]').click(); true`);
    await waitFor(`[...document.querySelectorAll('[aria-label^="Urutan"] > li button')].some((b) => b.textContent.includes('Gambar'))`);
    await ev(`[...document.querySelectorAll('[aria-label^="Urutan"] > li button')].find((b) => b.textContent.includes('Gambar')).click(); true`);
    const panel = await waitFor(`[...document.querySelectorAll('img[alt="Preview"]')].some((i) => i.complete && i.naturalWidth > 0)`);
    await browser.shot(`builder-images-${width}`);
    check(`editor preview and settings show the photo at ${width}px`, loaded && panel, JSON.stringify({ loaded, panel, detail: detail.value }));
  }
} finally {
  browser.close();
  exitCode = finish();
}
process.exit(exitCode);
