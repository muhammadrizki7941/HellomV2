// Fase 7: header banner (YouTube, ratio, focus), video block (Shorts → vertical, bad link explained),
// animations (entrance + "Matikan semua animasi"), template gallery (categories, live preview, style
// only / everything, 360 px), super admin hides & orders templates. Needs Laravel :8010 + Vite :3010
// on hellom_pos_test, mocks.mjs, and YOUTUBE_OEMBED_URL=http://127.0.0.1:8020/youtube/oembed on Laravel. Expect "8/8 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const SHOP = 'http://127.0.0.1:8010';
const API = `${SHOP}/api/v1/hellom`;
const env = { ...process.env, DB_DATABASE: 'hellom_pos_test' };
const { check, finish } = checker();
execFileSync('php', ['tests/e2e/builder-seed.php'], { env });
const s = JSON.parse(readFileSync(new URL('../../storage/app/builder_seed.json', import.meta.url), 'utf8'));
const headers = { Authorization: `Bearer ${s.token}`, Accept: 'application/json', 'Content-Type': 'application/json' };
await fetch(`${API}/apps/landing-builder/editor-preference`, { method: 'PUT', headers, body: JSON.stringify({ preference: 'lynk', tour_done: true }) });
const page = (await (await fetch(`${API}/apps/landing-builder/site/pages`, { method: 'POST', headers, body: JSON.stringify({ title: 'Beranda' }) })).json()).data;
const draft = (await (await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { headers })).json()).data;
await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { method: 'PUT', headers, body: JSON.stringify({ revision: draft.revision, document: { ...draft.document, blocks: [
  { id: 'p1', type: 'profile', hidden: false, content: { name: 'Toko Kue Bu Ani', bio: 'Kue rumahan' }, styles: {} },
  { id: 'b1', type: 'button', hidden: false, content: { text: 'Pesan sekarang', actionType: 'link', linkUrl: 'https://example.com/pesan' }, styles: {} },
] } }) });

const browser = await openChrome(9361, 'cdp-builder-extras');
const { send, ev, waitFor, errors } = browser;
const clickText = (text, scope = 'document') => ev(`(() => { const b = [...${scope}.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)} || x.getAttribute('aria-label') === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);
const type = (selector, value) => ev(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, ${JSON.stringify(value)}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
const openRow = (text) => ev(`(() => { const li = [...document.querySelectorAll('[aria-label^="Urutan"] > li')].find((x) => x.textContent.includes(${JSON.stringify(text)})); li?.querySelectorAll('button').forEach((b) => { if (b.textContent.includes(${JSON.stringify(text)})) b.click(); }); return !!li; })()`);
const login = async (token, user) => {
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(user))}); true`);
};

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await login(s.token, s.user);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);

  // 7.1 Banner from a YouTube link: thumbnail in the preview, ratio 16:9.
  await openRow('Toko Kue Bu Ani');
  await waitFor(`!!document.querySelector('[data-banner-field]')`);
  await clickText('Video YouTube', `document.querySelector('[data-banner-field]')`);
  await type('[aria-label="Link video YouTube untuk banner"]', 'https://youtu.be/dQw4w9WgXcQ?t=15');
  await clickText('16:9', `document.querySelector('[data-banner-field]')`);
  const banner = await browser.waitPreview(`(() => { const c = document.querySelector('.cover'); return !!c && c.getAttribute('style').includes('16/9') && !!c.querySelector('img[src*="i.ytimg.com/vi/dQw4w9WgXcQ"]') && !!c.querySelector('[data-cover-yt="dQw4w9WgXcQ"]') && !c.querySelector('iframe'); })()`, 15000);
  await waitFor(`!!document.querySelector('[aria-label="Titik fokus banner"]')`, 10000);
  const focus = await ev(`(() => { const f = document.querySelector('[aria-label="Titik fokus banner"]'); if (!f) return false; const r = f.getBoundingClientRect(); const o = { bubbles: true, clientX: r.left + r.width * 0.25, clientY: r.top + r.height * 0.75, pointerId: 1 };
    f.dispatchEvent(new PointerEvent('pointerdown', o)); f.dispatchEvent(new PointerEvent('pointerup', o)); return true; })()`);
  await browser.shot('extras-focus');
  const focused = focus && await browser.waitPreview(`document.querySelector('.cover img')?.getAttribute('style').includes('object-position:25% 75%')`);
  await browser.shot('extras-banner');
  check('banner: YouTube link → thumbnail in the editor (no player), 16:9, tap sets the focus point', banner && focused, JSON.stringify({ banner, focused }));

  // 7.3 Video block: Shorts recognised and vertical; a bad link is explained.
  await clickText('Kembali ke daftar');
  await ev(`document.querySelector('[data-tour="add"]').click(); true`);
  await waitFor(`!!document.querySelector('[data-block-type="video"]')`);
  await ev(`document.querySelector('[data-block-type="video"]').click(); true`);
  await waitFor(`!!document.querySelector('#video-url')`);
  await type('#video-url', 'https://vimeo.com/12345');
  const bad = await waitFor(`document.querySelector('[data-video-error]')?.textContent.includes('Link video belum dikenali')`, 10000);
  await browser.shot('extras-video-bad');
  await type('#video-url', 'https://youtube.com/shorts/abcdefghijk?feature=share');
  const card = await waitFor(`document.querySelector('[data-video-preview="youtube"]')?.textContent.includes('tampil vertikal')`, 15000);
  const shorts = await browser.waitPreview(`!!document.querySelector('.yt.vertical[data-yt="abcdefghijk"]')`);
  check('video: Shorts link → preview card + vertical 9:16 in the page; Vimeo explained in Indonesian', bad && card && shorts, JSON.stringify({ bad, card, shorts }));

  // 7.2 Animations: entrance "Naik" + featured style, publish → public page has them.
  await clickText('Kembali ke daftar');
  await openRow('Pesan sekarang');
  await waitFor(`document.body.textContent.includes('Tampilan tombol ini')`);
  await ev(`(() => { const l = [...document.querySelectorAll('label')].find((x) => x.textContent.includes('Tombol unggulan')); l.querySelector('input').click(); return true; })()`);
  await waitFor(`!!document.querySelector('[aria-label="Animasi tombol unggulan"]')`);
  await clickText('Goyang halus', `document.querySelector('[aria-label="Animasi tombol unggulan"]')`);
  await ev(`document.querySelector('[data-tour="design"]').click(); true`);
  await waitFor(`!!document.querySelector('[data-section="animasi"]')`);
  await ev(`document.querySelector('[data-section="animasi"] > button').click(); true`);
  await clickText('Naik', `document.querySelector('[data-section="animasi"]')`);
  await waitFor(`document.querySelector('header [aria-live]')?.textContent.includes('Tersimpan')`, 10000);
  await clickText('Terbitkan');
  await waitFor(`document.body.textContent.includes('Halaman terbit!')`, 15000);
  let html = await (await fetch(`${SHOP}/${s.username}`)).text();
  const animated = html.includes('class="ent ent-slide"') && html.includes('featured fx-shake') && html.includes('data-cover-yt="dQw4w9WgXcQ"');
  await ev(`(() => { const l = [...document.querySelectorAll('[data-section="animasi"] label')].find((x) => x.textContent.includes('Matikan semua animasi')); l.querySelector('input').click(); return true; })()`);
  await waitFor(`document.querySelector('header [aria-live]')?.textContent.includes('Tersimpan')`, 10000);
  await clickText('Terbitkan');
  // The second publish can take a moment: poll the live page instead of a fixed wait.
  let off = false;
  for (let i = 0; i < 20 && !off; i++) {
    await sleep(500);
    html = await (await fetch(`${SHOP}/${s.username}`)).text();
    off = /<body class="[^"]*no-anim/.test(html) && !html.includes('class="ent ');
  }
  check('animations: entrance + featured shake published; "Matikan semua animasi" turns them off', animated && off, JSON.stringify({ animated, off }));

  // 7.4 Template gallery.
  await clickText('Kembali ke daftar');
  await ev(`document.querySelector('[data-tour="templates"]').click(); true`);
  const gallery = await waitFor(`document.querySelectorAll('[data-template]').length >= 12 && document.body.textContent.includes('Populer') && document.body.textContent.includes('Baru')`, 15000);
  await clickText('Kuliner & UMKM', `document.querySelector('[aria-label="Kategori template"]')`);
  const filtered = await waitFor(`[...document.querySelectorAll('[data-template]')].every((c) => ['dapur-hangat', 'alam'].includes(c.getAttribute('data-template')))`);
  await ev(`document.querySelector('[data-template="dapur-hangat"]').click(); true`);
  const live = await browser.waitPreview(`document.body.textContent.includes('Dapur Bu Ratna')`, 15000, 'Pratinjau template');
  await browser.shot('extras-gallery');
  check('gallery: 12+ templates with Populer/Baru, category filter, live phone preview', gallery && filtered && live, JSON.stringify({ gallery, filtered, live }));

  await clickText('Pakai gaya saja');
  const styleOnly = await browser.waitPreview(`document.body.classList.contains('bg-pattern') && document.body.textContent.includes('Toko Kue Bu Ani') && document.body.textContent.includes('Pesan sekarang')`, 15000);
  await ev(`window.confirm = () => true; document.querySelector('[data-tour="templates"]').click(); true`);
  await waitFor(`!!document.querySelector('[data-template="dapur-hangat"]')`, 15000);
  await ev(`document.querySelector('[data-template="dapur-hangat"]').click(); true`);
  await sleep(500);
  await clickText('Pakai semuanya');
  const all = await browser.waitPreview(`document.body.textContent.includes('Lihat menu lengkap') && document.querySelector('h1')?.textContent.includes('Toko Kue Bu Ani') && !document.body.textContent.includes('Dapur Bu Ratna')`, 15000);
  check('apply: "Pakai gaya saja" keeps content; "Pakai semuanya" swaps blocks but keeps the shop name', styleOnly && all, JSON.stringify({ styleOnly, all }));

  // Phone: gallery list → tap → preview + buttons, no sideways scroll.
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('[aria-label="Menu lainnya"]')`, 20000);
  await clickText('Menu lainnya');
  await clickText('Template');
  await waitFor(`document.querySelectorAll('[data-template]').length >= 12`, 15000);
  await ev(`document.querySelector('[data-template="neon-glass"]').click(); true`);
  const phone = await waitFor(`!!document.querySelector('[aria-label="Kembali ke daftar template"]') && [...document.querySelectorAll('button')].some((b) => b.textContent.trim() === 'Pakai semuanya' && b.offsetParent) && document.documentElement.scrollWidth <= innerWidth + 1`, 15000);
  await sleep(1500);
  await browser.shot('extras-gallery-phone');
  check('phone 360 px: tap a template → preview with both buttons, no sideways scroll', phone);

  // Super admin hides a template and moves another to the top; sellers follow.
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await login(s.admin_token, s.admin_user);
  await send('Page.navigate', { url: APP + '/admin/settings' });
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.includes('Template Halaman'))`, 20000);
  await clickText('Template Halaman');
  await waitFor(`!!document.querySelector('[data-admin-template="neo-brutal"]')`, 15000);
  await ev(`[...document.querySelector('[data-admin-template="neo-brutal"]').querySelectorAll('button')].find((b) => b.textContent.includes('Sembunyikan')).click(); true`);
  await waitFor(`document.querySelector('[data-admin-template="neo-brutal"]')?.textContent.includes('Disembunyikan') && document.body.textContent.includes('disembunyikan')`, 10000);
  await ev(`document.querySelector('[aria-label="Naikkan Kelas Online"]').click(); true`);
  await sleep(1200);
  await browser.shot('extras-admin');
  const seen = (await (await fetch(`${API}/apps/landing-builder/page-templates`, { headers })).json()).data.templates.map((t) => t.id);
  const order = (await (await fetch(`${API}/admin/landing-templates`, { headers: { ...headers, Authorization: `Bearer ${s.admin_token}` } })).json()).data.templates.map((t) => t.id);
  check('super admin: hide + reorder are saved and sellers no longer see the hidden template', !seen.includes('neo-brutal') && order.indexOf('kelas-online') === 8 && seen.length === 11, JSON.stringify({ seen, order }));
  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env });
  exitCode = finish();
}
process.exit(exitCode);
