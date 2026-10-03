// Fase 3 blocks in the editor: Spasi, Tombol WhatsApp, Embed (link recognised + official iframe in
// the preview), TikTok in Video, and "Produk fisik" / "Produk digital" cards whose picker offers only
// that kind. Needs Laravel :8010 + Vite :3010 on hellom_pos_test. Expect "6/6 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/builder-seed.php'], { env });
const s = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const api = (path, body) => fetch(`${API}${path}`, { method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${s.token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then((r) => r.json());
await fetch(`${API}/apps/landing-builder/editor-preference`, { method: 'PUT', headers: { Authorization: `Bearer ${s.token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ preference: 'linktree', tour_done: true }) });
await api('/apps/landing-builder/products', { type: 'physical', name: 'Kaos Hellom', price: 90000, shipping_mode: 'free' });
await api('/apps/landing-builder/products', { type: 'drive', name: 'E-book Resep', price: 49000, delivery_url: 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUv/view' });

const browser = await openChrome(9357, 'cdp-builder-blocks');
const { send, ev, waitFor, inPreview, errors } = browser;
const clickText = (text) => ev(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)} || x.getAttribute('aria-label') === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);
const setField = (selector, value) => ev(`(() => {
  const el = document.querySelector(${JSON.stringify(selector)});
  if (!el) return false;
  const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(value)});
  el.dispatchEvent(new Event('input', { bubbles: true }));
  return true;
})()`);
const add = async (key) => {
  await clickText('Kembali ke daftar');
  await clickText('Tambah link');
  await waitFor(`!!document.querySelector('[data-block-type="${key}"]')`);
  await ev(`document.querySelector('[data-block-type="${key}"]').click(); true`);
  await waitFor(`!!document.querySelector('[aria-label="Kembali ke daftar"]')`);
};

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(s.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);
  await clickText('Tambah link');
  const cards = await waitFor(`['spacer', 'whatsapp', 'embed', 'product', 'product_physical'].every((k) => document.querySelector('[data-block-type="' + k + '"]'))`);
  await browser.shot('blocks-gallery');
  check('gallery offers Spasi, Tombol WhatsApp, Embed, Produk digital & fisik', cards);
  await clickText('Kembali ke daftar');

  // WhatsApp with its own number → wa.me link in the preview.
  await add('whatsapp');
  await setField('aside input[type="tel"]', '0812 5555 1234');
  const wa = await browser.waitPreview(`!!document.querySelector('a[href^="https://wa.me/6281255551234"]')`);
  check('WhatsApp block → wa.me link with the number (0 → 62)', wa);

  // Embed: Spotify link recognised in the panel and shown as the official iframe.
  await add('embed');
  await setField('aside input[type="url"]', 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M?si=1');
  const hint = await waitFor(`document.body.textContent.includes('Link Spotify dikenali')`);
  const spotify = await browser.waitPreview(`!!document.querySelector('iframe[src="https://open.spotify.com/embed/playlist/37i9dQZF1DXcBWIGoYBM5M"]')`);
  await setField('aside input[type="url"]', 'https://vt.tiktok.com/ZS123/');
  const refused = await waitFor(`document.body.textContent.includes('Link belum dikenali')`);
  await browser.shot('blocks-embed');
  check('Embed: Spotify recognised + iframe in preview; short link explained', hint && spotify && refused, JSON.stringify({ hint, spotify, refused }));

  // Video with a TikTok link → vertical TikTok embed.
  await add('video');
  await setField('aside input[type="url"]', 'https://www.tiktok.com/@toko.kue/video/7312345678901234567');
  const tiktok = await browser.waitPreview(`!!document.querySelector('.embed.vertical iframe[src="https://www.tiktok.com/embed/v2/7312345678901234567"]')`);
  check('Video block plays TikTok links', tiktok);

  // Spasi with a height.
  await add('spacer');
  await ev(`(() => { const r = document.querySelector('#spacer-height'); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(r, '96'); r.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
  const spacer = await browser.waitPreview(`!!document.querySelector('div[style="height:96px"]')`);

  // "Produk fisik" offers only physical products.
  await add('product_physical');
  const options = await waitFor(`[...document.querySelectorAll('aside select option')].some((o) => o.textContent.includes('Kaos Hellom'))`);
  const onlyPhysical = await ev(`![...document.querySelectorAll('aside select option')].some((o) => o.textContent.includes('E-book Resep'))`);
  check('Spasi height + "Produk fisik" picker lists only physical products', spacer && options && onlyPhysical, JSON.stringify({ spacer, options, onlyPhysical }));
  const preview = await inPreview(`document.body.textContent.length`);
  check('no script errors', errors.length === 0 && preview.value > 0, JSON.stringify(errors));
} finally {
  browser.close();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env });
  exitCode = finish();
}
process.exit(exitCode);
