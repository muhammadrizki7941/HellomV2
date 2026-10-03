// Fase 6: clicks per link + source in Statistik (desktop + 360 px), clicks from the real public page
// carry the link id, and the page settings show the generated share card (og:image).
// Needs Laravel :8010 + Vite :3010 on hellom_pos_test. Expect "6/6 checks OK".
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
await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/document`, { method: 'PUT', headers, body: JSON.stringify({ revision: draft.revision, document: { ...draft.document,
  social: { items: [{ platform: 'instagram', value: '@toko.kue' }] },
  blocks: [
    { id: 'p1', type: 'profile', hidden: false, content: { name: 'Toko Kue Bu Ani', bio: 'Kue rumahan tanpa pengawet' }, styles: {} },
    { id: 'b1', type: 'button', hidden: false, content: { text: 'Pesan sekarang', actionType: 'link', linkUrl: 'https://example.com/pesan' }, styles: {} },
  ] } }) });
const published = await fetch(`${API}/apps/landing-builder/site/pages/${page.id}/publish`, { method: 'POST', headers, body: '{}' });
const event = (body) => fetch(`${API}/public/landing-events`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ username: s.username, ...body }) });
for (const source of ['instagram', 'tiktok']) await event({ metric: 'visit', source });
for (const source of ['https://l.instagram.com/', 'instagram', '']) await event({ metric: 'click', item: 'b1', dimension: 'Pesan sekarang', source });
await event({ metric: 'click', item: 'social:instagram', dimension: 'Instagram', source: 'tiktok' });

const browser = await openChrome(9360, 'cdp-builder-stats');
const { send, ev, waitFor, errors } = browser;
const clickText = (text) => ev(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)} || x.getAttribute('aria-label') === ${JSON.stringify(text)}); b?.click(); return !!b; })()`);

let exitCode = 1;
try {
  check('page published', published.ok, String(published.status));

  // A real click on the public page sends the link id.
  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: `${SHOP}/${s.username}` });
  await waitFor(`!!document.querySelector('a[data-item="b1"]')`, 15000);
  await ev(`(() => { const a = document.querySelector('a[data-item="b1"]'); a.removeAttribute('target'); a.addEventListener('click', (e) => e.preventDefault()); a.click(); return true; })()`);
  await sleep(800);
  const sent = await fetch(`${API}/apps/landing-builder/stats/traffic?days=7`, { headers }).then((r) => r.json());
  const b1 = sent.data.links.find((l) => l.item === 'b1');
  check('public page: tapping a button counts for that link', b1?.clicks === 4, JSON.stringify(b1));

  // Statistik at 1366 px.
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/' });
  await waitFor(`document.readyState === 'complete'`);
  await ev(`localStorage.setItem('hellom_token', ${JSON.stringify(s.token)}); localStorage.setItem('hellom_user', ${JSON.stringify(JSON.stringify(s.user))}); true`);
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=statistik' });
  const desktop = await waitFor(`(() => {
    const li = document.querySelector('[data-link="b1"]');
    const soc = document.querySelector('[data-link="social:instagram"]');
    return !!li && li.textContent.includes('Pesan sekarang') && li.textContent.includes('4') && /instagram\\s*\\d/i.test(li.textContent) && li.textContent.includes('langsung')
      && !!soc && soc.textContent.includes('Sosial media') && soc.textContent.includes('tiktok');
  })()`, 20000);
  await browser.shot('stats-desktop');
  check('Statistik: "Klik per link" with clicks, % and sources', desktop);

  await send('Emulation.setDeviceMetricsOverride', { width: 360, height: 780, deviceScaleFactor: 2, mobile: true });
  await sleep(600);
  const phone = await waitFor(`!!document.querySelector('[data-link="b1"]') && document.documentElement.scrollWidth <= innerWidth + 1`);
  await ev(`document.querySelector('#link-clicks').scrollIntoView(); true`);
  await browser.shot('stats-phone');
  check('Statistik on a 360 px phone: no sideways scroll', phone);

  // Page settings: generated share card is shown (1200×630).
  await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: APP + '/dashboard/apps/landing-builder?tab=editor' });
  await waitFor(`!!document.querySelector('iframe[title="Pratinjau halaman"]')`, 20000);
  await clickText('Beranda');
  await waitFor(`[...document.querySelectorAll('button')].some((b) => b.textContent.trim() === 'Atur')`);
  await clickText('Atur');
  const card = await waitFor(`(() => { const img = document.querySelector('[data-share-image] img'); return !!img && img.complete && img.naturalWidth === 1200 && img.naturalHeight === 630; })()`, 15000);
  await browser.shot('stats-share-card');
  check('page settings: automatic share card (1200×630) with "Pakai gambar sendiri"', card && await ev(`document.querySelector('[data-share-image]').textContent.includes('Pakai gambar sendiri')`).then((r) => r === true || r?.value === true));
  check('no script errors', errors.length === 0, JSON.stringify(errors));
} finally {
  browser.close();
  execFileSync('php', ['tests/e2e/builder-seed.php', 'cleanup'], { env });
  exitCode = finish();
}
process.exit(exitCode);
