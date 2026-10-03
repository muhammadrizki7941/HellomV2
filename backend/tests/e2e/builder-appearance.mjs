// Fase 5 Tampilan: theme preset (background, self-hosted font, button look) in the preview, background
// types, theme button settings, one button's own look + icon + featured, published page, phone sheet.
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test. Expect "8/8 checks OK".
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { checker, openChrome, sleep } from './cdp.mjs';

const APP = 'http://127.0.0.1:3010';
const API = 'http://127.0.0.1:8010/api/v1/hellom';
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
  { id: 'b2', type: 'button', hidden: false, content: { text: 'Katalog', actionType: 'link', linkUrl: 'https://example.com/katalog' }, styles: {} },
] } }) });

const browser = await openChrome(9359, 'cdp-builder-appearance');
const { send, ev, waitFor, errors } = browser;
const clickText = (text, scope = 'document') => ev(`(() => { const b = [...${scope}.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)} || x.getAttribute('aria-label') === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);
const section = (id) => ev(`(() => { const b = document.querySelector('[data-section="${id}"] > button'); if (b?.getAttribute('aria-expanded') !== 'true') b?.click(); return !!b; })()`);
const inSection = (id, text) => clickText(text, `document.querySelector('[data-section="${id}"]')`);

let exitCode = 1;
try {
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(s.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);
  await ev(`document.querySelector('[data-tour="design"]').click(); true`);
  await waitFor(`!!document.querySelector('[data-section="tema"]')`);

  // Preset "Neo-brutal": pattern background, Archivo heading (self-hosted, loaded), hard shadow, square.
  await section('tema');
  await ev(`document.querySelector('[data-theme="brutal"]').click(); true`);
  const brutal = await browser.waitPreview(`(() => {
    const b = document.querySelector('a.btn');
    if (!b || !document.body.classList.contains('bg-pattern')) return false;
    const cs = getComputedStyle(b);
    return getComputedStyle(document.querySelector('h1')).fontFamily.includes('Archivo')
      && cs.boxShadow.includes('px') && cs.borderTopLeftRadius === '4px';
  })()`, 15000);
  const fontLoaded = await browser.waitPreview(`[...document.fonts].some((f) => f.family.includes('Archivo') && f.status === 'loaded')`, 15000);
  await browser.shot('appearance-brutal');
  check('preset Neo-brutal: pattern, square buttons with hard shadow, Archivo font', brutal, JSON.stringify({ brutal }));
  check('self-hosted font file actually loads in the sandboxed preview', fontLoaded);

  // Background types.
  await section('background');
  await inSection('background', 'Gradien');
  const gradient = await browser.waitPreview(`document.body.classList.contains('bg-gradient') && document.documentElement.getAttribute('style').includes('linear-gradient')`);
  await inSection('background', 'Animasi');
  const animated = await browser.waitPreview(`document.body.classList.contains('bg-animated') && !!document.querySelector('.bg-layer')`);
  check('background: gradient and animated layers render', gradient && animated, JSON.stringify({ gradient, animated }));

  // Theme buttons: pill + outline + grow.
  await section('tombol');
  await inSection('tombol', 'Pill');
  await inSection('tombol', 'Garis');
  await inSection('tombol', 'Besar');
  const themeButtons = await browser.waitPreview(`(() => {
    const cs = getComputedStyle(document.querySelector('a.btn'));
    return parseFloat(cs.borderTopLeftRadius) > 100 && cs.backgroundColor === 'rgba(0, 0, 0, 0)' && document.body.classList.contains('hv-grow');
  })()`);
  check('theme buttons: pill, outline, grow on hover', themeButtons);

  // One button: own fill (solid), icon, featured — the other keeps the theme.
  await clickText('Kembali ke daftar');
  await waitFor(`[...document.querySelectorAll('[aria-label^="Urutan"] > li')].some((li) => li.textContent.includes('Pesan sekarang'))`);
  await ev(`[...document.querySelectorAll('[aria-label^="Urutan"] > li')].find((li) => li.textContent.includes('Pesan sekarang')).querySelectorAll('button').forEach((b) => { if (b.textContent.includes('Pesan sekarang')) b.click(); }); true`);
  await waitFor(`document.body.textContent.includes('Tampilan tombol ini')`);
  await clickText('Solid', `[...document.querySelectorAll('[role="radiogroup"][aria-label="Isi"]')].pop()`);
  await ev(`document.querySelector('[aria-label="Belanja"]').click(); true`);
  await ev(`(() => { const l = [...document.querySelectorAll('label')].find((x) => x.textContent.includes('Tombol unggulan')); l.querySelector('input').click(); return true; })()`);
  const own = await browser.waitPreview(`(() => {
    const [a, b] = document.querySelectorAll('a.btn');
    return a.classList.contains('featured') && !!a.querySelector('.btn-ico svg')
      && getComputedStyle(a).backgroundColor !== 'rgba(0, 0, 0, 0)' && getComputedStyle(b).backgroundColor === 'rgba(0, 0, 0, 0)';
  })()`);
  await browser.shot('appearance-button');
  check('one button: own solid fill, icon, featured; the other follows the theme', own);

  // Publish → live page has the same look and the font preload.
  await waitFor(`document.querySelector('header [aria-live]')?.textContent.includes('Tersimpan')`, 10000);
  await clickText('Terbitkan');
  await waitFor(`document.body.textContent.includes('Halaman terbit!')`, 15000);
  const html = await (await fetch(`http://127.0.0.1:8010/${s.username}`)).text();
  const font = await fetch('http://127.0.0.1:8010/fonts/landing/archivo.woff2').catch(() => null);
  check('published page: animated background, featured button, font preload',
    html.includes('bg-animated') && html.includes('btn featured has-ico') && /rel="preload"[^>]+\/fonts\/landing\//.test(html),
    `font route ${font?.status} ${font?.headers.get('access-control-allow-origin')}`);

  // Phone: Tampilan in the bottom bar → sheet, no sideways scroll.
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('nav[aria-label="Alat editor"] [data-tour="design"]')`, 20000);
  await ev(`document.querySelector('nav[aria-label="Alat editor"] [data-tour="design"]').click(); true`);
  await sleep(500);
  await ev(`document.querySelector('[role="dialog"] [data-section="tema"] > button')?.click(); true`);
  const sheet = await waitFor(`!!document.querySelector('[role="dialog"] [data-theme="neon"]') && document.documentElement.scrollWidth <= innerWidth + 1`);
  await browser.shot('appearance-phone');
  check('phone: Tampilan opens as a sheet with the theme gallery (no sideways scroll)', sheet);
  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env });
  exitCode = finish();
}
process.exit(exitCode);
